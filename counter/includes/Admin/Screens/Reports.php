<?php
namespace Counter\Admin\Screens;

use Counter\Reports\Reports as ReportsClass;
use Counter\Reports\CashierPerformance;
use Counter\Stock\Locations;
use Counter\Admin\EntityPicker;

defined( 'ABSPATH' ) || exit;

/**
 * P5.2 — a thin wp-admin screen over Reports::run()'s own contract. The
 * screen never computes a figure itself; every number it shows, and every
 * number export() streams as CSV, comes from the exact same run() call —
 * "a report that exports different figures from what it displays is worse
 * than no export" (Direction's own words) is structurally true here, not
 * merely tested for.
 */
class Reports {

	const LABELS = [
		'sales_summary'     => 'Sales summary',
		'sales_by_product'  => 'Sales by product',
		'sales_by_category' => 'Sales by category',
		'sales_by_cashier'  => 'Sales by cashier',
		'sales_by_hour'     => 'Sales by hour of day',
		'tender_mix'        => 'Tender mix',
		'discounts'         => 'Discounts given',
		'refunds'           => 'Refunds',
		'margin'            => 'Margin',
		'tax_collected'     => 'Tax collected',
		'register_history'  => 'Register / Z history',
		// P6.5 — not a Reports::REPORT_NAMES entry (viewer-scoped, its own
		// row shape includes a nested variance_history list the generic
		// table below cannot render), handled as its own branch in
		// render() instead.
		'cashier_performance' => 'Cashier performance',
	];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Reports', 'counter' ),
			__( 'Reports', 'counter' ),
			'cntr_view_reports',
			'counter-reports',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$report = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : 'sales_summary';
		if ( ! isset( self::LABELS[ $report ] ) ) {
			$report = 'sales_summary';
		}
		$args = [
			'from'        => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' ),
			'to'          => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' ),
			'channel'     => isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : 'all',
			'location_id' => isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0,
		];

		$is_cashier_performance = 'cashier_performance' === $report;

		$location_options = [ [ 'id' => 0, 'label' => __( 'All locations', 'counter' ) ] ];
		$location_label    = __( 'All locations', 'counter' );
		foreach ( Locations::all( 'active' ) as $loc ) {
			$location_options[] = [ 'id' => (int) $loc['id'], 'label' => (string) $loc['name'] ];
			if ( (int) $loc['id'] === $args['location_id'] ) {
				$location_label = (string) $loc['name'];
			}
		}

		if ( isset( $_GET['cntr_export'] ) && ! $is_cashier_performance ) {
			check_admin_referer( 'cntr_report_export' );
			$csv = ReportsClass::export( $report, $args );
			if ( is_wp_error( $csv ) ) {
				wp_die( esc_html( $csv->get_error_message() ) );
			}
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="counter-' . $report . '-' . $args['from'] . '-to-' . $args['to'] . '.csv"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- raw CSV body, not HTML
			exit;
		}

		$rows = $is_cashier_performance
			? CashierPerformance::report( $args, get_current_user_id() )
			: ReportsClass::run( $report, $args );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Reports', 'counter' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="counter-reports">
				<select name="report">
					<?php foreach ( self::LABELS as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $report, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="date" name="from" value="<?php echo esc_attr( $args['from'] ); ?>">
				<input type="date" name="to" value="<?php echo esc_attr( $args['to'] ); ?>">
				<select name="channel">
					<option value="all" <?php selected( $args['channel'], 'all' ); ?>><?php esc_html_e( 'All channels', 'counter' ); ?></option>
					<option value="pos" <?php selected( $args['channel'], 'pos' ); ?>><?php esc_html_e( 'POS', 'counter' ); ?></option>
					<option value="online" <?php selected( $args['channel'], 'online' ); ?>><?php esc_html_e( 'Online', 'counter' ); ?></option>
				</select>
				<?php
				EntityPicker::render(
					[
						'id'          => 'cntr-report-location',
						'hidden_name' => 'location_id',
						'type'        => 'location',
						'placeholder' => __( 'Type a location name…', 'counter' ),
						'value'       => $args['location_id'],
						'value_label' => $location_label,
						'options'     => $location_options,
					]
				);
				?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run', 'counter' ); ?></button>
				<?php if ( current_user_can( 'cntr_export' ) && ! $is_cashier_performance ) : ?>
					<button type="submit" name="cntr_export" value="1" formaction="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php' ), 'cntr_report_export' ) ); ?>" class="button"><?php esc_html_e( 'Export CSV', 'counter' ); ?></button>
				<?php endif; ?>
			</form>

			<?php if ( is_wp_error( $rows ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $rows->get_error_message() ); ?></p></div>
			<?php elseif ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No data for this period.', 'counter' ); ?></p>
			<?php elseif ( $is_cashier_performance ) : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Operator', 'counter' ); ?></th><th><?php esc_html_e( 'Orders', 'counter' ); ?></th><th><?php esc_html_e( 'Sales', 'counter' ); ?></th><th><?php esc_html_e( 'Avg basket', 'counter' ); ?></th><th><?php esc_html_e( 'Discount given', 'counter' ); ?></th><th><?php esc_html_e( 'Voids', 'counter' ); ?></th><th><?php esc_html_e( 'No-sales', 'counter' ); ?></th><th><?php esc_html_e( 'Variance history', 'counter' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo esc_html( (string) $row['orders_count'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row['sales'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row['avg_basket'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $row['discount_given'] ) ); ?></td>
								<td><?php echo esc_html( (string) $row['voids'] ); ?></td>
								<td><?php echo esc_html( (string) $row['no_sales'] ); ?></td>
								<td>
									<?php foreach ( $row['variance_history'] as $v ) : ?>
										<div><?php echo esc_html( $v['closed_at'] . ': ' . $v['variance'] ); ?></div>
									<?php endforeach; ?>
									<?php if ( empty( $row['variance_history'] ) ) : ?>&mdash;<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><?php foreach ( array_keys( $rows[0] ) as $col ) : ?><th><?php echo esc_html( $col ); ?></th><?php endforeach; ?></tr></thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr><?php foreach ( $row as $val ) : ?><td><?php echo esc_html( (string) $val ); ?></td><?php endforeach; ?></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
