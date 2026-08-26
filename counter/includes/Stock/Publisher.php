<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;
use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's _stock always reflects what the shop is willing to sell online,
 * and never lags a counter sale.
 *
 *   published = SUM( cntr_stock.qty WHERE location is online_sellable )
 *             − reserved ( WooCommerce's own reservation table, P1.6 )
 *             − settings: stock.online_buffer
 *
 * Written with wc_update_product_stock( $product, $sellable, 'set' ) — a
 * targeted update, not a full $product->save(), which would fire the whole
 * product save machinery on every sale.
 *
 * Do NOT filter woocommerce_product_get_stock_quantity to compute this on the
 * fly. That filter runs on every product in every archive query and will
 * destroy storefront performance on a large catalogue — the trap most
 * multi-location plugins fall into.
 */
class Publisher {

	/**
	 * Reentrancy guard. $fresh->save() below fires woocommerce_update_product,
	 * which the catalogue indexer (P1.7) listens to. The indexer bumps the
	 * revision but must NOT call back into publish() — check is_publishing()
	 * before doing anything that could. Without this, that listener recurses.
	 */
	private static bool $publishing = false;

	public static function is_publishing(): bool {
		return self::$publishing;
	}

	/**
	 * On 'set' here, and why it does not contradict Invariant II: Invariant II
	 * governs BALANCE writes. This is a projection of the balance, recomputed
	 * under SELECT ... FOR UPDATE inside the same transaction as the move that
	 * caused it. That lock is what makes it safe: without it, A reads 9, B reads
	 * 8, B sets 8, A sets 9, and the website advertises a unit that left the
	 * shop ten seconds ago. MUST be called from inside the same Db::transaction()
	 * as the ledger move that changed the balance — moving this call outside
	 * the transaction reintroduces exactly that lost-update bug. Do not.
	 */
	public static function publish( int $product_id, int $variation_id = 0 ): void {
		global $wpdb;

		// Matches Entity::ledger_key()'s collapse: variation_id = 0 means the
		// balance (and therefore the id WooCommerce needs) lives on product_id.
		$stock_id = $variation_id ?: $product_id;

		$balance = self::locked_online_balance( $product_id, $variation_id );

		$reserved = class_exists( '\Counter\Stock\Reserve' )
			? \Counter\Stock\Reserve::reserved_for( $stock_id )
			: '0';
		$buffer = (string) Settings::get( 'stock.online_buffer', 0 );

		$sellable = bcsub( bcsub( $balance, $reserved, 4 ), $buffer, 4 );
		if ( bccomp( $sellable, '0', 4 ) < 0 ) {
			// Never publish a negative number — offline sales can legitimately
			// drive the real balance negative (P7.5); a negative _stock makes
			// WooCommerce's own arithmetic behave unpredictably.
			$sellable = '0';
		}

		$product = wc_get_product( $stock_id );
		if ( ! $product ) {
			return;
		}

		self::$publishing = true;
		try {
			wc_update_product_stock( $product, $sellable, 'set' );

			// Do not assume wc_update_product_stock() reconciles stock_status for
			// you across versions — reconcile explicitly.
			$fresh  = wc_get_product( $stock_id );
			$status = $fresh->backorders_allowed()
				? 'onbackorder'
				: ( bccomp( $sellable, '0', 4 ) > 0 ? 'instock' : 'outofstock' );

			if ( $fresh->get_stock_status() !== $status ) {
				$fresh->set_stock_status( $status );
				$fresh->save();
			}

			// Every publish is a catalogue change even when nothing about the
			// product itself changed — sellable_qty is part of the payload.
			Catalog::reindex( wc_get_product( $stock_id ) );
		} finally {
			self::$publishing = false;
		}

		// Cache coherency on rollback: $fresh->save() (and wc_update_product_stock
		// itself) write through WooCommerce's object cache, which does not
		// participate in the SQL transaction. If the enclosing transaction rolls
		// back, the database reverts but the cache keeps serving the value that
		// never actually landed — so register cleanup for exactly that case.
		Db::on_rollback(
			static function () use ( $stock_id ) {
				wc_delete_product_transients( $stock_id );
				clean_post_cache( $stock_id );
			}
		);
	}

	/**
	 * SUM(qty) across every online-sellable location, taken under FOR UPDATE so
	 * the whole publish() computation is safe against a concurrent sale — see
	 * the class docblock.
	 */
	private static function locked_online_balance( int $product_id, int $variation_id ): string {
		global $wpdb;

		$sellable_ids = Locations::online_sellable_ids();
		if ( empty( $sellable_ids ) ) {
			return '0.0000';
		}

		$stock_table  = Install::table( 'stock' );
		$placeholders = implode( ',', array_fill( 0, count( $sellable_ids ), '%d' ) );

		$rows = Db::for_update(
			"SELECT qty FROM {$stock_table} WHERE product_id = %d AND variation_id = %d AND location_id IN ({$placeholders})",
			$product_id,
			$variation_id,
			...$sellable_ids
		);

		$total = '0.0000';
		foreach ( $rows as $row ) {
			$total = bcadd( $total, (string) $row['qty'], 4 );
		}
		return $total;
	}
}
