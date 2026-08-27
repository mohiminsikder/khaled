<?php
namespace Counter\Admin\Screens;

use Counter\Reports\Reports as ReportsClass;
use Counter\Stock\Locations;
use Counter\Admin\EntityPicker;

defined( 'ABSPATH' ) || exit;

/**
 * D3 — till sessions are auditable. A thin screen over
 * Reports::register_report()'s own per-shift rows, each carrying a nested
 * by_method map — the one reason this is its own screen rather than another
 * entry in the general Reports.php table, which only ever renders a flat row
 * (the same reasoning documented on register_report() itself, and on
 * Reports.php's own 'cashier_performance' branch for an identical shape).
 */
class RegisterReport {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Register Report', 'counter' ),
			__( 'Register Report', 'counter' ),
			'cntr_view_reports',
			'counter-register-report',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$args = [
			'from'        => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d', strtotime( '-7 days' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
			'to'          => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'location_id' => isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'register_id' => isset( $_GET['register_id'] ) ? absint( $_GET['register_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		];

		$location_options = [ [ 'id' => 0, 'label' => __( 'All locations', 'counter' ) ] ];
		$location_label    = __( 'All locations', 'counter' );
		foreach ( Locations::all( 'active' ) as $loc ) {
			$location_options[] = [ 'id' => (int) $loc['id'], 'label' => (string) $loc['name'] ];
			if ( (int) $loc['id'] === $args['location_id'] ) {
				$location_label = (string) $loc['name'];
			}
		}

		$shifts = ReportsClass::register_report( $args );

		// Columns are whichever payment methods actually appear in this
		// result — a shop's own method set (cash, bKash, card, …) rather
		// than a hardcoded list this screen would drift from the moment a
		// new payment account is added.
		$methods = [];
		foreach ( $shifts as $s ) {
			foreach ( array_keys( $s['by_method'] ) as $m ) {
				$methods[ $m ] = true;
			}
		}
		$methods = array_keys( $methods );
		sort( $methods );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Register Report', 'counter' ) . '</h1>';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="counter-register-report">';
		printf( '<input type="date" name="from" value="%s"> ', esc_attr( $args['from'] ) );
		printf( '<input type="date" name="to" value="%s"> ', esc_attr( $args['to'] ) );
		EntityPicker::render(
			[
				'id'          => 'cntr-regrep-location',
				'hidden_name' => 'location_id',
				'type'        => 'location',
				'placeholder' => __( 'Type a location name…', 'counter' ),
				'value'       => $args['location_id'],
				'value_label' => $location_label,
				'options'     => $location_options,
			]
		);
		echo ' <button type="submit" class="button button-primary">' . esc_html__( 'Filter', 'counter' ) . '</button>';
		echo '</form>';

		echo '<table class="widefat striped" style="margin-top:16px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Register', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Cashier', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Opened', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'counter' ) . '</th>';
		foreach ( $methods as $m ) {
			printf( '<th>%s</th>', esc_html( ucfirst( $m ) ) );
		}
		echo '<th>' . esc_html__( 'Transactions', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Tender total', 'counter' ) . '</th>';
		echo '<th>' . esc_html__( 'Drawer variance', 'counter' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $shifts ) ) {
			printf( '<tr><td colspan="%d">%s</td></tr>', 6 + count( $methods ), esc_html__( 'No shifts in this range.', 'counter' ) );
		}

		foreach ( $shifts as $s ) {
			$user = $s['user_id'] ? get_userdata( $s['user_id'] ) : false;
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $s['register_name'] ?: ( '#' . $s['register_id'] ) ) );
			printf( '<td>%s</td>', esc_html( $user ? $user->display_name : __( 'Unknown', 'counter' ) ) );
			printf( '<td>%s</td>', esc_html( $s['opened_at'] ) );
			printf(
				'<td>%s</td>',
				$s['is_open']
					? '<span style="color:#2271b1;font-weight:600;">' . esc_html__( 'Open', 'counter' ) . '</span>'
					: esc_html__( 'Closed', 'counter' ) . ' — ' . esc_html( $s['closed_at'] )
			);
			foreach ( $methods as $m ) {
				$cell = $s['by_method'][ $m ] ?? null;
				printf( '<td>%s</td>', $cell ? wp_kses_post( wc_price( $cell['amount'] ) ) . ' (' . (int) $cell['count'] . ')' : '—' );
			}
			printf( '<td>%d</td>', (int) $s['transaction_count'] );
			printf( '<td>%s</td>', wp_kses_post( wc_price( $s['tender_total'] ) ) );
			printf(
				'<td>%s</td>',
				null === $s['variance']
					? esc_html__( '— not yet counted', 'counter' )
					: ( 0 === bccomp( $s['variance'], '0', 4 ) ? esc_html__( 'Balanced', 'counter' ) : wp_kses_post( wc_price( $s['variance'] ) ) )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
