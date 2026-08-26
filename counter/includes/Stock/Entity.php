<?php
namespace Counter\Stock;

defined( 'ABSPATH' ) || exit;

/**
 * Audit finding G5. Three cases break naive code:
 *
 *  - A product with manage_stock OFF — a service, a delivery charge, a made-to-
 *    order item. No ledger row, no publish, no oversell check.
 *  - A variation with manage_stock = 'parent', where stock lives on the PARENT
 *    product id, not the variation id.
 *  - An order line that is not a product at all — a fee, shipping, a coupon.
 *
 * WC_Product::managing_stock() is NOT a clean boolean for a parent-managed
 * variation — it returns the literal string 'parent' (truthy, but not `true`),
 * because WC_Product_Variation never overrides managing_stock() itself, only
 * get_manage_stock(), which can return 'parent'. Comparing against `false` rather
 * than `true` is what makes both cases resolve correctly. get_stock_managed_by_id()
 * IS overridden on WC_Product_Variation and is the authoritative "what id holds
 * this" answer — confirmed directly against the installed WooCommerce source
 * rather than assumed (see docs/verified-api.md and the P1.2 commit).
 */
class Entity {

	/**
	 * @return array{managed:bool,stock_id:int,product_id:int,variation_id:int,backorders:string}
	 */
	public static function resolve( \WC_Product $product ): array {
		$is_variation = $product->is_type( 'variation' );

		return [
			'managed'      => false !== $product->managing_stock(),
			'stock_id'     => $product->get_stock_managed_by_id(),
			'product_id'   => $is_variation ? $product->get_parent_id() : $product->get_id(),
			'variation_id' => $is_variation ? $product->get_id() : 0,
			'backorders'   => (string) $product->get_backorders(),
		];
	}

	/**
	 * Null for fees, shipping, coupons — anything that isn't WC_Order_Item_Product
	 * — and null for a product deleted since the order was placed. Never fatals:
	 * $item->get_product() returns false in that case, and Channel::apply_stock()
	 * runs against historical orders (a webhook replay, a status change on a
	 * month-old order, an offline sale draining after the catalogue was tidied).
	 * The caller skips the line and writes an order note naming the missing
	 * product id; a fatal here would take the till down.
	 */
	public static function for_item( \WC_Order_Item $item ): ?array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return null;
		}

		return self::resolve( $product );
	}

	/**
	 * The (product_id, variation_id) PAIR that actually owns an independent
	 * balance — NOT the same as $entity['product_id']/$entity['variation_id']
	 * verbatim. A parent-managed variation shares ONE balance with every sibling
	 * variation (WooCommerce decrements the parent's own stock for all of them),
	 * so its ledger key collapses to (parent_id, 0). Using that variation's own
	 * id here would silently give it an isolated balance instead of drawing from
	 * the shared pool the storefront actually sells against.
	 *
	 * A simple product and a self-managing variation both already have
	 * stock_id === their own natural key, so this is a no-op for them.
	 */
	public static function ledger_key( array $entity ): array {
		$variation_id = ( $entity['stock_id'] === $entity['product_id'] ) ? 0 : $entity['variation_id'];
		return [ $entity['product_id'], $variation_id ];
	}
}
