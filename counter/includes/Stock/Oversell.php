<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * P7.5 — Direction, verbatim: "two registers offline at the same time can
 * both sell the last unit. Counter does not prevent this. It detects it."
 *
 * record() is called from Orders\Channel::apply_stock(), inside the same
 * transaction as the sale it describes, whenever Batches::consume_fifo()
 * comes back flagged_short — i.e. a location's batches ran out before the
 * sold quantity was met, and the shortfall was still consumed (costed at
 * last-known price, batch_id 0) because the goods physically left the shop.
 * This class does not decide whether the sale proceeds — that decision was
 * already made, by Batches, before this is ever called. It only writes the
 * row a manager later reconciles.
 */
class Oversell {

	public static function record( int $product_id, int $variation_id, int $location_id, int $order_id, int $register_id, array $fifo ): void {
		$qty_short = '0.0000';
		foreach ( $fifo['moves'] as $move ) {
			if ( ! empty( $move['short'] ) ) {
				$qty_short = bcadd( $qty_short, (string) $move['qty'], 4 );
			}
		}
		if ( bccomp( $qty_short, '0', 4 ) <= 0 ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			Install::table( 'oversell_log' ),
			[
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'location_id'   => $location_id,
				'order_id'      => $order_id,
				'register_id'   => $register_id,
				'qty_short'     => $qty_short,
				'balance_after' => Ledger::balance( $product_id, $variation_id, $location_id ),
				'status'        => 'open',
				'created_at'    => Db::now(),
			]
		);
	}

	/**
	 * Every row a manager still needs to look at, oldest first — mirrors
	 * Pos\Queue::failed_permanent_list().
	 */
	public static function open_list(): array {
		global $wpdb;
		$table = Install::table( 'oversell_log' );
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' ORDER BY created_at", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	/**
	 * "resolving a row writes an audit entry and does not alter the
	 * ledger" — the self-test's own words. The oversold quantity already
	 * happened and was already costed by Batches::consume_fifo() at the
	 * moment of sale; resolving here is bookkeeping acknowledgement (a
	 * restock, a write-off, a corrected count elsewhere), never a second
	 * stock movement.
	 *
	 * @return true|\WP_Error
	 */
	public static function resolve( int $id, string $note, int $user_id ) {
		global $wpdb;
		$table = Install::table( 'oversell_log' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'cntr_oversell_missing', __( 'Oversell entry not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'open' !== $row['status'] ) {
			return new \WP_Error( 'cntr_oversell_not_open', __( 'Only an open oversell entry can be resolved.', 'counter' ), [ 'status' => 409 ] );
		}
		$wpdb->update(
			$table,
			[
				'status'      => 'resolved',
				'resolution'  => $note,
				'resolved_at' => Db::now(),
			],
			[ 'id' => $id ]
		);
		Audit::log( 'oversell_resolved', 'oversell_log', $id, [ 'status' => 'open' ], [ 'status' => 'resolved', 'note' => $note ], $user_id );
		return true;
	}
}
