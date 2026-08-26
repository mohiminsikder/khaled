<?php
namespace Counter\Purchasing;

use Counter\Install;
use Counter\Db;
use Counter\Stock\Entity;

defined( 'ABSPATH' ) || exit;

/**
 * P2.4 — goods arrive at a known landed cost. This class places the order and
 * fixes each line's landed_cost_share ONCE, at ordering time, from the
 * shipping/other charges known then — Receiving (the other half of this
 * task) never recomputes the distribution; it only ever applies a
 * proportional SLICE of a line's already-fixed share to whatever fraction of
 * that line is being received in one particular delivery. That is what makes
 * partial receiving (Direction: "the normal case, not the exception") work
 * without the numbers drifting apart across several partial deliveries.
 */
class Orders {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'purchase_orders' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function lines( int $po_id ): array {
		global $wpdb;
		$table = Install::table( 'purchase_lines' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE po_id = %d ORDER BY id", $po_id ), ARRAY_A );
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function create( array $data ) {
		$lines = (array) ( $data['lines'] ?? [] );
		if ( empty( $lines ) ) {
			return new \WP_Error( 'cntr_po_no_lines', __( 'A purchase order needs at least one line.', 'counter' ), [ 'status' => 400 ] );
		}

		// A PO line for an unmanaged product is refused outright — nothing
		// gets created, not even the lines that WOULD have been fine. Checked
		// before anything is written, same "validate everything first" shape
		// Receiving::receive() uses for over-receiving.
		foreach ( $lines as $line ) {
			$product_id   = (int) ( $line['product_id'] ?? 0 );
			$variation_id = (int) ( $line['variation_id'] ?? 0 );
			$product      = wc_get_product( $variation_id ?: $product_id );
			if ( ! $product || ! Entity::resolve( $product )['managed'] ) {
				return new \WP_Error(
					'cntr_po_unmanaged_product',
					__( 'Every purchase order line must be a stock-managed product.', 'counter' ),
					[ 'status' => 400, 'product_id' => $product_id ]
				);
			}
		}

		return Db::transaction(
			function () use ( $data, $lines ) {
				global $wpdb;

				$values = [];
				$subtotal = '0.0000';
				$tax_total = '0.0000';
				foreach ( $lines as $line ) {
					$qty       = wc_format_decimal( $line['qty_ordered'] ?? 0, 4 );
					$unit_cost = wc_format_decimal( $line['unit_cost'] ?? 0, 4 );
					$tax_rate  = wc_format_decimal( $line['tax_rate'] ?? 0, 4 );
					$value     = bcmul( $qty, $unit_cost, 4 );
					$line_tax  = bcdiv( bcmul( $value, $tax_rate, 6 ), '100', 4 );

					$values[]  = [
						'product_id'   => (int) ( $line['product_id'] ?? 0 ),
						'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
						'qty'          => $qty,
						'unit_cost'    => $unit_cost,
						'tax_rate'     => $tax_rate,
						'value'        => $value,
						'lot_no'       => (string) ( $line['lot_no'] ?? '' ),
						'expiry_date'  => $line['expiry_date'] ?? null,
					];
					$subtotal  = bcadd( $subtotal, $value, 4 );
					$tax_total = bcadd( $tax_total, $line_tax, 4 );
				}

				$shipping_total = wc_format_decimal( $data['shipping_total'] ?? 0, 4 );
				$other_total    = wc_format_decimal( $data['other_total'] ?? 0, 4 );
				$charges        = bcadd( $shipping_total, $other_total, 4 );
				$grand_total    = bcadd( bcadd( $subtotal, $tax_total, 4 ), $charges, 4 );

				$shares = self::distribute_landed_cost( $values, $charges );

				$seq   = Db::next_seq( 'po_no' );
				$po_no = sprintf( 'PO-%06d', $seq );

				$po_table = Install::table( 'purchase_orders' );
				$wpdb->insert(
					$po_table,
					[
						'po_no'          => $po_no,
						'supplier_id'    => (int) ( $data['supplier_id'] ?? 0 ),
						'location_id'    => (int) ( $data['location_id'] ?? 0 ),
						'status'         => 'ordered',
						'subtotal'       => $subtotal,
						'tax_total'      => $tax_total,
						'shipping_total' => $shipping_total,
						'other_total'    => $other_total,
						'grand_total'    => $grand_total,
						'note'           => $data['note'] ?? null,
						'user_id'        => (int) ( $data['user_id'] ?? get_current_user_id() ),
						'ordered_at'     => Db::now(),
						'expected_at'    => $data['expected_at'] ?? null,
						'created_at'     => Db::now(),
					]
				);
				$po_id = (int) $wpdb->insert_id;

				$lines_table = Install::table( 'purchase_lines' );
				foreach ( $values as $i => $v ) {
					$wpdb->insert(
						$lines_table,
						[
							'po_id'             => $po_id,
							'product_id'        => $v['product_id'],
							'variation_id'      => $v['variation_id'],
							'qty_ordered'       => $v['qty'],
							'qty_received'      => '0.0000',
							'unit_cost'         => $v['unit_cost'],
							'tax_rate'          => $v['tax_rate'],
							'landed_cost_share' => $shares[ $i ],
							'batch_id'          => 0,
							'lot_no'            => $v['lot_no'],
							'expiry_date'       => $v['expiry_date'],
						]
					);
				}

				return $po_id;
			}
		);
	}

	/**
	 * Proportional by line value (qty * unit_cost, before tax), rounded to
	 * the poisha, with the rounding remainder folded onto the single largest
	 * line rather than spread further — the Direction's own words. Returns
	 * shares in the same order as $values, one 4-decimal-string per line.
	 */
	private static function distribute_landed_cost( array $values, string $charges ): array {
		$count = count( $values );
		$shares = array_fill( 0, $count, '0.0000' );

		if ( bccomp( $charges, '0', 4 ) <= 0 || 0 === $count ) {
			return $shares;
		}

		$total_value = '0.0000';
		foreach ( $values as $v ) {
			$total_value = bcadd( $total_value, $v['value'], 4 );
		}
		if ( bccomp( $total_value, '0', 4 ) <= 0 ) {
			return $shares;
		}

		$running   = '0.00';
		$largest_i = 0;
		$largest_v = '-1';
		foreach ( $values as $i => $v ) {
			$proportion = bcdiv( $v['value'], $total_value, 10 );
			$raw_share  = bcmul( $charges, $proportion, 10 );
			$rounded    = self::round_poisha( $raw_share );
			$shares[ $i ] = $rounded;
			$running      = bcadd( $running, $rounded, 2 );

			if ( bccomp( $v['value'], $largest_v, 4 ) > 0 ) {
				$largest_v = $v['value'];
				$largest_i = $i;
			}
		}

		$remainder = bcsub( $charges, $running, 2 );
		if ( bccomp( $remainder, '0', 2 ) !== 0 ) {
			$shares[ $largest_i ] = bcadd( $shares[ $largest_i ], $remainder, 4 );
		}

		return $shares;
	}

	/** Round-half-up to 2 decimals (poisha) using bcmath only — no float rounding on money, ever. */
	private static function round_poisha( string $n ): string {
		$neg = bccomp( $n, '0', 6 ) < 0;
		$abs = $neg ? bcmul( $n, '-1', 6 ) : $n;
		$rounded = bcadd( $abs, '0.005', 2 );
		return $neg ? bcmul( $rounded, '-1', 4 ) : bcadd( $rounded, '0', 4 );
	}
}
