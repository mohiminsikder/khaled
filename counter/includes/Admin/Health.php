<?php
namespace Counter\Admin;

use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * The version stamp can lie — upgrade() writes it whatever dbDelta actually did.
 * This page reads the database directly (Install::missing()) rather than trusting
 * the stamp, and runs the self-test suite on demand.
 */
class Health {

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_settings' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$missing      = Install::missing();
		$db_ver_stamp = (int) get_option( 'cntr_db_ver', 0 );

		$selftest_results = null;
		if ( isset( $_GET['selftest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic trigger, no state changes gated by it
			$selftest_results = ( new Selftest() )->run();
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Health', 'counter' ) . '</h1>';

		self::render_schema_section( $missing, $db_ver_stamp );
		self::render_wc_section();
		self::render_update_policy_section();
		self::render_queue_section();
		self::render_returns_section();
		self::render_accounts_section();
		self::render_quick_add_section();
		self::render_selftest_section( $selftest_results );

		echo '</div>';
	}

	private static function render_schema_section( array $missing, int $db_ver_stamp ): void {
		echo '<h2>' . esc_html__( 'Schema', 'counter' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf( 'CNTR_DB_VER: %d — stored stamp: %d', CNTR_DB_VER, $db_ver_stamp )
		) . '</p>';

		if ( empty( $missing ) ) {
			echo '<p style="color:green">' . esc_html__( 'Install::missing() — nothing missing.', 'counter' ) . '</p>';
			return;
		}

		echo '<p style="color:red">' . esc_html__( 'Install::missing() found gaps:', 'counter' ) . '</p><ul>';
		foreach ( $missing as $gap ) {
			$label = $gap['table'] . ( $gap['column'] ? '.' . $gap['column'] : '' ) . ' — ' . $gap['issue'];
			echo '<li>' . esc_html( $label ) . '</li>';
		}
		echo '</ul>';
	}

	private static function render_wc_section(): void {
		echo '<h2>' . esc_html__( 'WooCommerce', 'counter' ) . '</h2>';
		echo '<p>' . esc_html( 'Version: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'not detected' ) ) . '</p>';
		echo '<p>' . esc_html( 'HPOS: ' . ( self::hpos_enabled() ? 'enabled' : 'disabled or unknown' ) ) . '</p>';
		echo '<p>' . esc_html(
			'Stock authority filters active: ' . ( has_filter( 'woocommerce_can_reduce_order_stock' ) ? 'yes' : 'no' )
		) . '</p>';
	}

	/**
	 * P8.4 — the one place this policy is actually enforced is
	 * UpdatePolicy's own 'auto_update_plugin' filter; this section only
	 * warns if the SETTINGS it reads have drifted from what that filter
	 * (and WP_AUTO_UPDATE_CORE in wp-config.php) assume — a human re-
	 * enabling the wp-admin toggle for WooCommerce/Counter, or someone
	 * editing wp-config.php, would both still be caught here even though
	 * neither can actually FORCE an unattended update through, since the
	 * filter itself does not depend on either setting reading correctly.
	 */
	private static function render_update_policy_section(): void {
		$status = \Counter\Admin\UpdatePolicy::status();
		echo '<h2>' . esc_html__( 'Update policy', 'counter' ) . '</h2>';

		if ( $status['core_ok'] && $status['plugins_ok'] ) {
			echo '<p style="color:green">' . esc_html__( 'Auto-updates are off for WordPress core (minor only) and for WooCommerce/Counter.', 'counter' ) . '</p>';
			return;
		}

		if ( ! $status['core_ok'] ) {
			echo '<p style="color:red">' . esc_html(
				sprintf(
					/* translators: %s: the current WP_AUTO_UPDATE_CORE value */
					__( 'WP_AUTO_UPDATE_CORE is %s — expected false or \'minor\'. WordPress core could update itself unattended.', 'counter' ),
					wp_json_encode( $status['core_value'] )
				)
			) . '</p>';
		}

		if ( ! $status['plugins_ok'] ) {
			echo '<p style="color:red">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin basenames */
					__( 'Auto-updates are enabled in wp-admin for: %s. UpdatePolicy\'s own filter still refuses the update outright, but this setting drifting is worth knowing about.', 'counter' ),
					implode( ', ', $status['plugins_in_auto_update_list'] )
				)
			) . '</p>';
		}
	}

	private static function render_queue_section(): void {
		global $wpdb;

		echo '<h2>' . esc_html__( 'Till', 'counter' ) . '</h2>';

		$queue_table = Install::table( 'sale_queue' );
		$by_status   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$queue_table} GROUP BY status", ARRAY_A );
		if ( empty( $by_status ) ) {
			echo '<p>' . esc_html__( 'Sale queue: empty.', 'counter' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Sale queue depth by status:', 'counter' ) . '</p><ul>';
			foreach ( $by_status as $row ) {
				echo '<li>' . esc_html( $row['status'] . ': ' . $row['n'] ) . '</li>';
			}
			echo '</ul>';
		}

		$oldest = $wpdb->get_var(
			$wpdb->prepare( "SELECT MIN(created_at) FROM {$queue_table} WHERE status = %s", 'queued' )
		);
		echo '<p>' . esc_html( 'Oldest unsynced outbox entry: ' . ( $oldest ?: 'none' ) ) . '</p>';

		$shifts_table = Install::table( 'shifts' );
		$open_shifts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$shifts_table} WHERE open_key IS NOT NULL" );
		echo '<p>' . esc_html( sprintf( 'Open shifts: %d', $open_shifts ) ) . '</p>';
	}

	/**
	 * P4.4 — "every return is processed at the counter first" is a process
	 * rule, not something software can enforce (WooCommerce's own refund
	 * screen is right there in wp-admin, and Counter cannot remove it
	 * without breaking WooCommerce itself). What it CAN do is notice when
	 * the rule was broken: Orders\Refunds::process() tags every refund it
	 * creates with `_cntr_via_pos` — a refund with no such tag reached the
	 * `wc_orders` table some other way, and its stock/tenders/shift events
	 * never went through Channel::apply_refund_reversion() via the counter
	 * path at all (only via whatever hook WooCommerce itself fired, if any).
	 */
	public static function diverged_refund_ids(): array {
		$ids = wc_get_orders(
			[
				'type'       => 'shop_order_refund',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[ 'key' => '_cntr_via_pos', 'compare' => 'NOT EXISTS' ],
				],
			]
		);
		return array_map( 'intval', $ids );
	}

	private static function render_returns_section(): void {
		echo '<h2>' . esc_html__( 'Returns', 'counter' ) . '</h2>';

		$diverged = self::diverged_refund_ids();
		if ( empty( $diverged ) ) {
			echo '<p style="color:green">' . esc_html__( 'Every refund on record was processed through the counter.', 'counter' ) . '</p>';
			return;
		}

		echo '<p style="color:red">' . esc_html(
			sprintf(
				/* translators: %d: number of refunds */
				_n(
					'%d refund was created in wp-admin without going through the counter — its stock and profit-and-loss may be wrong.',
					'%d refunds were created in wp-admin without going through the counter — their stock and profit-and-loss may be wrong.',
					count( $diverged ),
					'counter'
				),
				count( $diverged )
			)
		) . '</p><ul>';
		foreach ( $diverged as $refund_id ) {
			echo '<li>#' . (int) $refund_id . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * P4.6 — "the count is meant to be zero." A POS tender with no mapped
	 * account is refused outright (Pos\Tenders::record()); an online order
	 * through an unmapped gateway is accepted, never refused, but counted
	 * here instead — the same "detect, don't silently diverge" shape as
	 * this class's own returns section above.
	 */
	private static function render_accounts_section(): void {
		echo '<h2>' . esc_html__( 'Payment accounts', 'counter' ) . '</h2>';

		$unmapped_gateways = \Counter\Pos\Accounts::unmapped_gateway_ids();
		$unmapped_orders   = \Counter\Pos\Accounts::unmapped_order_ids();

		if ( empty( $unmapped_gateways ) ) {
			echo '<p style="color:green">' . esc_html__( 'Every enabled payment gateway resolves to an active account.', 'counter' ) . '</p>';
		} else {
			echo '<p style="color:red">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated gateway ids */
					__( 'These enabled gateways have no active account mapped: %s', 'counter' ),
					implode( ', ', $unmapped_gateways )
				)
			) . '</p>';
		}

		echo '<p style="color:' . ( empty( $unmapped_orders ) ? 'green' : 'red' ) . '">' . esc_html(
			sprintf(
				/* translators: %d: number of orders */
				__( 'Online orders paid through an unmapped gateway: %d', 'counter' ),
				count( $unmapped_orders )
			)
		) . '</p>';
	}

	/**
	 * F7 (COUNTERFRONTEND.md) — closes the orphaned flag Pos\Terminal::quick_add()
	 * writes and nothing had ever read: P1.13's own words, "Products created
	 * this way are flagged and listed on a Health-page screen so somebody
	 * completes their category, tax class and cost later." EVERY quick-added
	 * product appears here — an empty 'missing' array means it's since been
	 * completed, not that it drops off the list.
	 *
	 * 'cost' reads Stock\Batches::last_known_cost(), not a product field of
	 * its own: quick_add()'s own opening-quantity move goes through
	 * Stock\Ledger::move() directly, never Batches::receive(), so a
	 * quick-added product's stock carries no batch and no recorded cost at
	 * all until someone receives it properly — exactly the gap this is
	 * meant to surface.
	 */
	public static function quick_added_products(): array {
		// get_posts(), not wc_get_products() — WC_Product_Query's own arg
		// allow-list does not reliably forward a raw meta_query the way
		// WP_Query itself does (found live: it silently returned every
		// product on the site, meta_query or not); this is the one
		// unambiguous way to actually filter by post meta here.
		$ids = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[ 'key' => '_cntr_quick_added', 'value' => '1' ],
				],
			]
		);

		$out = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			$missing = [];
			if ( empty( $product->get_category_ids() ) ) {
				$missing[] = 'category';
			}
			if ( '' === $product->get_tax_class() ) {
				$missing[] = 'tax_class';
			}
			if ( 0 === bccomp( \Counter\Stock\Batches::last_known_cost( $id, 0 ), '0', 4 ) ) {
				$missing[] = 'cost';
			}

			$out[] = [
				'product_id' => $id,
				'name'       => $product->get_name(),
				'created_at' => $product->get_date_created() ? $product->get_date_created()->date( 'Y-m-d H:i' ) : '',
				'missing'    => $missing,
			];
		}
		return $out;
	}

	private static function render_quick_add_section(): void {
		echo '<h2>' . esc_html__( 'Quick-added products', 'counter' ) . '</h2>';

		$rows = self::quick_added_products();
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No products have been quick-added yet.', 'counter' ) . '</p>';
			return;
		}

		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Product', 'counter' ) . '</th><th>'
			. esc_html__( 'Created', 'counter' ) . '</th><th>' . esc_html__( 'Still missing', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$is_complete = empty( $row['missing'] );
			$label       = $is_complete ? __( 'complete', 'counter' ) : implode( ', ', $row['missing'] );
			echo '<tr><td>' . esc_html( $row['name'] . ' (#' . $row['product_id'] . ')' ) . '</td><td>'
				. esc_html( $row['created_at'] ) . '</td><td style="color:' . ( $is_complete ? 'green' : 'red' ) . '">'
				. esc_html( $label ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_selftest_section( ?array $results ): void {
		echo '<h2>' . esc_html__( 'Self-test', 'counter' ) . '</h2>';

		if ( null === $results ) {
			$url = add_query_arg( 'selftest', 1 );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '#cntr-selftest">'
				. esc_html__( 'Run self-test', 'counter' ) . '</a></p>';
			return;
		}

		echo '<p id="cntr-selftest">' . esc_html(
			sprintf( '%d / %d passed', $results['pass'], $results['pass'] + $results['fail'] )
		) . '</p><ul>';

		foreach ( $results['results'] as $r ) {
			$color = $r['pass'] ? 'green' : 'red';
			$mark  = $r['pass'] ? '✓' : '✗';
			echo '<li style="color:' . esc_attr( $color ) . '">' . esc_html( $mark . ' ' . $r['label'] );
			if ( $r['detail'] ) {
				echo esc_html( ' — ' . $r['detail'] );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function hpos_enabled(): bool {
		return class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
