<?php
namespace Counter\Rest;

use Counter\Pos\Pin as PinClass;

defined( 'ABSPATH' ) || exit;

/**
 * P6.1 — the terminal's own surface for "who is standing here right now."
 * Gated the same way the shift routes already are (Router::guard_any(),
 * the terminal's own credential OR a directly logged-in cashier) — the PIN
 * itself is the layer underneath that, identifying the operator, never a
 * substitute for it.
 */
class Pin {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/pin/switch',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'switch_operator' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'register_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'pin'         => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/pin/current',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'current' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'register_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public static function switch_operator( \WP_REST_Request $req ) {
		$result = PinClass::switch_operator( (int) $req->get_param( 'register_id' ), (string) $req->get_param( 'pin' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public static function current( \WP_REST_Request $req ) {
		$operator_id = PinClass::current_operator( (int) $req->get_param( 'register_id' ) );
		if ( 0 === $operator_id ) {
			return rest_ensure_response( [ 'operator_id' => 0 ] );
		}
		$employee = \Counter\People\Employees::get( $operator_id );
		return rest_ensure_response(
			[
				'operator_id' => $operator_id,
				'code'        => $employee['code'] ?? '',
				'designation' => $employee['designation'] ?? '',
			]
		);
	}
}
