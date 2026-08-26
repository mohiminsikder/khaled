<?php
namespace Counter\Purchasing;

use Counter\Install;
use Counter\Db;
use Counter\Stock\Batches;

defined( 'ABSPATH' ) || exit;

/**
 * P2.4 — the receiving half of Orders. Partial receiving is the normal case:
 * a line's landed_cost_share is FIXED at ordering time (Orders::create()),
 * and every receiving event just applies a proportional slice of it —
 * `share / qty_ordered` is a constant per-unit rate for the line's whole
 * life, so receiving 3 of 10 today and the remaining 7 next month adds up to
 * exactly the line's fixed share either way, never drifting.
 */
class Receiving {

	/**
	 * $received: [ purchase_line.id => qty being received right now, ... ].
	 * Every line is validated against its own outstanding qty BEFORE anything
	 * is written — one line over-receiving refuses the WHOLE call, nothing
	 * partially applied.
	 *
	 * @return array{po_id:int,batch_ids:array<int>,status:string,bill_total:string,replayed:bool}|\WP_Error
	 */
	public static function receive( int $po_id, string $uuid, array $received, int $user_id = 0 ) {
		$uuid = trim( $uuid );
		if ( '' === $uuid ) {
			return new \WP_Error( 'cntr_po_receive_no_uuid', __( 'A client UUID is required.', 'counter' ), [ 'status' => 400 ] );
		}

		// Same atomic add_option idempotency guard as Rest\Stock::process()
		// (P2.2) — no dedicated table for this either, the plan lists none.
		$option_name = 'cntr_po_receive_uuid_' . sanitize_key( $uuid );
		$existing    = get_option( $option_name, false );
		if ( false !== $existing ) {
			$replay             = json_decode( (string) $existing, true );
			$replay['replayed'] = true;
			return $replay;
		}

		$result = Db::transaction(
			function () use ( $po_id, $received, $user_id ) {
				global $wpdb;

				$po_table = Install::table( 'purchase_orders' );
				$po_rows  = Db::for_update( "SELECT * FROM {$po_table} WHERE id = %d", $po_id );
				$po       = $po_rows[0] ?? null;
				if ( ! $po ) {
					return new \WP_Error( 'cntr_po_missing', __( 'Purchase order not found.', 'counter' ), [ 'status' => 404 ] );
				}
				if ( 'closed' === $po['status'] ) {
					return new \WP_Error( 'cntr_po_closed', __( 'This purchase order is already fully received.', 'counter' ), [ 'status' => 409 ] );
				}

				$lines_table = Install::table( 'purchase_lines' );
				$all_lines   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lines_table} WHERE po_id = %d", $po_id ), ARRAY_A );

				// Validate every requested line FIRST — all or nothing.
				$to_apply = [];
				foreach ( $received as $line_id => $qty_now ) {
					$qty_now = wc_format_decimal( $qty_now, 4 );
					if ( bccomp( $qty_now, '0', 4 ) <= 0 ) {
						continue;
					}
					$line = null;
					foreach ( $all_lines as $l ) {
						if ( (int) $l['id'] === (int) $line_id ) {
							$line = $l;
							break;
						}
					}
					if ( ! $line ) {
						return new \WP_Error( 'cntr_po_bad_line', __( 'That line does not belong to this purchase order.', 'counter' ), [ 'status' => 400 ] );
					}
					$outstanding = bcsub( $line['qty_ordered'], $line['qty_received'], 4 );
					if ( bccomp( $qty_now, $outstanding, 4 ) > 0 ) {
						return new \WP_Error(
							'cntr_po_overreceive',
							__( 'Cannot receive more than is outstanding on this line.', 'counter' ),
							[ 'status' => 409, 'line_id' => (int) $line_id, 'outstanding' => $outstanding ]
						);
					}
					$to_apply[] = [ 'line' => $line, 'qty_now' => $qty_now ];
				}

				if ( empty( $to_apply ) ) {
					return new \WP_Error( 'cntr_po_nothing_to_receive', __( 'Nothing to receive.', 'counter' ), [ 'status' => 400 ] );
				}

				$batch_ids  = [];
				$bill_total = '0.0000';

				foreach ( $to_apply as $entry ) {
					$line    = $entry['line'];
					$qty_now = $entry['qty_now'];

					$per_unit_landed = bccomp( $line['qty_ordered'], '0', 4 ) > 0
						? bcdiv( $line['landed_cost_share'], $line['qty_ordered'], 6 )
						: '0';
					$unit_cost_landed = bcadd( $line['unit_cost'], $per_unit_landed, 4 );

					$batch_id = Batches::receive(
						[
							'product_id'   => $line['product_id'],
							'variation_id' => $line['variation_id'],
							'location_id'  => $po['location_id'],
							'lot_no'       => $line['lot_no'],
							'expiry_date'  => $line['expiry_date'] ?: null,
							'qty_received' => $qty_now,
							'unit_cost'    => $unit_cost_landed,
							'po_id'        => $po_id,
							'ref_type'     => 'purchase_order',
							'ref_id'       => $po_id,
							'user_id'      => $user_id,
						]
					);
					$batch_ids[] = $batch_id;

					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$lines_table} SET qty_received = qty_received + %s, batch_id = %d WHERE id = %d",
							$qty_now,
							$batch_id,
							$line['id']
						)
					);

					$bill_total = bcadd( $bill_total, bcmul( $qty_now, $unit_cost_landed, 4 ), 4 );
				}

				if ( (int) $po['supplier_id'] > 0 && bccomp( $bill_total, '0', 4 ) !== 0 ) {
					// Due date = today + the supplier's own payment terms —
					// P2.5's aging() reads this back to bucket the bill.
					$supplier  = Suppliers::get( (int) $po['supplier_id'] );
					$terms     = $supplier ? (int) $supplier['terms_days'] : 0;
					$due_date  = gmdate( 'Y-m-d', strtotime( Db::now() ) + ( $terms * DAY_IN_SECONDS ) );

					Suppliers::write_ledger_row(
						(int) $po['supplier_id'],
						'bill',
						$bill_total,
						'purchase_order',
						$po_id,
						sprintf( __( 'Goods received against %s', 'counter' ), $po['po_no'] ),
						$due_date,
						$user_id
					);
				}

				$fresh_lines = $wpdb->get_results( $wpdb->prepare( "SELECT qty_ordered, qty_received FROM {$lines_table} WHERE po_id = %d", $po_id ), ARRAY_A );
				$fully       = true;
				foreach ( $fresh_lines as $l ) {
					if ( bccomp( $l['qty_ordered'], $l['qty_received'], 4 ) > 0 ) {
						$fully = false;
						break;
					}
				}
				$new_status = $fully ? 'closed' : 'partial';
				$wpdb->update( $po_table, [ 'status' => $new_status ], [ 'id' => $po_id ] );

				return [
					'po_id'      => $po_id,
					'batch_ids'  => $batch_ids,
					'status'     => $new_status,
					'bill_total' => $bill_total,
					'replayed'   => false,
				];
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		add_option( $option_name, wp_json_encode( $result ), '', 'no' );
		return $result;
	}
}
