<?php
namespace Counter\Admin\Screens;

use Counter\Install;
use Counter\Settings;
use Counter\Pos\Shifts;

defined( 'ABSPATH' ) || exit;

/**
 * The Z-report (audit finding B4/P1.16). build() is the testable core — every
 * total it returns is independently queried from the rows it also lists, so a
 * bug that makes the two disagree (a filter drifting between the two queries,
 * a join duplicating rows) is visible rather than silently self-consistent.
 * render() is a thin wp-admin wrapper around it.
 */
class ShiftReport {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Z-report', 'counter' ),
			__( 'Z-report', 'counter' ),
			'cntr_view_reports',
			'counter-zreport',
			[ self::class, 'render' ]
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	public static function build( int $shift_id ) {
		global $wpdb;

		$shift = Shifts::get( $shift_id );
		if ( ! $shift ) {
			return new \WP_Error( 'cntr_shift_missing', __( 'Shift not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$shift_sales_table = Install::table( 'shift_sales' );
		$tenders_table      = Install::table( 'tenders' );
		$events_table        = Install::table( 'shift_events' );
		$late_sales_table    = Install::table( 'late_sales' );

		$sales_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$shift_sales_table} WHERE shift_id = %d AND kind = 'sale' ORDER BY id", $shift_id ),
			ARRAY_A
		);
		$sales_total = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$shift_sales_table} WHERE shift_id = %d AND kind = 'sale'", $shift_id )
		);

		$returns_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$shift_sales_table} WHERE shift_id = %d AND kind = 'return' ORDER BY id", $shift_id ),
			ARRAY_A
		);
		$returns_total = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$shift_sales_table} WHERE shift_id = %d AND kind = 'return'", $shift_id )
		);

		$by_channel = [];
		foreach ( $sales_rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			$channel = $order ? ( (string) $order->get_meta( '_cntr_channel' ) ?: 'online' ) : 'unknown';
			if ( ! isset( $by_channel[ $channel ] ) ) {
				$by_channel[ $channel ] = [ 'count' => 0, 'total' => '0.0000' ];
			}
			++$by_channel[ $channel ]['count'];
			$by_channel[ $channel ]['total'] = bcadd( $by_channel[ $channel ]['total'], (string) $row['total'], 4 );
		}

		$tenders_by_method = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT method, COALESCE(SUM(CASE WHEN is_change = 1 THEN -amount ELSE amount END),0) AS net
				 FROM {$tenders_table} WHERE shift_id = %d AND refund_id = 0 GROUP BY method",
				$shift_id
			),
			ARRAY_A
		);
		$tenders_by_method_total = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN is_change = 1 THEN -amount ELSE amount END),0)
				 FROM {$tenders_table} WHERE shift_id = %d AND refund_id = 0",
				$shift_id
			)
		);

		$refunds_by_method = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT method, COALESCE(SUM(amount),0) AS total FROM {$tenders_table}
				 WHERE shift_id = %d AND refund_id > 0 GROUP BY method",
				$shift_id
			),
			ARRAY_A
		);

		$no_sales = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$events_table} WHERE shift_id = %d AND type = 'no_sale' ORDER BY id", $shift_id ),
			ARRAY_A
		);

		$discounts_over_ceiling = self::discounts_over_ceiling( $sales_rows );

		$late_sales = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$late_sales_table} WHERE intended_shift_id = %d OR landed_shift_id = %d ORDER BY id",
				$shift_id,
				$shift_id
			),
			ARRAY_A
		);

		return [
			'shift'                  => $shift,
			'sales'                  => $sales_rows,
			'sales_total'            => $sales_total,
			'returns'                => $returns_rows,
			'returns_total'          => $returns_total,
			'by_channel'             => $by_channel,
			'tenders_by_method'      => $tenders_by_method,
			'tenders_by_method_total' => $tenders_by_method_total,
			'refunds_by_method'      => $refunds_by_method,
			'no_sales'               => $no_sales,
			'discounts_over_ceiling' => $discounts_over_ceiling,
			'late_sales'             => $late_sales,
		];
	}

	/**
	 * Flags order lines whose discount exceeds pos.discount_ceiling_pct. Named
	 * "authorised by" for the operator who rang the sale — Counter has no
	 * separate discount-authorisation capture yet (no terminal UI calls
	 * cntr_discount_line's gate today; see docs/decisions.md), so the only
	 * identity available is whoever completed the order, not a distinct
	 * approving supervisor.
	 */
	private static function discounts_over_ceiling( array $sales_rows ): array {
		$ceiling = (float) Settings::get( 'pos.discount_ceiling_pct' );
		if ( $ceiling <= 0 ) {
			return [];
		}

		$flagged = [];
		foreach ( $sales_rows as $row ) {
			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$subtotal = (float) $item->get_subtotal();
				$total    = (float) $item->get_total();
				if ( $subtotal <= 0 ) {
					continue;
				}
				$pct = ( $subtotal - $total ) / $subtotal * 100;
				if ( $pct > $ceiling ) {
					$flagged[] = [
						'order_id'      => $order->get_id(),
						'line'          => $item->get_name(),
						'discount_pct'  => round( $pct, 2 ),
						'authorised_by' => (int) $order->get_meta( '_cntr_operator_id' ),
					];
				}
			}
		}
		return $flagged;
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_reports' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$shift_id = isset( $_GET['shift_id'] ) ? absint( $_GET['shift_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Z-report', 'counter' ) . '</h1>';

		if ( ! $shift_id ) {
			self::render_picker();
			echo '</div>';
			return;
		}

		$report = self::build( $shift_id );
		if ( is_wp_error( $report ) ) {
			echo '<p style="color:red">' . esc_html( $report->get_error_message() ) . '</p></div>';
			return;
		}

		self::render_report( $report );
		echo '</div>';
	}

	private static function render_picker(): void {
		global $wpdb;
		$table = Install::table( 'shifts' );
		$rows  = $wpdb->get_results( "SELECT id, register_id, closed_at FROM {$table} WHERE closed_at IS NOT NULL ORDER BY id DESC LIMIT 50", ARRAY_A );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No closed shifts yet.', 'counter' ) . '</p>';
			return;
		}

		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Shift', 'counter' ) . '</th><th>' . esc_html__( 'Register', 'counter' ) . '</th><th>' . esc_html__( 'Closed', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$url = add_query_arg( [ 'page' => 'counter-zreport', 'shift_id' => $r['id'] ], admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $url ) . '">#' . esc_html( $r['id'] ) . '</a></td><td>' . esc_html( $r['register_id'] ) . '</td><td>' . esc_html( $r['closed_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_report( array $report ): void {
		$shift = $report['shift'];

		echo '<h2>' . esc_html( sprintf( __( 'Shift #%d', 'counter' ), $shift['id'] ) ) . '</h2>';
		echo '<table class="widefat"><tbody>';
		self::row( __( 'Opening float', 'counter' ), $shift['opening_float'] );
		self::row( __( 'Expected cash', 'counter' ), $shift['expected_cash'] );
		self::row( __( 'Counted cash', 'counter' ), $shift['counted_cash'] );
		self::row( __( 'Variance', 'counter' ), $shift['variance'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Sales', 'counter' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( __( '%1$d sales, total %2$s', 'counter' ), count( $report['sales'] ), $report['sales_total'] ) ) . '</p>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Channel', 'counter' ) . '</th><th>' . esc_html__( 'Count', 'counter' ) . '</th><th>' . esc_html__( 'Total', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $report['by_channel'] as $channel => $data ) {
			echo '<tr><td>' . esc_html( $channel ) . '</td><td>' . esc_html( $data['count'] ) . '</td><td>' . esc_html( $data['total'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Tenders', 'counter' ) . '</h2>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Method', 'counter' ) . '</th><th>' . esc_html__( 'Net', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $report['tenders_by_method'] as $t ) {
			echo '<tr><td>' . esc_html( $t['method'] ) . '</td><td>' . esc_html( $t['net'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Returns and refunds', 'counter' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( __( '%1$d returns, total %2$s', 'counter' ), count( $report['returns'] ), $report['returns_total'] ) ) . '</p>';
		foreach ( $report['refunds_by_method'] as $r ) {
			echo '<p>' . esc_html( $r['method'] . ': ' . $r['total'] ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'No-sales', 'counter' ) . '</h2>';
		if ( empty( $report['no_sales'] ) ) {
			echo '<p>' . esc_html__( 'None.', 'counter' ) . '</p>';
		} else {
			foreach ( $report['no_sales'] as $n ) {
				echo '<p>' . esc_html( $n['created_at'] . ' — ' . __( 'operator', 'counter' ) . ' ' . $n['user_id'] ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Discounts above the ceiling', 'counter' ) . '</h2>';
		if ( empty( $report['discounts_over_ceiling'] ) ) {
			echo '<p>' . esc_html__( 'None.', 'counter' ) . '</p>';
		} else {
			foreach ( $report['discounts_over_ceiling'] as $d ) {
				echo '<p>' . esc_html( sprintf( '#%1$d %2$s — %3$s%% (operator %4$d)', $d['order_id'], $d['line'], $d['discount_pct'], $d['authorised_by'] ) ) . '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Late sales', 'counter' ) . '</h2>';
		if ( empty( $report['late_sales'] ) ) {
			echo '<p>' . esc_html__( 'None.', 'counter' ) . '</p>';
		} else {
			foreach ( $report['late_sales'] as $l ) {
				echo '<p>' . esc_html( sprintf( 'order #%1$d — %2$s (%3$s)', $l['order_id'], $l['amount'], $l['status'] ) ) . '</p>';
			}
		}
	}

	private static function row( string $label, $value ): void {
		echo '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( (string) $value ) . '</td></tr>';
	}
}
