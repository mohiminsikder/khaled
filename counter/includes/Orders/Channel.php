<?php
namespace Counter\Orders;

use Counter\Install;
use Counter\Db;
use Counter\Stock\Entity;
use Counter\Stock\Ledger;
use Counter\Stock\Publisher;
use Counter\Stock\Locations;
use Counter\Stock\Batches;
use Counter\Stock\Oversell;

defined( 'ABSPATH' ) || exit;

/**
 * One guarded function moves stock for an order, on any channel, exactly once.
 *
 * The guard is a table row, not order meta. Order meta would be
 * read-check-write: two concurrent calls both read "not applied" and both
 * apply. An upsert against a PRIMARY KEY is atomic — exactly one caller gets
 * rows_affected === 1 and does the work; the other gets 0 and returns false,
 * the correct, quiet outcome for a replayed webhook.
 *
 * ON DUPLICATE KEY UPDATE order_id = order_id, never INSERT IGNORE. IGNORE
 * downgrades every error to a warning — a truncated column, a bad foreign
 * value, a disk-full — and all of them would read here as "already applied,"
 * skipping the stock movement while reporting success. The upsert form
 * suppresses only the duplicate-key case, the one genuinely expected outcome.
 *
 * The guard INSERT/UPDATE is inside the same transaction as the moves it
 * guards, so a rollback anywhere in the enclosing operation releases the
 * guard too and the order can be retried.
 */
class Channel {

	/**
	 * P4.1 — the other half of audit finding B1. apply_stock()/revert_stock()/
	 * apply_refund_reversion() (P1.11) already exist and are already exercised
	 * by the POS path (Rest\Sale.php, Orders\Refunds.php); nothing before this
	 * task ever wired a single WooCommerce hook, so a storefront order never
	 * touched the ledger at all and Ledger::rebuild() would silently erase
	 * every online sale.
	 *
	 * Hooking BOTH 'cancelled' and 'refunded' to revert_stock() looks, at
	 * first read, like it would double-restock an order that already had a
	 * partial refund reverted through apply_refund_reversion() (a different,
	 * refund-keyed guard row). For two genuinely SEPARATE, sequential
	 * refunds — e.g. a partial refund today, the order fully refunded (and
	 * so transitioned to 'refunded') next week — it does not:
	 * restore_to_batches() (shared by both) computes, per original sale
	 * move, how much of THAT move has already been credited back by summing
	 * every prior 'return' move against it, so the second call only ever
	 * restores what is genuinely still outstanding.
	 *
	 * That is NOT enough on its own for a single wc_create_refund() call
	 * that refunds 100% of the order — found live: WooCommerce transitions
	 * the order straight to 'refunded' (firing on_stock_reverting_status())
	 * INSIDE that same call, before Orders\Refunds::process()'s own explicit
	 * apply_refund_reversion() call even runs, and the two ended up each
	 * restoring the full quantity rather than the second one seeing the
	 * first's row already there. without_refund_hook() is the real guard for
	 * THAT case: it suppresses both hooks for the duration of a POS-initiated
	 * wc_create_refund() call, so Orders\Refunds::process() remains the only
	 * caller doing the reversion, atomically, inside its own transaction,
	 * exactly as before this task.
	 */
	public static function init(): void {
		add_action( 'woocommerce_new_order', [ self::class, 'tag_channel' ], 10, 2 );
		add_action( 'init', [ self::class, 'register_document_statuses' ] );
		add_filter( 'wc_order_statuses', [ self::class, 'add_document_statuses_to_list' ] );

		foreach ( [ 'on-hold', 'processing', 'completed' ] as $status ) {
			add_action( "woocommerce_order_status_{$status}", [ self::class, 'on_stock_applying_status' ] );
		}
		add_action( 'woocommerce_order_status_cancelled', [ self::class, 'on_stock_reverting_status' ] );
		add_action( 'woocommerce_order_status_refunded', [ self::class, 'on_stock_reverting_status' ] );

		// B6 — 'cntr-draft'/'cntr-quotation' are DELIBERATELY absent from the
		// foreach above: a sale document "never touch[es] the stock ledger" —
		// the whole point is that Orders\Builder can build one exactly the
		// same way as a real sale (same pricing/tax/discount logic) and have
		// it sit inert until Rest\Sale::finalize() moves it to 'completed',
		// which IS already in that list. Adding either status here would
		// silently apply stock the moment a draft is saved — a future editor
		// of this foreach should read this comment before "just adding every
		// status" to it.

		// WooCommerce's own refund-creation action — fires for a partial OR
		// full refund, created anywhere (wp-admin, a gateway webhook, or
		// Orders\Refunds::process() itself), and fires SYNCHRONOUSLY inside
		// wc_create_refund() — before Orders\Refunds::process()'s own
		// transaction even opens. Found live: that ordering means a
		// POS-initiated refund's reversion would happen HERE, outside
		// Refunds::process()'s transaction, breaking its atomicity with the
		// tenders/shift_event/shift_sale_return writes it wraps in the same
		// transaction — and Refunds::process()'s own explicit call would then
		// see the guard already consumed and (correctly, at the time it was
		// written) throw "already processed". without_refund_hook() is how
		// Refunds::process() opts its OWN refunds out of this hook entirely,
		// so POS refunds keep full atomicity exactly as before this task; an
		// admin-created refund (wp-admin, a gateway webhook) has no such
		// caller and is exactly what this hook exists to cover.
		add_action( 'woocommerce_order_refunded', [ self::class, 'on_order_refunded' ], 10, 2 );
	}

	/**
	 * B6 — the two Counter-owned statuses a sale document can sit in before
	 * it becomes a real sale. Registered as real WordPress post statuses (not
	 * just a meta flag) so wc_get_orders()/WP_Query and the admin orders
	 * screen all recognise them the same way they recognise 'wc-completed'.
	 */
	const DOCUMENT_STATUSES = [
		'cntr-draft'      => 'Draft',
		'cntr-quotation'  => 'Quotation',
	];

	public static function register_document_statuses(): void {
		foreach ( self::DOCUMENT_STATUSES as $status => $label ) {
			register_post_status(
				'wc-' . $status,
				[
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders in this status */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'counter' ),
				]
			);
		}
	}

	public static function add_document_statuses_to_list( array $statuses ): array {
		foreach ( self::DOCUMENT_STATUSES as $status => $label ) {
			$statuses[ 'wc-' . $status ] = $label;
		}
		return $statuses;
	}

	/** Set only while a POS caller's own wc_create_refund() call is in flight — see init()'s own comment on woocommerce_order_refunded. */
	private static bool $suppress_refund_hook = false;

	/**
	 * Wrap a caller's own wc_create_refund() call in this so
	 * on_order_refunded() does not also react to the refund it is about to
	 * create — the caller is expected to revert stock itself (Orders\Refunds
	 * ::process() does, inside its own transaction, immediately after).
	 */
	public static function without_refund_hook( callable $fn ) {
		self::$suppress_refund_hook = true;
		try {
			return $fn();
		} finally {
			self::$suppress_refund_hook = false;
		}
	}

	/**
	 * Every order not built by Orders\Builder (which tags 'pos' itself,
	 * in-memory, before its own first save()) reaches here untagged.
	 * $order is the SAME in-memory object WooCommerce's own save() just
	 * acted on — reading its meta here, rather than re-fetching by id, sees
	 * whatever Builder already set regardless of exactly when that save
	 * flushed to the database (Builder.php's own docblock: "update_meta_data()
	 * populates the in-memory prop immediately, no intermediate save()
	 * required"). save_meta_data() persists just this one change without
	 * re-running the full save()/status-transition machinery.
	 */
	public static function tag_channel( int $order_id, \WC_Order $order ): void {
		if ( $order instanceof \WC_Order_Refund ) {
			return;
		}
		if ( '' === (string) $order->get_meta( '_cntr_channel' ) ) {
			$order->update_meta_data( '_cntr_channel', 'online' );
			$order->save_meta_data();
		}
	}

	public static function on_stock_applying_status( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( self::apply_stock( $order ) ) {
			$order->save(); // persists _cntr_cogs — see apply_stock()'s own docblock
		}
	}

	public static function on_stock_reverting_status( int $order_id ): void {
		// A 100%-refunded order's status transitions straight to 'refunded'
		// AS PART OF wc_create_refund() itself — found live: this fires
		// woocommerce_order_status_refunded (this handler) INSIDE that same
		// call, a separate action from woocommerce_order_refunded, so
		// without_refund_hook() must suppress both or a POS-initiated full
		// refund double-restocks (this handler's order-level guard has never
		// been touched, so it proceeds, then Refunds::process()'s own
		// explicit apply_refund_reversion() call restores the same quantity
		// again a moment later).
		if ( self::$suppress_refund_hook ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::revert_stock( $order );
		}
	}

	public static function on_order_refunded( int $order_id, int $refund_id ): void {
		if ( self::$suppress_refund_hook ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( $order instanceof \WC_Order && $refund instanceof \WC_Order_Refund ) {
			self::apply_refund_reversion( $order, $refund );
		}
	}

	public static function apply_stock( \WC_Order $order ): bool {
		return Db::transaction(
			function () use ( $order ) {
				global $wpdb;
				$t = Install::table( 'order_stock_state' );

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$t} (order_id, refund_id, state, applied_at)
						 VALUES (%d, 0, 'applied', %s)
						 ON DUPLICATE KEY UPDATE order_id = order_id",
						$order->get_id(),
						Db::now()
					)
				);
				if ( 0 === $wpdb->rows_affected ) {
					return false; // already applied — not an error
				}

				$location_id = self::location_for( $order );
				$channel     = (string) $order->get_meta( '_cntr_channel' ) ?: 'online';
				$total_cogs  = '0.0000';
				$register_id = (int) $order->get_meta( '_cntr_register_id' );

				foreach ( $order->get_items() as $item ) {
					$e = Entity::for_item( $item );
					if ( ! $e || ! $e['managed'] ) {
						continue;
					}

					$qty  = self::line_qty( $item );
					$fifo = Batches::consume_fifo(
						$e['product_id'],
						$e['variation_id'],
						$location_id,
						$qty,
						[
							'reason'   => 'online' === $channel ? 'sale_online' : 'sale',
							'ref_type' => 'order',
							'ref_id'   => $order->get_id(),
							'channel'  => $channel,
						]
					);
					$total_cogs = bcadd( $total_cogs, $fifo['total_cost'], 4 );

					// P7.5 — the goods already left the shop (consume_fifo()
					// decided that, not this call); this only writes the row a
					// manager reconciles. See Stock\Oversell's own docblock.
					if ( ! empty( $fifo['flagged_short'] ) ) {
						Oversell::record( $e['product_id'], $e['variation_id'], $location_id, $order->get_id(), $register_id, $fifo );
					}
				}

				// P2.8: frozen here, at the moment of sale — a later cost
				// change (a new batch, a corrected _purchase_price) must
				// never rewrite what this order's margin was actually based
				// on. Persisted whenever the caller next saves $order (every
				// existing caller already does, right after apply_stock()).
				$order->update_meta_data( '_cntr_cogs', $total_cogs );

				// P5.1 — same transaction, same moment the stock moves above
				// are written. record_sale() also tags the order with where
				// this landed (_cntr_rollup_*), for on_order_edited() and any
				// later reversal to find.
				\Counter\Reports\Rollup::record_sale( $order, $channel, $location_id, $total_cogs );

				return true;
			}
		);
	}

	/**
	 * Mirrors apply_stock() with UPDATE ... SET state='reverted' WHERE
	 * order_id=%d AND refund_id=0 AND state='applied', using rows_affected as
	 * the same kind of guard. $line_items, when given, restricts which order
	 * items are reverted (a partial return); null reverts every managed line.
	 */
	public static function revert_stock( \WC_Order $order, ?array $line_items = null ): bool {
		return Db::transaction(
			function () use ( $order, $line_items ) {
				global $wpdb;
				$t = Install::table( 'order_stock_state' );

				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$t} SET state = 'reverted', reverted_at = %s WHERE order_id = %d AND refund_id = 0 AND state = 'applied'",
						Db::now(),
						$order->get_id()
					)
				);
				if ( 0 === $wpdb->rows_affected ) {
					return false; // never applied, or already reverted
				}

				$location_id = self::location_for( $order );
				$channel     = (string) $order->get_meta( '_cntr_channel' ) ?: 'online';
				$cost_restored = '0.0000';

				foreach ( $order->get_items() as $item ) {
					if ( null !== $line_items && ! in_array( $item->get_id(), $line_items, true ) ) {
						continue;
					}
					$e = Entity::for_item( $item );
					if ( ! $e || ! $e['managed'] ) {
						continue;
					}

					$qty = self::line_qty( $item );
					$cost_restored = bcadd( $cost_restored, self::restore_to_batches( $e['product_id'], $e['variation_id'], $location_id, $qty, $order->get_id(), $channel ), 4 );
				}

				// P5.1 — no real WC refund object exists for a plain
				// cancellation, so the order's own frozen total IS the
				// "amount" being un-recognised — the same accounting
				// treatment a full refund would get. Correct for every
				// REAL caller today, which always reverts the whole order
				// ($line_items is always null) — a future partial-line
				// caller would need the line-level amount here instead,
				// not the whole order's total.
				\Counter\Reports\Rollup::record_reversal( $channel, $location_id, wc_format_decimal( $order->get_total(), 4 ), $cost_restored );

				return true;
			}
		);
	}

	/**
	 * Restocks a SPECIFIC refund (P1.15) — distinct from revert_stock() above,
	 * which reverts the WHOLE original sale (refund_id=0, e.g. an order
	 * cancellation) and is keyed by an UPDATE against a row apply_stock()
	 * already created. A partial refund has no such pre-existing row — each
	 * one gets its own INSERT-guarded row, keyed by (order_id, refund_id),
	 * exactly the reason §5.2's schema comment gives for refund_id being part
	 * of that table's primary key: "a partial refund would consume the
	 * order's single guard row [if keyed on order_id alone]."
	 *
	 * Quantities come from the REFUND's own line items (negative — what was
	 * taken back), not the parent order's, since a partial refund's quantity
	 * lives only on the refund object.
	 */
	public static function apply_refund_reversion( \WC_Order $order, \WC_Order_Refund $refund ): bool {
		return Db::transaction(
			function () use ( $order, $refund ) {
				global $wpdb;
				$t         = Install::table( 'order_stock_state' );
				$refund_id = $refund->get_id();

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$t} (order_id, refund_id, state, applied_at)
						 VALUES (%d, %d, 'applied', %s)
						 ON DUPLICATE KEY UPDATE order_id = order_id",
						$order->get_id(),
						$refund_id,
						Db::now()
					)
				);
				if ( 0 === $wpdb->rows_affected ) {
					return false; // this refund was already processed
				}

				$location_id = self::location_for( $order );
				$channel     = (string) $order->get_meta( '_cntr_channel' ) ?: 'online';
				$cost_restored = '0.0000';

				foreach ( $refund->get_items( 'line_item' ) as $item ) {
					$qty = abs( (float) $item->get_quantity() ); // refund items carry NEGATIVE quantities
					if ( $qty <= 0 ) {
						continue;
					}
					$product = $item->get_product();
					if ( ! $product ) {
						continue;
					}
					$e = Entity::resolve( $product );
					if ( ! $e['managed'] ) {
						continue;
					}

					$cost_restored = bcadd( $cost_restored, self::restore_to_batches( $e['product_id'], $e['variation_id'], $location_id, (string) $qty, $order->get_id(), $channel ), 4 );
				}

				// P5.1 — this specific refund's own amount, scoped to just
				// its own lines — never the whole order's total, unlike
				// revert_stock()'s own plain-cancellation case above.
				\Counter\Reports\Rollup::record_reversal( $channel, $location_id, wc_format_decimal( abs( (float) $refund->get_amount() ), 4 ), $cost_restored );

				return true;
			}
		);
	}

	/**
	 * P2.12 — confirmed live against the installed WooCommerce:
	 * wc_stock_amount( '1.5' ) === 1. WC_Order_Item_Product's own quantity
	 * setter silently rounds to a whole number, so a fractional
	 * allow_decimal=1 unit sale (Orders\Builder writes '_cntr_base_qty' meta
	 * for exactly this reason) cannot trust $item->get_quantity() to carry
	 * its real amount. Prefers that meta when present; every line built
	 * without a unit_id (i.e. every line before this task, and every whole-
	 * number-unit line since) has no such meta and behaves exactly as
	 * before. See docs/verified-api.md.
	 */
	private static function line_qty( \WC_Order_Item $item ): string {
		$base_qty = $item->get_meta( '_cntr_base_qty' );
		if ( '' !== $base_qty && null !== $base_qty ) {
			return wc_format_decimal( $base_qty, 4 );
		}
		return (string) abs( (float) $item->get_quantity() );
	}

	private static function location_for( \WC_Order $order ): int {
		$location_id = (int) $order->get_meta( '_cntr_location_id' );
		return $location_id ?: Locations::default_id();
	}

	/**
	 * P2.8 — "a return restores to the batch it came from," including a
	 * multi-batch sale and a partial return: walks this order's own ORIGINAL
	 * sale-reason moves for this product, oldest first (the same order they
	 * were consumed in), crediting each one back up to how much of IT
	 * specifically hasn't already been restored by an earlier partial
	 * return — tracked by summing prior 'return' moves against that exact
	 * batch_id for this order, not a shared running total, so several
	 * partial returns against a multi-batch sale can never double-restore
	 * one batch while leaving another under-restored.
	 *
	 * A sale move with batch_id = 0 (Batches::consume_fifo()'s own "ran out,
	 * costed at last-known" branch) has no real lot to credit — restored as
	 * a plain ledger move instead, same cost, no batch touched.
	 */
	/** @return string total cost restored (qty x unit_cost, summed) — Reports\Rollup's own cogs delta. */
	private static function restore_to_batches( int $product_id, int $variation_id, int $location_id, string $qty_to_restore, int $order_id, string $channel ): string {
		global $wpdb;
		$moves_table  = Install::table( 'stock_moves' );
		$cost_restored = '0.0000';

		$sale_moves = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, batch_id, qty_delta, unit_cost FROM {$moves_table}
				 WHERE ref_type = 'order' AND ref_id = %d AND product_id = %d AND variation_id = %d
				 AND reason IN ('sale','sale_online') ORDER BY id ASC",
				$order_id,
				$product_id,
				$variation_id
			),
			ARRAY_A
		);

		$remaining = wc_format_decimal( $qty_to_restore, 4 );

		foreach ( $sale_moves as $move ) {
			if ( bccomp( $remaining, '0', 4 ) <= 0 ) {
				break;
			}

			$taken = bcmul( $move['qty_delta'], '-1', 4 ); // sale moves are negative; this is how much THIS move originally took
			$batch_id = (int) $move['batch_id'];

			$already_restored = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(qty_delta),0) FROM {$moves_table}
					 WHERE ref_type = 'order' AND ref_id = %d AND reason = 'return' AND batch_id = %d",
					$order_id,
					$batch_id
				)
			);

			$available = bcsub( $taken, $already_restored, 4 );
			if ( bccomp( $available, '0', 4 ) <= 0 ) {
				continue;
			}

			$take = bccomp( $available, $remaining, 4 ) <= 0 ? $available : $remaining;

			if ( $batch_id > 0 ) {
				Batches::restore(
					$batch_id,
					$take,
					[ 'ref_type' => 'order', 'ref_id' => $order_id, 'channel' => $channel ]
				);
			} else {
				Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'location_id'  => $location_id,
						'qty_delta'    => $take,
						'unit_cost'    => $move['unit_cost'],
						'batch_id'     => 0,
						'reason'       => 'return',
						'ref_type'     => 'order',
						'ref_id'       => $order_id,
						'channel'      => $channel,
					]
				);
			}

			$cost_restored = bcadd( $cost_restored, bcmul( $take, $move['unit_cost'], 4 ), 4 );
			$remaining     = bcsub( $remaining, $take, 4 );
		}

		// Should not happen in normal operation (can't return more than was
		// ever sold) — but data can drift (a sale that predates P2.8, an
		// exchange, manual correction) and the units returning to the shop
		// must never be silently lost for want of a traceable original move.
		if ( bccomp( $remaining, '0', 4 ) > 0 ) {
			$fallback_cost = Batches::last_known_cost( $product_id, $variation_id );
			Ledger::move(
				[
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'location_id'  => $location_id,
					'qty_delta'    => $remaining,
					'unit_cost'    => $fallback_cost,
					'batch_id'     => 0,
					'reason'       => 'return',
					'ref_type'     => 'order',
					'ref_id'       => $order_id,
					'channel'      => $channel,
					'note'         => __( 'Restored beyond any traceable original sale move for this order.', 'counter' ),
				]
			);
			$cost_restored = bcadd( $cost_restored, bcmul( $remaining, $fallback_cost, 4 ), 4 );
		}

		Publisher::publish( $product_id, $variation_id );
		return $cost_restored;
	}
}
