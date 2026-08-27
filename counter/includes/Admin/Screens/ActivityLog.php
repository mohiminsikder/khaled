<?php
namespace Counter\Admin\Screens;

use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * D5 — Audit::log() has a reader. A plain paged table over Audit::query()'s
 * own SQL-level LIMIT/OFFSET (not another ListTable subclass loading the
 * whole table into memory — cntr_audit_log has no natural upper bound the
 * way a shop's own locations or registers do).
 */
class ActivityLog {

	const CHIP_STYLE = 'display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;background:#f0f0f1;border:1px solid #dcdcde;';

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Activity Log', 'counter' ),
			__( 'Activity Log', 'counter' ),
			'cntr_view_audit',
			'counter-activity-log',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_audit' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$args = [
			'user_id' => isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
			'action'  => isset( $_GET['action_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['action_filter'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'from'    => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'to'      => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'page'    => isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page' => 20,
		];

		$result = Audit::query( $args );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Activity Log', 'counter' ) . '</h1>';

		self::render_filters( $args );
		self::render_table( $result['rows'] );
		self::render_pagination( $args, $result['total'] );

		echo '</div>';
	}

	private static function render_filters( array $args ): void {
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="counter-activity-log">';

		echo '<select name="user_id">';
		printf( '<option value="0">%s</option>', esc_html__( 'All actors', 'counter' ) );
		foreach ( get_users( [ 'fields' => [ 'ID', 'display_name' ] ] ) as $u ) {
			printf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( $args['user_id'], (int) $u->ID, false ), esc_html( $u->display_name ) );
		}
		echo '</select> ';

		echo '<select name="action_filter">';
		printf( '<option value="">%s</option>', esc_html__( 'All actions', 'counter' ) );
		foreach ( Audit::distinct_actions() as $a ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $a ), selected( $args['action'], $a, false ) );
		}
		echo '</select> ';

		printf( '<input type="date" name="from" value="%s"> ', esc_attr( $args['from'] ) );
		printf( '<input type="date" name="to" value="%s"> ', esc_attr( $args['to'] ) );

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Filter', 'counter' ) . '</button>';
		echo '</form>';
	}

	private static function render_table( array $rows ): void {
		echo '<table class="widefat striped" style="margin-top:16px;"><thead><tr>';
		foreach ( [ __( 'When', 'counter' ), __( 'Actor', 'counter' ), __( 'Action', 'counter' ), __( 'Subject', 'counter' ), __( 'Resulting state', 'counter' ) ] as $h ) {
			printf( '<th>%s</th>', esc_html( $h ) );
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No matching activity.', 'counter' ) . '</td></tr>';
		}

		foreach ( $rows as $r ) {
			$user     = $r['user_id'] ? get_userdata( $r['user_id'] ) : false;
			$operator = $r['operator_id'] ? \Counter\People\Employees::get( $r['operator_id'] ) : null;
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $r['created_at'] ) );
			printf(
				'<td>%s</td>',
				esc_html( $user ? $user->display_name : __( 'System', 'counter' ) ) . ( $operator ? ' <span style="' . self::CHIP_STYLE . '">' . esc_html( $operator['code'] ) . '</span>' : '' )
			);
			printf( '<td><span style="%s">%s</span></td>', self::CHIP_STYLE, esc_html( $r['action'] ) );
			printf( '<td><span style="%s">%s #%d</span></td>', self::CHIP_STYLE, esc_html( $r['object_type'] ), $r['object_id'] );
			printf( '<td><code style="font-size:11px;">%s</code></td>', esc_html( self::summarize( $r['after'] ?? $r['before'] ?? null ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/** A compact, single-line summary of a before/after JSON blob — the full value on hover via the title attribute would be nicer, kept simple here. */
	private static function summarize( $value ): string {
		if ( null === $value ) {
			return '—';
		}
		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) ) {
			return '—';
		}
		return strlen( $json ) > 120 ? substr( $json, 0, 117 ) . '…' : $json;
	}

	private static function render_pagination( array $args, int $total ): void {
		$per_page    = (int) $args['per_page'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<p class="tablenav-pages" style="margin-top:12px;">';
		printf(
			/* translators: 1: current page, 2: total pages, 3: total rows */
			esc_html__( 'Page %1$d of %2$d (%3$d total)', 'counter' ),
			(int) $args['page'],
			$total_pages,
			$total
		);
		echo ' ';
		if ( $args['page'] > 1 ) {
			printf( '<a class="button" href="%s">%s</a> ', esc_url( add_query_arg( 'paged', $args['page'] - 1 ) ), esc_html__( '« Previous', 'counter' ) );
		}
		if ( $args['page'] < $total_pages ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( add_query_arg( 'paged', $args['page'] + 1 ) ), esc_html__( 'Next »', 'counter' ) );
		}
		echo '</p>';
	}
}
