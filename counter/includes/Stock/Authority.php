<?php
namespace Counter\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Invariant III — Counter is the only thing that moves stock.
 *
 * WooCommerce reduces stock itself: wc_maybe_reduce_stock_levels() is hooked to
 * woocommerce_payment_complete and to the completed/processing/on-hold transitions,
 * guarded by the _order_stock_reduced order meta; wc_maybe_increase_stock_levels()
 * mirrors it on cancelled/pending (and, confirmed live against WC 11.0.1, also on
 * failed — see docs/verified-api.md item 1).
 *
 * Left alone, a POS sale decrements twice — once through our ledger and write-through,
 * once through WooCommerce's hook — and which one wins depends on hook order. And an
 * online sale decrements WooCommerce stock while writing no ledger row at all, which
 * makes the ledger incomplete and every number built on it wrong: rebuild, FIFO cost,
 * valuation, margin.
 *
 * These two filters are the documented extension point and are preferred to
 * remove_action(), which is version-fragile. test_wc_stock_authority() proves at
 * runtime that they actually took effect, so a WooCommerce change that renames or
 * bypasses them fails the suite instead of corrupting the shop's stock quietly.
 */
class Authority {

	public static function init(): void {
		add_action(
			'init',
			function () {
				add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 99 );
				add_filter( 'woocommerce_can_restore_order_stock', '__return_false', 99 );
			},
			5
		);

		add_action( 'admin_init', [ self::class, 'warn_if_filters_missing' ] );
	}

	public static function active(): bool {
		return (bool) has_filter( 'woocommerce_can_reduce_order_stock' )
			&& (bool) has_filter( 'woocommerce_can_restore_order_stock' );
	}

	/**
	 * A plugin conflict that removes these filters (a naive remove_all_filters()
	 * call, a theme cleanup snippet) must be visible, not silent — silently letting
	 * WooCommerce start double-decrementing stock again is exactly the failure this
	 * whole task exists to prevent.
	 */
	public static function warn_if_filters_missing(): void {
		if ( self::active() ) {
			return;
		}
		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__(
						'Counter: the WooCommerce stock-authority filters are missing. Something removed them — stock may now be double-decremented. Check Counter → Health.',
						'counter'
					)
				);
			}
		);
	}
}
