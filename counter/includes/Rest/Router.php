<?php
namespace Counter\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * One namespace, one place that decides who may call what. Individual controllers
 * (Sale, Catalog, Shift, ...) register their routes on the cntr_register_routes
 * action rather than Router knowing their names — the first controller lands in
 * P1, this task only builds the namespace and the guard every route will use.
 */
class Router {

	const NS = 'counter/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function register_routes(): void {
		do_action( 'cntr_register_routes', self::NS );
	}

	/**
	 * Central guard factory. Every route uses one of these; none writes its own.
	 *
	 * On nonces, precisely: WordPress already enforces the wp_rest nonce for
	 * cookie-authenticated REST requests via rest_cookie_check_errors — a cookie
	 * request without a valid X-WP-Nonce fails before this callback ever runs. Do
	 * NOT hand-roll a second nonce check here — it would break the terminal, which
	 * authenticates with an application password and legitimately carries no
	 * nonce. Capability checks plus the register binding are the layer Counter
	 * adds on top of what core already enforces.
	 */
	public static function guard( string $cap, bool $needs_register = false ): callable {
		return static function ( \WP_REST_Request $req ) use ( $cap, $needs_register ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'cntr_auth', __( 'Not signed in.', 'counter' ), [ 'status' => 401 ] );
			}
			if ( ! current_user_can( $cap ) ) {
				return new \WP_Error( 'cntr_cap', __( 'Not permitted.', 'counter' ), [ 'status' => 403 ] );
			}
			if ( $needs_register && ! \Counter\Pos\Registers::valid_for_user( $req->get_header( 'X-CNTR-Register' ) ) ) {
				return new \WP_Error( 'cntr_register', __( 'Unknown register.', 'counter' ), [ 'status' => 403 ] );
			}
			return true;
		};
	}

	/**
	 * Accepts ANY of the given capabilities, not all. Exists for routes the
	 * cntr_terminal machine account itself must reach (the catalogue feed, for
	 * one) — that role deliberately has only cntr_terminal_access and nothing
	 * else (P0.4), while a directly-logged-in human reaches the same route via
	 * an operational capability like cntr_use_pos. Until P6.1's PIN system
	 * exists to authorize the actual cashier, the machine account's own
	 * capability is what stands in for "this is a legitimate terminal request."
	 */
	public static function guard_any( array $caps, bool $needs_register = false ): callable {
		return static function ( \WP_REST_Request $req ) use ( $caps, $needs_register ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'cntr_auth', __( 'Not signed in.', 'counter' ), [ 'status' => 401 ] );
			}
			$allowed = false;
			foreach ( $caps as $cap ) {
				if ( current_user_can( $cap ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return new \WP_Error( 'cntr_cap', __( 'Not permitted.', 'counter' ), [ 'status' => 403 ] );
			}
			if ( $needs_register && ! \Counter\Pos\Registers::valid_for_user( $req->get_header( 'X-CNTR-Register' ) ) ) {
				return new \WP_Error( 'cntr_register', __( 'Unknown register.', 'counter' ), [ 'status' => 403 ] );
			}
			return true;
		};
	}
}
