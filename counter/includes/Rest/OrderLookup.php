<?php
namespace Counter\Rest;

use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * P8.5 — the missing half of the return flow Direction's own P1 receipt
 * design already assumed existed: a cashier only ever has the printed
 * receipt_no in hand, never an order_id, so a return has to start from a
 * lookup neither /sale nor /returns itself provides. GET, not POST — this
 * changes nothing, it only reads.
 */
class OrderLookup {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/order-lookup',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => Router::guard_any( [ 'cntr_refund', 'cntr_terminal_access' ], true ),
				'args'                => [
					'receipt_no' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public static function handle( \WP_REST_Request $req ) {
		global $wpdb;
		$receipt_no = (string) $req->get_param( 'receipt_no' );

		// 'sale' only — a return is never itself the starting point of
		// another return; the original sale's own receipt_no is the only
		// valid lookup key a cashier could actually be holding.
		$table    = Install::table( 'shift_sales' );
		$order_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT order_id FROM {$table} WHERE receipt_no = %s AND kind = 'sale'", $receipt_no )
		);
		if ( ! $order_id ) {
			return new \WP_Error( 'cntr_order_lookup_not_found', __( 'No sale found for that receipt number.', 'counter' ), [ 'status' => 404 ] );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'cntr_order_lookup_no_order', __( 'That receipt\'s order no longer exists.', 'counter' ), [ 'status' => 404 ] );
		}

		$lines = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$qty          = (float) $item->get_quantity();
			// WC_Order::get_qty_refunded_for_item() is always <= 0 by its
			// own convention (a refund is a negative quantity); this is the
			// one place that sign is inverted, so nothing downstream (the
			// return picker's own "how much is left" arithmetic) has to
			// re-remember it.
			$refunded_qty = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
			$unit_price   = $qty > 0 ? wc_format_decimal( (float) $item->get_total() / $qty, 4 ) : '0.0000';

			$lines[] = [
				'item_id'       => $item_id,
				'product_id'    => $item->get_product_id(),
				'variation_id'  => $item->get_variation_id(),
				'name'          => $item->get_name(),
				'qty'           => wc_format_decimal( $qty, 4 ),
				'refunded_qty'  => wc_format_decimal( $refunded_qty, 4 ),
				'remaining_qty' => wc_format_decimal( max( 0, $qty - $refunded_qty ), 4 ),
				'unit_price'    => $unit_price,
			];
		}

		return rest_ensure_response(
			[
				'order_id'   => $order->get_id(),
				'receipt_no' => $receipt_no,
				'total'      => wc_format_decimal( $order->get_total(), 4 ),
				'lines'      => $lines,
			]
		);
	}
}
