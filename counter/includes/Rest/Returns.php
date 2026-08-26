<?php
namespace Counter\Rest;

use Counter\Orders\Refunds;

defined( 'ABSPATH' ) || exit;

class Returns {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/returns',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => Router::guard_any( [ 'cntr_refund', 'cntr_terminal_access' ], true ),
				'args'                => [
					'order_id'          => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'shift_id'          => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'amount'            => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'line_items'        => [
						'required' => true,
						'type'     => 'object',
					],
					'reason'            => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'tenders'           => [
						'required' => true,
						'type'     => 'array',
					],
					'exchange_group_id' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public static function handle( \WP_REST_Request $req ) {
		$order_id = (int) $req->get_param( 'order_id' );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'cntr_return_no_order', __( 'Order not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$line_items = (array) $req->get_param( 'line_items' );
		$line_items = array_combine( array_map( 'intval', array_keys( $line_items ) ), array_values( $line_items ) );

		$result = Refunds::process(
			$order,
			(string) $req->get_param( 'amount' ),
			$line_items,
			(string) $req->get_param( 'reason' ),
			(int) $req->get_param( 'shift_id' ),
			(array) $req->get_param( 'tenders' ),
			$req->get_param( 'exchange_group_id' ) ?: null
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( [ 'refunded' => true, 'order_id' => $order_id ] );
	}
}
