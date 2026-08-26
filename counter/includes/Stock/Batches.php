<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P2.7 — a batch is created on receiving with a lot number, an expiry and a
 * landed unit cost. Same discipline as cntr_stock (Balance): `qty_remaining`
 * is a CACHED balance, updated with relative writes only (Invariant II), and
 * the truth is the sum of cntr_stock_moves rows carrying that batch_id —
 * rebuild() proves it, the same way Ledger::rebuild() proves cntr_stock.
 *
 * receive() writes the one 'purchase' ledger move that brings a batch into
 * existence; P2.4 (purchase orders) is the caller that will actually reach
 * this from a real PO, and P2.8 (FIFO) is the caller that will reach
 * consume() from a real sale. Built and self-tested standing alone, same as
 * every other domain class in this plan.
 */
class Batches {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'batches' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Creates the batch row AND the one 'purchase' ledger move that puts its
	 * units into cntr_stock — a batch with no corresponding move would be a
	 * balance nothing else in the system can see. $data: product_id,
	 * variation_id, location_id, lot_no, expiry_date (Y-m-d or null),
	 * qty_received, unit_cost, po_id, ref_type, ref_id, user_id.
	 */
	public static function receive( array $data ): int {
		global $wpdb;

		$qty = wc_format_decimal( $data['qty_received'] ?? 0, 4 );
		if ( bccomp( $qty, '0', 4 ) <= 0 ) {
			throw new \InvalidArgumentException( 'Batches::receive() requires qty_received greater than zero.' );
		}
		$unit_cost = wc_format_decimal( $data['unit_cost'] ?? 0, 4 );

		return Db::transaction(
			function () use ( $wpdb, $data, $qty, $unit_cost ) {
				$table = Install::table( 'batches' );
				$wpdb->insert(
					$table,
					[
						'product_id'   => (int) ( $data['product_id'] ?? 0 ),
						'variation_id' => (int) ( $data['variation_id'] ?? 0 ),
						'location_id'  => (int) ( $data['location_id'] ?? 0 ),
						'lot_no'       => (string) ( $data['lot_no'] ?? '' ),
						'expiry_date'  => $data['expiry_date'] ?? null,
						'qty_received' => $qty,
						'qty_remaining' => $qty,
						'unit_cost'    => $unit_cost,
						'po_id'        => (int) ( $data['po_id'] ?? 0 ),
						'received_at'  => Db::now(),
					]
				);
				$batch_id = (int) $wpdb->insert_id;

				Ledger::move(
					[
						'product_id'   => $data['product_id'] ?? 0,
						'variation_id' => $data['variation_id'] ?? 0,
						'location_id'  => $data['location_id'] ?? 0,
						'qty_delta'    => $qty,
						'unit_cost'    => $unit_cost,
						'batch_id'     => $batch_id,
						'reason'       => 'purchase',
						'ref_type'     => $data['ref_type'] ?? 'batch',
						'ref_id'       => (int) ( $data['ref_id'] ?? $data['po_id'] ?? 0 ),
						'user_id'      => (int) ( $data['user_id'] ?? 0 ),
					]
				);
				Publisher::publish( (int) ( $data['product_id'] ?? 0 ), (int) ( $data['variation_id'] ?? 0 ) );

				return $batch_id;
			}
		);
	}

	/**
	 * Consumes $qty from a specific batch — a signed-negative ledger move
	 * with THIS batch's id, and a relative decrement of the cached
	 * qty_remaining (never a computed absolute SET, same rule as
	 * Balance::apply()). Refuses outright, writing nothing, if $qty exceeds
	 * what remains: unlike a general stock adjustment (which allows negative
	 * and flags it — a data-entry correction can legitimately need that), a
	 * specific physical lot cannot have more taken from it than it ever held.
	 * $move_extra can override reason/ref_type/ref_id/user_id (P2.8's FIFO
	 * consumption will pass 'sale'/'order'/order_id here; the self-test uses
	 * the 'adjust' default).
	 */
	public static function consume( int $batch_id, string $qty, array $move_extra = [] ): int {
		global $wpdb;
		$qty = wc_format_decimal( $qty, 4 );
		if ( bccomp( $qty, '0', 4 ) <= 0 ) {
			throw new \InvalidArgumentException( 'Batches::consume() requires a positive quantity.' );
		}

		return Db::transaction(
			function () use ( $wpdb, $batch_id, $qty, $move_extra ) {
				$table = Install::table( 'batches' );
				$rows  = Db::for_update( "SELECT * FROM {$table} WHERE id = %d", $batch_id );
				$batch = $rows[0] ?? null;
				if ( ! $batch ) {
					throw new \InvalidArgumentException( "Batches::consume(): batch {$batch_id} does not exist." );
				}

				$remaining = wc_format_decimal( $batch['qty_remaining'], 4 );
				if ( bccomp( $qty, $remaining, 4 ) > 0 ) {
					throw new \RuntimeException( "Batches::consume(): batch {$batch_id} only has {$remaining} remaining, cannot consume {$qty}." );
				}

				$move_id = Ledger::move(
					array_merge(
						[
							'product_id'   => $batch['product_id'],
							'variation_id' => $batch['variation_id'],
							'location_id'  => $batch['location_id'],
							'qty_delta'    => bcmul( $qty, '-1', 4 ),
							'unit_cost'    => $batch['unit_cost'],
							'batch_id'     => $batch_id,
							'reason'       => 'adjust',
							'ref_type'     => 'batch_consume',
							'ref_id'       => 0,
						],
						$move_extra
					)
				);

				self::apply_qty_remaining( $batch_id, bcmul( $qty, '-1', 4 ) );
				Publisher::publish( (int) $batch['product_id'], (int) $batch['variation_id'] );

				return $move_id;
			}
		);
	}

	/**
	 * P2.8 — walks batches at this exact product/variation/LOCATION oldest-
	 * received first, consuming from each until $qty_needed is met. A sale
	 * spanning two batches writes TWO ledger moves (one per batch, each
	 * carrying that batch's own real unit_cost) rather than one averaged
	 * move — 'moves' below is exactly that list. 'weighted_unit_cost' is a
	 * SEPARATE, derived figure for the caller (Channel freezes it into an
	 * order's own COGS) — never itself written as a move's unit_cost.
	 *
	 * If the location's batches run out before $qty_needed is met (offline
	 * oversell, P7.5, or simply bad data), the shortfall is consumed anyway
	 * at last_known_cost() with batch_id 0 and a note — flagged, not
	 * refused: Direction is explicit that the sale proceeds. 'flagged_short'
	 * is true whenever that branch fired at all.
	 *
	 * $move_extra is merged into every move this writes (reason, ref_type,
	 * ref_id, channel, user_id — whatever the caller needs on every line).
	 *
	 * @return array{moves:array,total_cost:string,total_qty:string,weighted_unit_cost:string,flagged_short:bool}
	 */
	public static function consume_fifo( int $product_id, int $variation_id, int $location_id, string $qty_needed, array $move_extra = [] ): array {
		$remaining = wc_format_decimal( $qty_needed, 4 );
		if ( bccomp( $remaining, '0', 4 ) <= 0 ) {
			throw new \InvalidArgumentException( 'Batches::consume_fifo() requires a positive quantity.' );
		}

		$moves         = [];
		$total_cost    = '0.0000';
		$total_qty     = '0.0000';
		$flagged_short = false;

		foreach ( self::open_batches_fifo( $product_id, $variation_id, $location_id ) as $batch ) {
			if ( bccomp( $remaining, '0', 4 ) <= 0 ) {
				break;
			}
			$available = wc_format_decimal( $batch['qty_remaining'], 4 );
			if ( bccomp( $available, '0', 4 ) <= 0 ) {
				continue;
			}
			$take = bccomp( $available, $remaining, 4 ) <= 0 ? $available : $remaining;

			$move_id = self::consume( (int) $batch['id'], $take, $move_extra );

			$moves[]    = [ 'move_id' => $move_id, 'batch_id' => (int) $batch['id'], 'qty' => $take, 'unit_cost' => $batch['unit_cost'] ];
			$total_cost = bcadd( $total_cost, bcmul( $take, $batch['unit_cost'], 4 ), 4 );
			$total_qty  = bcadd( $total_qty, $take, 4 );
			$remaining  = bcsub( $remaining, $take, 4 );
		}

		if ( bccomp( $remaining, '0', 4 ) > 0 ) {
			$flagged_short = true;
			$last_cost     = self::last_known_cost( $product_id, $variation_id );

			// $move_extra first (supplies reason/ref_type/ref_id/channel/
			// user_id defaults), then the computed fields — these must win
			// unconditionally, note included, regardless of what the caller
			// passed.
			$move_id = Ledger::move(
				array_merge(
					$move_extra,
					[
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'location_id'  => $location_id,
						'qty_delta'    => bcmul( $remaining, '-1', 4 ),
						'unit_cost'    => $last_cost,
						'batch_id'     => 0,
						'note'         => __( 'FIFO short: no batch stock remained at this location; costed at last-known price.', 'counter' ),
					]
				)
			);

			$moves[]    = [ 'move_id' => $move_id, 'batch_id' => 0, 'qty' => $remaining, 'unit_cost' => $last_cost, 'short' => true ];
			$total_cost = bcadd( $total_cost, bcmul( $remaining, $last_cost, 4 ), 4 );
			$total_qty  = bcadd( $total_qty, $remaining, 4 );
			$remaining  = '0.0000';

			// consume() (the real-batch path above) publishes internally on
			// every call; this direct Ledger::move() does not, and a sale
			// that runs entirely through this branch (no batches at all for
			// the item, e.g. every pre-P2.7 product) must still update the
			// storefront figure — found live: test_wc_stock_authority (P1.1,
			// no batches involved at all) went from a pass to WooCommerce's
			// own stock never moving, because this was the only branch that
			// forgot to.
			Publisher::publish( $product_id, $variation_id );
		}

		$weighted_unit_cost = bccomp( $total_qty, '0', 4 ) > 0 ? bcdiv( $total_cost, $total_qty, 4 ) : '0.0000';

		return [
			'moves'              => $moves,
			'total_cost'         => $total_cost,
			'total_qty'          => $total_qty,
			'weighted_unit_cost' => $weighted_unit_cost,
			'flagged_short'      => $flagged_short,
		];
	}

	/**
	 * The reverse of consume() — a positive ledger move crediting a SPECIFIC
	 * batch back (a return restocking to the exact lot it came from, P2.8),
	 * and a relative increment of qty_remaining. No upper bound to refuse
	 * against: unlike consume(), restoring can never legitimately go
	 * "negative" in the sense that check guards against.
	 */
	public static function restore( int $batch_id, string $qty, array $move_extra = [] ): int {
		$qty = wc_format_decimal( $qty, 4 );
		if ( bccomp( $qty, '0', 4 ) <= 0 ) {
			throw new \InvalidArgumentException( 'Batches::restore() requires a positive quantity.' );
		}

		return Db::transaction(
			function () use ( $batch_id, $qty, $move_extra ) {
				$batch = self::get( $batch_id );
				if ( ! $batch ) {
					throw new \InvalidArgumentException( "Batches::restore(): batch {$batch_id} does not exist." );
				}

				$move_id = Ledger::move(
					array_merge(
						[
							'product_id'   => $batch['product_id'],
							'variation_id' => $batch['variation_id'],
							'location_id'  => $batch['location_id'],
							'qty_delta'    => $qty,
							'unit_cost'    => $batch['unit_cost'],
							'batch_id'     => $batch_id,
							'reason'       => 'return',
							'ref_type'     => '',
							'ref_id'       => 0,
						],
						$move_extra
					)
				);

				self::apply_qty_remaining( $batch_id, $qty );
				Publisher::publish( (int) $batch['product_id'], (int) $batch['variation_id'] );

				return $move_id;
			}
		);
	}

	/**
	 * The most recently RECEIVED batch's cost for this exact product/
	 * variation, regardless of location or how much (if any) remains —
	 * "last known" means the last price actually paid, not the last price
	 * anywhere with stock left. Falls back to the pre-P2.8 stub
	 * (_purchase_price product meta, then '0') only if this product has
	 * never had a batch at all.
	 */
	public static function last_known_cost( int $product_id, int $variation_id ): string {
		global $wpdb;
		$table = Install::table( 'batches' );
		$cost  = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT unit_cost FROM {$table} WHERE product_id = %d AND variation_id = %d ORDER BY received_at DESC, id DESC LIMIT 1",
				$product_id,
				$variation_id
			)
		);
		if ( null !== $cost ) {
			return wc_format_decimal( $cost, 4 );
		}

		$product = wc_get_product( $variation_id ?: $product_id );
		if ( $product ) {
			$price = $product->get_meta( '_purchase_price' );
			if ( '' !== $price && null !== $price ) {
				return wc_format_decimal( $price, 4 );
			}
		}
		return '0.0000';
	}

	/** Open (qty_remaining > 0) batches at one exact location, oldest-received first. */
	private static function open_batches_fifo( int $product_id, int $variation_id, int $location_id ): array {
		global $wpdb;
		$table = Install::table( 'batches' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d AND location_id = %d AND qty_remaining > 0 ORDER BY received_at ASC, id ASC",
				$product_id,
				$variation_id,
				$location_id
			),
			ARRAY_A
		);
	}

	/**
	 * expiry_date <= today + $days, still holding stock, oldest first — the
	 * boundary itself (exactly $days out) IS included, one day past it is not.
	 */
	public static function near_expiry( int $days, ?int $location_id = null ): array {
		global $wpdb;
		$table    = Install::table( 'batches' );
		$boundary = gmdate( 'Y-m-d', strtotime( Db::now() ) + ( $days * DAY_IN_SECONDS ) );

		if ( $location_id ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE expiry_date IS NOT NULL AND expiry_date <= %s AND qty_remaining > 0 AND location_id = %d ORDER BY expiry_date",
					$boundary,
					$location_id
				),
				ARRAY_A
			);
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE expiry_date IS NOT NULL AND expiry_date <= %s AND qty_remaining > 0 ORDER BY expiry_date",
				$boundary
			),
			ARRAY_A
		);
	}

	/**
	 * Recomputes qty_remaining from SUM(qty_delta) grouped by batch_id — the
	 * proof this cache is honest. $batch_id = null rebuilds every batch.
	 * $dry_run (P2.10) reports what would change without writing — used by
	 * `wp counter rebuild-stock` (Cli.php), same shape as Ledger::rebuild().
	 *
	 * @return array{touched:array,count:int}
	 */
	public static function rebuild( ?int $batch_id = null, bool $dry_run = false ): array {
		global $wpdb;
		$moves_table  = Install::table( 'stock_moves' );
		$batches_table = Install::table( 'batches' );

		if ( null === $batch_id ) {
			$rows = $wpdb->get_results(
				"SELECT batch_id, SUM(qty_delta) AS total FROM {$moves_table} WHERE batch_id > 0 GROUP BY batch_id",
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT batch_id, SUM(qty_delta) AS total FROM {$moves_table} WHERE batch_id = %d GROUP BY batch_id",
					$batch_id
				),
				ARRAY_A
			);
		}

		$touched = [];
		foreach ( $rows as $row ) {
			$id     = (int) $row['batch_id'];
			$before = $wpdb->get_var( $wpdb->prepare( "SELECT qty_remaining FROM {$batches_table} WHERE id = %d", $id ) );

			// stock_moves (Invariant I, permanent) can carry a batch_id whose
			// cntr_batches row is long gone — a batch belonging to a fixture
			// product deleted since. $wpdb->update() against a nonexistent
			// id silently affects 0 rows either way, but reporting it as
			// "touched" here would be a real, misleading line in `wp counter
			// rebuild-stock`'s own output — found live running it unscoped
			// against this whole self-test-worn database. Nothing to
			// reconstruct a row that isn't there; skip it.
			if ( null === $before ) {
				continue;
			}

			$after = wc_format_decimal( $row['total'], 4 );
			if ( 0 !== bccomp( $before, $after, 4 ) ) {
				$touched[] = [ 'batch_id' => $id, 'before' => (string) $before, 'after' => $after ];
				if ( ! $dry_run ) {
					$wpdb->update( $batches_table, [ 'qty_remaining' => $after ], [ 'id' => $id ] );
				}
			}
		}
		return [ 'touched' => $touched, 'count' => count( $touched ) ];
	}

	private static function apply_qty_remaining( int $batch_id, string $delta ): void {
		global $wpdb;
		$table = Install::table( 'batches' );
		$delta = wc_format_decimal( $delta, 4 );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET qty_remaining = qty_remaining + %s WHERE id = %d",
				$delta,
				$batch_id
			)
		);
	}
}
