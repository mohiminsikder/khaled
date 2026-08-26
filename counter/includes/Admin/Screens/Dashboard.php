<?php
namespace Counter\Admin\Screens;

use Counter\Reports\Dashboard as DashboardData;

defined( 'ABSPATH' ) || exit;

/**
 * P5.3 — the owner's landing page. Registered directly as the top-level
 * 'counter' menu's own callback in Admin\Menu — this is the first screen
 * open, not a submenu buried under Reports, and so has no init()/menu
 * registration of its own to avoid registering the same content twice under
 * two different slugs. Three panels, three questions, nothing else: what
 * did we make yesterday, what is running out, did the drawer balance last
 * night. Every figure comes straight from Reports\Dashboard::data() — this
 * class only renders it.
 */
class Dashboard {

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$location_id = isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
		$data        = DashboardData::data( $location_id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dashboard', 'counter' ); ?></h1>
			<p><?php echo esc_html( sprintf( /* translators: %s: yesterday's date */ __( 'Yesterday — %s', 'counter' ), $data['day'] ) ); ?></p>

			<div class="cntr-dash-panels" style="display:flex;gap:24px;flex-wrap:wrap;">

				<div class="cntr-dash-panel postbox" style="padding:16px;min-width:260px;">
					<h2><?php esc_html_e( 'What did we make yesterday?', 'counter' ); ?></h2>
					<?php if ( 0 === (int) $data['yesterday']['orders_count'] ) : ?>
						<p><?php esc_html_e( 'No sales recorded yesterday.', 'counter' ); ?></p>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Net sales:', 'counter' ); ?></strong> <?php echo wp_kses_post( wc_price( $data['yesterday']['net'] ) ); ?></p>
						<p><strong><?php esc_html_e( 'Orders:', 'counter' ); ?></strong> <?php echo esc_html( (string) $data['yesterday']['orders_count'] ); ?></p>
						<?php if ( array_key_exists( 'margin', $data['yesterday'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Margin:', 'counter' ); ?></strong> <?php echo wp_kses_post( wc_price( $data['yesterday']['margin'] ) ); ?> (<?php echo esc_html( $data['yesterday']['margin_pct'] ); ?>%)</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<div class="cntr-dash-panel postbox" style="padding:16px;min-width:260px;">
					<h2><?php esc_html_e( 'What is running out?', 'counter' ); ?></h2>
					<?php if ( empty( $data['low_stock'] ) ) : ?>
						<p><?php esc_html_e( 'Nothing below its low-stock threshold.', 'counter' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $data['low_stock'] as $row ) : ?>
								<li><?php echo esc_html( $row['name'] ); ?> — <?php echo esc_html( $row['qty'] ); ?> / <?php echo esc_html( $row['threshold'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="cntr-dash-panel postbox" style="padding:16px;min-width:260px;">
					<h2><?php esc_html_e( 'Did the drawer balance last night?', 'counter' ); ?></h2>
					<?php if ( empty( $data['shifts'] ) ) : ?>
						<p><?php esc_html_e( 'No shifts closed last night.', 'counter' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $data['shifts'] as $row ) : ?>
								<li>
									<?php echo esc_html( $row['register_name'] ?: '#' . $row['register_id'] ); ?> —
									<?php echo $row['balanced'] ? esc_html__( 'balanced', 'counter' ) : esc_html( sprintf( /* translators: %s: signed cash variance */ __( 'off by %s', 'counter' ), wc_format_localized_price( $row['variance'] ) ) ); ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

			</div>
		</div>
		<?php
	}
}
