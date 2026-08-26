<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P2.9 — a physical count that produces a variance sheet and correct
 * balances. The ONE legitimate place in this whole plan for absolute-count
 * semantics — and even here the write is never a direct balance assignment:
 * it is a ledger move of (counted − expected), reason 'stocktake', same as
 * every other correction (Invariant I/II).
 *
 * `expected` is frozen at count_line() time — read from cntr_stock (Balance)
 * the moment a line is actually counted, never cached from when the
 * stocktake opened. A stocktake can stay open for hours; a sale in the
 * middle of that window has already moved the real balance by the time
 * someone counts that specific product, and reading `expected` late is what
 * keeps that sale from reading as shrinkage the count never actually found.
 */
class Stocktake {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'stocktakes' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function lines( int $stocktake_id ): array {
		global $wpdb;
		$table = Install::table( 'stocktake_lines' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE stocktake_id = %d ORDER BY id", $stocktake_id ), ARRAY_A );
	}

	public static function open( array $data ): int {
		global $wpdb;
		$table  = Install::table( 'stocktakes' );
		$seq    = Db::next_seq( 'stocktake_ref' );
		$ref_no = sprintf( 'ST-%06d', $seq );

		$wpdb->insert(
			$table,
			[
				'ref_no'      => $ref_no,
				'location_id' => (int) ( $data['location_id'] ?? 0 ),
				'status'      => 'open',
				'note'        => $data['note'] ?? null,
				'user_id'     => (int) ( $data['user_id'] ?? get_current_user_id() ),
				'opened_at'   => Db::now(),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Records one physical count. `expected` is read from cntr_stock right
	 * now, not cached from anywhere earlier — see the class docblock. A
	 * count matching expected still gets a stocktake_lines row (the count
	 * happened and should be on the sheet) but writes NO ledger move: a
	 * variance of zero is not a correction.
	 *
	 * @return int|\WP_Error the stocktake_lines row id, or an error if the
	 *   stocktake is not open.
	 */
	public static function count_line( int $stocktake_id, int $product_id, int $variation_id, string $counted, int $user_id = 0 ) {
		$stocktake = self::get( $stocktake_id );
		if ( ! $stocktake ) {
			return new \WP_Error( 'cntr_stocktake_missing', __( 'Stocktake not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $stocktake['status'] ) {
			return new \WP_Error( 'cntr_stocktake_closed', __( 'This stocktake is closed.', 'counter' ), [ 'status' => 409 ] );
		}

		$counted = wc_format_decimal( $counted, 4 );

		return Db::transaction(
			function () use ( $stocktake, $product_id, $variation_id, $counted, $user_id ) {
				global $wpdb;

				$expected = Ledger::balance( $product_id, $variation_id, (int) $stocktake['location_id'] );
				$variance = bcsub( $counted, $expected, 4 );

				if ( 0 !== bccomp( $variance, '0', 4 ) ) {
					Ledger::move(
						[
							'product_id'   => $product_id,
							'variation_id' => $variation_id,
							'location_id'  => (int) $stocktake['location_id'],
							'qty_delta'    => $variance,
							'reason'       => 'stocktake',
							'ref_type'     => 'stocktake',
							'ref_id'       => (int) $stocktake['id'],
							'user_id'      => $user_id,
						]
					);
					Publisher::publish( $product_id, $variation_id );
				}

				$lines_table = Install::table( 'stocktake_lines' );
				$wpdb->insert(
					$lines_table,
					[
						'stocktake_id' => (int) $stocktake['id'],
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'expected'     => $expected,
						'counted'      => $counted,
						'variance'     => $variance,
						'counted_at'   => Db::now(),
						'user_id'      => $user_id ?: get_current_user_id(),
					]
				);
				return (int) $wpdb->insert_id;
			}
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function close( int $stocktake_id ): bool|\WP_Error {
		global $wpdb;
		$table = Install::table( 'stocktakes' );

		$rows      = Db::for_update( "SELECT * FROM {$table} WHERE id = %d", $stocktake_id );
		$stocktake = $rows[0] ?? null;
		if ( ! $stocktake ) {
			return new \WP_Error( 'cntr_stocktake_missing', __( 'Stocktake not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $stocktake['status'] ) {
			return new \WP_Error( 'cntr_stocktake_already_closed', __( 'This stocktake is already closed.', 'counter' ), [ 'status' => 409 ] );
		}

		$wpdb->update( $table, [ 'status' => 'closed', 'closed_at' => Db::now() ], [ 'id' => $stocktake_id ] );
		return true;
	}

	/**
	 * Every line, and the totals a printed sheet needs — expected/counted/
	 * variance each independently summed from the lines list, the same
	 * "compute the total a second, separate way" discipline P1.16's
	 * Z-report uses, so a query bug surfaces as a mismatch rather than
	 * looking internally consistent.
	 */
	public static function variance_sheet( int $stocktake_id ): array {
		$stocktake = self::get( $stocktake_id );
		$lines     = self::lines( $stocktake_id );

		$total_expected = '0.0000';
		$total_counted  = '0.0000';
		$total_variance = '0.0000';
		foreach ( $lines as $l ) {
			$total_expected = bcadd( $total_expected, $l['expected'], 4 );
			$total_counted  = bcadd( $total_counted, $l['counted'], 4 );
			$total_variance = bcadd( $total_variance, $l['variance'], 4 );
		}

		return [
			'stocktake'      => $stocktake,
			'lines'          => $lines,
			'total_expected' => $total_expected,
			'total_counted'  => $total_counted,
			'total_variance' => $total_variance,
		];
	}
}
