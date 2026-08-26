<?php
namespace Counter\Admin\Screens;

defined( 'ABSPATH' ) || exit;

/**
 * P1.18 — the budget is measured, not asserted. Each till posts one p50/p95
 * summary per calendar day (POST /counter/v1/perf, from its own last-500
 * local log — see assets/pos.js); this stores the rollups, keyed by date,
 * and shows them. No new table: this task's own schema is "none", and a
 * capped option is enough for a rolling window of daily summaries — the raw
 * per-event timings never leave the till at all.
 */
class Perf {

	const OPTION   = 'cntr_perf_daily';
	const MAX_DAYS = 90;

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Performance', 'counter' ),
			__( 'Performance', 'counter' ),
			'cntr_view_reports',
			'counter-perf',
			[ self::class, 'render' ]
		);
	}

	public static function record_daily_summary( string $date, array $summary ): void {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return;
		}
		$store = self::all();
		$store[ $date ] = $summary;
		krsort( $store );
		$store = array_slice( $store, 0, self::MAX_DAYS, true );
		update_option( self::OPTION, $store, false );
	}

	public static function all(): array {
		$store = get_option( self::OPTION, [] );
		return is_array( $store ) ? $store : [];
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_reports' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Performance', 'counter' ) . '</h1>';
		echo '<p>' . esc_html__( 'Client-side terminal timings, posted once per day from each till. Targets: lookup p95 < 5ms, add-line < 16ms, POST round-trip p95 < 400ms, scan-to-receipt < 10s for a twenty-item basket.', 'counter' ) . '</p>';

		$days = self::all();
		if ( empty( $days ) ) {
			echo '<p>' . esc_html__( 'No terminal has posted a daily summary yet.', 'counter' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Lookup p50 / p95 (ms)', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Add line p50 / p95 (ms)', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'POST round-trip p50 / p95 (ms)', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Scan-to-receipt p50 / p95 (ms)', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Samples', 'counter' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $days as $date => $summary ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $date ) . '</td>';
			echo '<td>' . esc_html( self::fmt( $summary, 'lookup' ) ) . '</td>';
			echo '<td>' . esc_html( self::fmt( $summary, 'add_line' ) ) . '</td>';
			echo '<td>' . esc_html( self::fmt( $summary, 'post' ) ) . '</td>';
			echo '<td>' . esc_html( self::fmt( $summary, 'total' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $summary['count'] ?? 0 ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	private static function fmt( array $summary, string $key ): string {
		$p50 = $summary[ $key ]['p50'] ?? null;
		$p95 = $summary[ $key ]['p95'] ?? null;
		if ( null === $p50 && null === $p95 ) {
			return '—';
		}
		return round( (float) $p50, 1 ) . ' / ' . round( (float) $p95, 1 );
	}
}
