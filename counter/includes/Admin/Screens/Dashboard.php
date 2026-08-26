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

		self::maybe_handle_readiness_dismiss();

		$location_id = isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
		$data        = DashboardData::data( $location_id );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dashboard', 'counter' ); ?></h1>
			<p><?php echo esc_html( sprintf( /* translators: %s: yesterday's date */ __( 'Yesterday — %s', 'counter' ), $data['day'] ) ); ?></p>

			<?php self::render_first_run_panel(); ?>
			<?php self::render_till_links_panel(); ?>

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

	/**
	 * A5 — the till otherwise has no link anywhere. One row per active
	 * register (a shop with more than one till bookmarks each to its own
	 * register, per §6's manual pass), its real /pos/?register=<id> URL —
	 * templates/pos.php already reads that query var and falls back to the
	 * first active register without it — and a copy control so a real
	 * cashier's bookmark is exact, not retyped by hand.
	 */
	private static function render_till_links_panel(): void {
		$registers = \Counter\Pos\Registers::all( 'active' );
		?>
		<div class="cntr-dash-panel postbox" style="padding:16px;margin-bottom:24px;">
			<h2><?php esc_html_e( 'The till', 'counter' ); ?></h2>
			<?php if ( empty( $registers ) ) : ?>
				<p><?php esc_html_e( 'No active register yet.', 'counter' ); ?></p>
			<?php else : ?>
				<table class="widefat" style="max-width:640px;">
					<tbody>
						<?php foreach ( $registers as $register ) : ?>
							<?php $url = add_query_arg( 'register', (int) $register['id'], home_url( '/pos/' ) ); ?>
							<tr>
								<td><?php echo esc_html( $register['name'] ); ?></td>
								<td><code class="cntr-till-url"><?php echo esc_html( $url ); ?></code></td>
								<td>
									<a href="<?php echo esc_url( $url ); ?>" class="button" target="_blank" rel="noopener">
										<?php esc_html_e( 'Open', 'counter' ); ?>
									</a>
									<button type="button" class="button cntr-till-copy" data-url="<?php echo esc_attr( $url ); ?>">
										<?php esc_html_e( 'Copy link', 'counter' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<script>
				document.querySelectorAll('.cntr-till-copy').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var url = btn.getAttribute('data-url');
						var done = function () {
							var original = btn.textContent;
							btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'counter' ) ); ?>;
							setTimeout(function () { btn.textContent = original; }, 1500);
						};
						if (navigator.clipboard && navigator.clipboard.writeText) {
							navigator.clipboard.writeText(url).then(done, done);
						} else {
							done();
						}
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The real reads readiness_items() turns into a checklist. Split out
	 * so a test can exercise readiness_items() with synthetic facts (a
	 * genuinely cashier-less install, say) without needing to touch real
	 * WordPress users or plugin data to get there.
	 */
	private static function gather_readiness_facts(): array {
		$registers = \Counter\Pos\Registers::all( 'active' );

		$cashier_count = count(
			get_users(
				[
					'role__in' => [ \Counter\Capabilities::ROLE_CASHIER, \Counter\Capabilities::ROLE_SUPERVISOR ],
					'fields'   => 'ID',
				]
			)
		);

		$any_open_shift = false;
		foreach ( $registers as $register ) {
			if ( \Counter\Pos\Shifts::open_for_register( (int) $register['id'] ) ) {
				$any_open_shift = true;
				break;
			}
		}

		return [
			'location_id'    => \Counter\Stock\Locations::default_id(),
			'registers'      => $registers,
			'accounts'       => \Counter\Pos\Accounts::all( 'active' ),
			'cashier_count'  => $cashier_count,
			'product_count'  => (int) ( wp_count_posts( 'product' )->publish ?? 0 ),
			'any_open_shift' => $any_open_shift,
		];
	}

	/**
	 * Pure — the five readiness questions, each with where to fix it. No
	 * dedicated Locations/Registers screen exists yet (that's C5, Phase C),
	 * so that miss points at Health instead of a dead link.
	 */
	public static function readiness_items( array $facts ): array {
		return [
			[
				'ok'    => $facts['location_id'] > 0 && ! empty( $facts['registers'] ),
				'label' => __( 'A location and an active register are set up', 'counter' ),
				'href'  => admin_url( 'admin.php?page=counter-health' ),
			],
			[
				'ok'    => ! empty( $facts['accounts'] ),
				'label' => __( 'At least one payment account is active (cash, bKash, …)', 'counter' ),
				'href'  => admin_url( 'admin.php?page=counter-accounts' ),
			],
			[
				'ok'    => $facts['cashier_count'] > 0,
				'label' => __( 'At least one cashier account exists', 'counter' ),
				'href'  => admin_url( 'user-new.php' ),
			],
			[
				'ok'    => $facts['product_count'] > 0,
				'label' => __( 'At least one product is published', 'counter' ),
				'href'  => admin_url( 'post-new.php?post_type=product' ),
			],
			[
				'ok'    => $facts['any_open_shift'],
				'label' => __( 'A shift is open on at least one register', 'counter' ),
				'href'  => home_url( '/pos/' ),
			],
		];
	}

	/**
	 * A6 — a dismissible GET action rather than the JS/AJAX machinery a
	 * per-user WP core admin notice would need; this panel is site-wide
	 * (readiness is a fact about the shop, not about who is looking), so a
	 * single option is the whole mechanism. Must run before any output —
	 * it redirects.
	 */
	private static function maybe_handle_readiness_dismiss(): void {
		if ( ! isset( $_GET['cntr_dismiss_readiness'] ) ) {
			return;
		}
		check_admin_referer( 'cntr_dismiss_readiness' );
		update_option( 'cntr_readiness_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( [ 'cntr_dismiss_readiness', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * A6 — "can a new owner tell whether the shop is ready to sell?" Every
	 * line is a real read, not a guess: the same seeded-on-activation state
	 * COUNTERV2.md §0.3 documents, checked directly rather than assumed
	 * present. Each miss links to where it is fixed; C1-C5's dedicated
	 * screens don't exist yet in Phase A, so a miss that has no Counter
	 * screen of its own yet points at Health (schema/seed state) or a core
	 * WordPress/WooCommerce screen instead — never a dead link.
	 */
	private static function render_first_run_panel(): void {
		if ( get_option( 'cntr_readiness_dismissed', false ) ) {
			return;
		}

		$items  = self::readiness_items( self::gather_readiness_facts() );
		$all_ok = ! in_array( false, array_column( $items, 'ok' ), true );

		$dismiss_url = wp_nonce_url( add_query_arg( 'cntr_dismiss_readiness', 1 ), 'cntr_dismiss_readiness' );
		?>
		<div class="cntr-dash-panel postbox" style="padding:16px;margin-bottom:24px;">
			<div style="display:flex;justify-content:space-between;align-items:baseline;">
				<h2><?php esc_html_e( 'Is the shop ready to sell?', 'counter' ); ?></h2>
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss', 'counter' ); ?></a>
			</div>
			<?php if ( $all_ok ) : ?>
				<p><?php esc_html_e( 'Yes — every readiness check passes.', 'counter' ); ?></p>
			<?php endif; ?>
			<ul>
				<?php foreach ( $items as $item ) : ?>
					<li style="color:<?php echo $item['ok'] ? 'green' : 'inherit'; ?>;">
						<?php echo $item['ok'] ? '✓' : '✗'; ?>
						<?php echo esc_html( $item['label'] ); ?>
						<?php if ( ! $item['ok'] ) : ?>
							— <a href="<?php echo esc_url( $item['href'] ); ?>"><?php esc_html_e( 'fix this', 'counter' ); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
