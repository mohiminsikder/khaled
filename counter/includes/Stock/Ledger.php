<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * INVARIANT I — stock truth is the ledger.
 *
 * cntr_stock_moves is append-only. Every unit that enters or leaves the business
 * is one row with a signed quantity, a reason, a cost and a reference. Nobody
 * UPDATEs or DELETEs a row here, ever, in any code path including uninstall. A
 * correction is a new move with reason 'adjust' and a note.
 *
 * cntr_stock (Balance) is a cached balance derived from this table, and
 * rebuild() can reconstruct every balance in the system from the moves alone.
 */
class Ledger {

	/** Documentation only — not enforced by move(), which is not one of the tested checks. */
	const REASONS = [
		'sale', 'sale_online', 'return', 'purchase', 'adjust', 'transfer_out',
		'transfer_in', 'waste', 'damage', 'stocktake', 'opening',
	];

	/**
	 * Inserts one append-only row and applies the balance. Never call this
	 * without an outer Db::transaction() already open — it does not open one
	 * itself, because a sale writes many moves and they commit or roll back
	 * together. Never accumulate money or quantity in PHP: this writes exactly
	 * one relative delta per call, formatted with wc_format_decimal($v, 4).
	 *
	 * $m keys: product_id, variation_id (as Entity::resolve() reports them — this
	 * re-derives the actual storage key via Entity::ledger_key(), which may
	 * collapse variation_id to 0 for a parent-managed variation), location_id,
	 * qty_delta, reason, ref_type, ref_id, and optionally batch_id, unit_cost,
	 * channel, note, user_id.
	 *
	 * Rejects: location_id <= 0 (a move with nowhere to happen), and a product
	 * that Entity::resolve() reports as unmanaged (nothing to track — the caller
	 * asked the ledger to move stock for something that has none).
	 */
	public static function move( array $m ): int {
		global $wpdb;

		$location_id = (int) ( $m['location_id'] ?? 0 );
		if ( $location_id <= 0 ) {
			throw new \InvalidArgumentException( 'Ledger::move() requires a non-zero location_id.' );
		}

		$input_product_id   = (int) ( $m['product_id'] ?? 0 );
		$input_variation_id = (int) ( $m['variation_id'] ?? 0 );
		$check_id            = $input_variation_id ?: $input_product_id;

		$product = wc_get_product( $check_id );
		if ( ! $product ) {
			throw new \InvalidArgumentException( "Ledger::move(): product {$check_id} does not exist." );
		}

		$entity = Entity::resolve( $product );
		if ( ! $entity['managed'] ) {
			throw new \InvalidArgumentException( "Ledger::move(): product {$check_id} does not manage stock." );
		}

		[ $product_id, $variation_id ] = Entity::ledger_key( $entity );

		$delta     = wc_format_decimal( $m['qty_delta'] ?? 0, 4 );
		$unit_cost = wc_format_decimal( $m['unit_cost'] ?? 0, 4 );
		$now       = Db::now();

		$balance_after = Balance::apply( $product_id, $variation_id, $location_id, $delta );

		$moves_table = Install::table( 'stock_moves' );
		$wpdb->insert(
			$moves_table,
			[
				'product_id'    => $product_id,
				'variation_id'  => $variation_id,
				'location_id'   => $location_id,
				'batch_id'      => (int) ( $m['batch_id'] ?? 0 ),
				'qty_delta'     => $delta,
				'unit_cost'     => $unit_cost,
				'balance_after' => $balance_after,
				'reason'        => (string) ( $m['reason'] ?? '' ),
				'ref_type'      => (string) ( $m['ref_type'] ?? '' ),
				'ref_id'        => (int) ( $m['ref_id'] ?? 0 ),
				'channel'       => (string) ( $m['channel'] ?? '' ),
				'note'          => $m['note'] ?? null,
				'user_id'       => (int) ( $m['user_id'] ?? get_current_user_id() ),
				'created_at'    => $now,
			]
		);

		$move_id = (int) $wpdb->insert_id;

		// §3.8 naming convention: hooks Counter fires are cntr_<noun>_<verb>.
		// Fired inside the same transaction as the move itself — a listener
		// that throws here rolls back the move along with it, which is exactly
		// what makes this a safe extension point rather than a side-channel
		// that can silently diverge from the ledger.
		do_action( 'cntr_stock_moved', $product_id, $variation_id, $location_id, $delta, $move_id );

		return $move_id;
	}

	public static function balance( int $product_id, int $variation_id, int $location_id ): string {
		return Balance::read( $product_id, $variation_id, $location_id );
	}

	/**
	 * Recomputes cntr_stock from SUM(qty_delta) grouped by item and location —
	 * the proof that Invariant I holds: the ledger is the truth, the balance
	 * table is just a cache of it. Shipped first in P1.4 (test_ledger_atomic()
	 * needs it to prove a corrupted balance is recoverable); P2.10 adds
	 * $dry_run and a detailed per-row report for `wp counter rebuild-stock`
	 * (Cli.php) — every row it WOULD change, old value next to new, reported
	 * whether or not anything is actually written.
	 *
	 * $product_id = null rebuilds every item; a specific id rebuilds only rows
	 * touching that product (by product_id OR variation_id, so a parent id also
	 * catches its parent-managed variations' shared row). Orphan zeroing (a
	 * cntr_stock row with no moves behind it at all) only runs on a full,
	 * unscoped rebuild — a scoped one has no way to know an id it wasn't asked
	 * about is truly orphaned rather than simply untouched this run.
	 *
	 * @return array{touched:array,orphans_reset:array,count:int}
	 */
	public static function rebuild( ?int $product_id = null, bool $dry_run = false ): array {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		$stock_table = Install::table( 'stock' );

		if ( null === $product_id ) {
			$rows = $wpdb->get_results(
				"SELECT product_id, variation_id, location_id, SUM(qty_delta) AS total
				 FROM {$moves_table}
				 GROUP BY product_id, variation_id, location_id",
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT product_id, variation_id, location_id, SUM(qty_delta) AS total
					 FROM {$moves_table}
					 WHERE product_id = %d OR variation_id = %d
					 GROUP BY product_id, variation_id, location_id",
					$product_id,
					$product_id
				),
				ARRAY_A
			);
		}

		$touched = [];
		foreach ( $rows as $row ) {
			$pid    = (int) $row['product_id'];
			$vid    = (int) $row['variation_id'];
			$lid    = (int) $row['location_id'];
			$before = Balance::read( $pid, $vid, $lid );
			$after  = wc_format_decimal( $row['total'], 4 );

			if ( 0 !== bccomp( $before, $after, 4 ) ) {
				$touched[] = [
					'product_id'   => $pid,
					'variation_id' => $vid,
					'location_id'  => $lid,
					'before'       => $before,
					'after'        => $after,
				];
				if ( ! $dry_run ) {
					Balance::set( $pid, $vid, $lid, $after );
				}
			}
		}

		// A row with moves summing to exactly zero would be skipped by the loop
		// above (SUM still returns a row, so this is actually fine — SUM(0
		// deltas) still produces one grouped row). The real gap this closes is a
		// cntr_stock row with NO moves behind it at all (a stray write from
		// somewhere that bypassed the ledger) — reset it to zero rather than
		// leaving it holding a number the moves don't support.
		$orphans_reset = [];
		if ( null === $product_id ) {
			$orphans = $wpdb->get_results(
				"SELECT s.product_id, s.variation_id, s.location_id, s.qty FROM {$stock_table} s
				 LEFT JOIN (
					SELECT product_id, variation_id, location_id FROM {$moves_table} GROUP BY 1,2,3
				 ) m ON m.product_id = s.product_id AND m.variation_id = s.variation_id AND m.location_id = s.location_id
				 WHERE m.product_id IS NULL",
				ARRAY_A
			);
			foreach ( $orphans as $row ) {
				$pid = (int) $row['product_id'];
				$vid = (int) $row['variation_id'];
				$lid = (int) $row['location_id'];
				if ( 0 !== bccomp( $row['qty'], '0', 4 ) ) {
					$orphans_reset[] = [
						'product_id'   => $pid,
						'variation_id' => $vid,
						'location_id'  => $lid,
						'before'       => $row['qty'],
						'after'        => '0.0000',
					];
					if ( ! $dry_run ) {
						Balance::set( $pid, $vid, $lid, '0.0000' );
					}
				}
			}
		}

		return [
			'touched'       => $touched,
			'orphans_reset' => $orphans_reset,
			'count'         => count( $touched ) + count( $orphans_reset ),
		];
	}
}
