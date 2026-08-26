<?php
namespace Counter\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Audit finding B3. WooCommerce already reserves stock for unpaid orders in
 * wp_wc_reserved_stock via wc_reserve_stock_for_order(), governed by the "Hold
 * stock (minutes)" setting. During that window _stock still reads 1 while the
 * last unit is spoken for — the storefront honours it; a terminal reading a
 * cached qty does not, and hands over a unit somebody paid for forty seconds
 * ago.
 *
 * Do NOT build a second reservation system. Two reservation systems that do
 * not know about each other are strictly worse than either alone — this reads
 * WooCommerce's own table rather than duplicating it.
 *
 * cntr_stock.reserved is a DIFFERENT thing entirely: units committed to an
 * in-transit internal transfer (P2.1). Never conflate the two.
 */
class Reserve {

	/**
	 * $stock_id is the stock-HOLDING id — Entity::resolve()'s stock_id, the
	 * variation id where the variation manages its own stock, matching what
	 * WooCommerce itself writes product_id as in wp_wc_reserved_stock.
	 */
	public static function reserved_for( int $stock_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_reserved_stock';

		$qty = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM(stock_quantity), 0 ) FROM {$table} WHERE product_id = %d AND `expires` > UTC_TIMESTAMP()",
				$stock_id
			)
		);

		return null === $qty ? '0' : (string) $qty;
	}
}
