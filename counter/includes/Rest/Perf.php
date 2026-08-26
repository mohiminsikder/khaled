<?php
namespace Counter\Rest;

use Counter\Admin\Screens\Perf as PerfScreen;

defined( 'ABSPATH' ) || exit;

/**
 * P1.18 — receives the terminal's own once-a-day p50/p95 summary
 * (assets/pos.js keeps the last 500 raw timings in localStorage and posts a
 * rollup, never the raw log itself, once per calendar day per till).
 */
class Perf {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/perf',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'date'    => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'summary' => [ 'required' => true, 'type' => 'object' ],
				],
			]
		);
	}

	public static function handle( \WP_REST_Request $req ) {
		$date    = (string) $req->get_param( 'date' );
		$summary = (array) $req->get_param( 'summary' );

		PerfScreen::record_daily_summary( $date, $summary );

		return rest_ensure_response( [ 'stored' => true ] );
	}
}
