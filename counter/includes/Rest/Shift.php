<?php
namespace Counter\Rest;

use Counter\Pos\Shifts;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Shift REST surface: the /current and /open routes the terminal (P1.13)
 * needs to establish context before it can sell anything, plus /close
 * (P1.16), which hands back the built Z-report so the terminal can show it
 * immediately without a second round trip.
 */
class Shift {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/shift/current',
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

		register_rest_route(
			$ns,
			'/shift/open',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'open' ],
				'permission_callback' => Router::guard_any( [ 'cntr_open_shift', 'cntr_terminal_access' ] ),
				'args'                => [
					'register_id'   => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'opening_float' => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '0.00',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$ns,
			'/shift/close',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'close' ],
				'permission_callback' => Router::guard_any( [ 'cntr_close_shift', 'cntr_close_any_shift', 'cntr_terminal_access' ] ),
				'args'                => [
					'shift_id'     => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'counted_cash' => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'denoms'       => [ 'required' => false, 'type' => 'object' ],
					'note'         => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);

		// B5 — the live X-report. Same low bar as /shift/current above
		// (using the till, not closing it) since this is a read-only
		// snapshot; the actual close stays gated separately below.
		// register_id resolves the register's CURRENTLY open shift (the
		// normal, pre-close case — the 💼 icon and the close-preview both
		// use this); shift_id looks up one shift directly regardless of
		// open/closed state, which is what the close flow's own FINAL print
		// needs — by the moment it runs, the shift it wants is the one that
		// was just closed a request ago, so register_id can no longer find
		// it (open_for_register() only ever matches an open shift). Exactly
		// one of the two is required.
		register_rest_route(
			$ns,
			'/shift/x-report',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'x_report' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'register_id' => [
						'required'          => false,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'shift_id'    => [
						'required'          => false,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// F5 (COUNTERFRONTEND.md) — Shifts::record_event() and the 'no_sale'
		// shift-event type both already existed (P1.16's Z-report and P6.5's
		// CashierPerformance both already read them back), but nothing ever
		// wrote one: record_event()'s own docblock says "No REST route calls
		// this yet." F10 is that first caller. Gated on cntr_no_sale at the
		// route itself — no operator without it can even reach the handler,
		// same as /shift/open's cntr_open_shift gate above.
		register_rest_route(
			$ns,
			'/shift/no-sale',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'no_sale' ],
				'permission_callback' => Router::guard( 'cntr_no_sale' ),
				'args'                => [
					'register_id' => [
						'required'          => true,
						'type'              => 'integer',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
					'reason'      => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && '' !== trim( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Writes the shift_events row P1.16's Z-report and P6.5's cashier
	 * performance report both already know how to read, plus the audit row
	 * P0.6 requires for every no-sale — the drawer opens by PRINTING (P1.14:
	 * the printer driver's own "open before/after printing" setting is the
	 * actual kick mechanism), so this call has nothing to do with the paper
	 * itself; the terminal builds and prints that slip client-side, same as
	 * every other receipt-shaped thing it prints.
	 */
	public static function no_sale( \WP_REST_Request $req ) {
		$register_id = (int) $req->get_param( 'register_id' );
		$reason      = (string) $req->get_param( 'reason' );

		// The register's CURRENTLY open shift, resolved server-side — same
		// "never trust the terminal's own possibly-stale shift_id" principle
		// Rest\Sale::process() already applies (its own $is_late handling).
		$shift_id = Shifts::require_open( $register_id );
		if ( is_wp_error( $shift_id ) ) {
			return $shift_id;
		}

		$operator_id = \Counter\Pos\Pin::current_operator( $register_id ) ?: get_current_user_id();

		Shifts::record_event( $shift_id, 'no_sale', '0.0000', $reason, $operator_id );
		Audit::log( 'no_sale', 'shift', $shift_id, null, [ 'reason' => $reason, 'register_id' => $register_id ], $operator_id );

		return rest_ensure_response( [ 'ok' => true ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function x_report( \WP_REST_Request $req ) {
		$shift_id = (int) $req->get_param( 'shift_id' );
		if ( ! $shift_id ) {
			$register_id = (int) $req->get_param( 'register_id' );
			$shift       = $register_id ? Shifts::open_for_register( $register_id ) : null;
			if ( ! $shift ) {
				return new \WP_Error( 'cntr_shift_required', __( 'No open shift on this register.', 'counter' ), [ 'status' => 409 ] );
			}
			$shift_id = (int) $shift['id'];
		}
		$report = Shifts::x_report( $shift_id );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		return rest_ensure_response( $report );
	}

	public static function current( \WP_REST_Request $req ) {
		$register_id = (int) $req->get_param( 'register_id' );
		$shift       = Shifts::open_for_register( $register_id );
		return rest_ensure_response( [ 'shift' => $shift ] );
	}

	public static function open( \WP_REST_Request $req ) {
		$register_id   = (int) $req->get_param( 'register_id' );
		$opening_float = (string) $req->get_param( 'opening_float' );

		$result = Shifts::open( $register_id, get_current_user_id(), $opening_float );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'shift_id' => $result ] );
	}

	/**
	 * Closing your own shift needs cntr_close_shift; closing someone else's
	 * needs cntr_close_any_shift — checked here against the shift's own
	 * user_id rather than at the route level, since the route can't know
	 * which shift is being closed until the request body is parsed.
	 */
	public static function close( \WP_REST_Request $req ) {
		$shift_id = (int) $req->get_param( 'shift_id' );
		$shift    = Shifts::get( $shift_id );
		if ( ! $shift ) {
			return new \WP_Error( 'cntr_shift_missing', __( 'Shift not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$is_own  = (int) $shift['user_id'] === get_current_user_id();
		$allowed = $is_own
			? ( current_user_can( 'cntr_close_shift' ) || current_user_can( 'cntr_terminal_access' ) )
			: current_user_can( 'cntr_close_any_shift' );
		if ( ! $allowed ) {
			return new \WP_Error( 'cntr_shift_close_forbidden', __( 'You are not permitted to close this shift.', 'counter' ), [ 'status' => 403 ] );
		}

		$denoms      = $req->get_param( 'denoms' );
		$denoms_json = ! empty( $denoms ) ? wp_json_encode( $denoms ) : null;
		$note        = (string) $req->get_param( 'note' );

		$result = Shifts::close( $shift_id, (string) $req->get_param( 'counted_cash' ), $denoms_json, '' !== $note ? $note : null );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$report = \Counter\Admin\Screens\ShiftReport::build( $shift_id );
		return rest_ensure_response( [ 'closed' => true, 'report' => $report ] );
	}
}
