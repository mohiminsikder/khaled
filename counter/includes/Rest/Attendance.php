<?php
namespace Counter\Rest;

use Counter\Pos\Pin;
use Counter\People\Attendance as AttendanceClass;

defined( 'ABSPATH' ) || exit;

/**
 * P6.2 — clock in/out FROM THE TERMINAL, which means identified by PIN,
 * the same mechanism P6.1 already built, not a bare employee_id a client
 * could simply supply. Reuses Pin::switch_operator() as-is (no changes to
 * that already-shipped file): resolving who just entered their PIN and
 * making them "the current operator" for this register is exactly what
 * clocking in at that register also naturally means.
 */
class Attendance {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		$args = [
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
		];

		register_rest_route(
			$ns,
			'/attendance/clock-in',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'clock_in' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => $args,
			]
		);

		register_rest_route(
			$ns,
			'/attendance/clock-out',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'clock_out' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => $args,
			]
		);
	}

	private static function identify( \WP_REST_Request $req ) {
		return Pin::switch_operator( (int) $req->get_param( 'register_id' ), (string) $req->get_param( 'pin' ) );
	}

	public static function clock_in( \WP_REST_Request $req ) {
		$identity = self::identify( $req );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$result = AttendanceClass::clock_in( $identity['operator_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'attendance_id' => $result, 'operator_id' => $identity['operator_id'] ] );
	}

	public static function clock_out( \WP_REST_Request $req ) {
		$identity = self::identify( $req );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$result = AttendanceClass::clock_out( $identity['operator_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'clocked_out' => true, 'operator_id' => $identity['operator_id'] ] );
	}
}
