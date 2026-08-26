<?php
namespace Counter\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * P8.4 — audit finding T5. "Counter is pinned to HPOS internals and stock
 * functions" — an unattended WordPress core or WooCommerce update could
 * silently break either, on the one machine that takes real money.
 * Direction's own rule: every update goes to staging, suite green, then
 * live, on a morning, never a Thursday evening — never automatically.
 *
 * WP_AUTO_UPDATE_CORE (wp-config.php) already gates WordPress core itself;
 * this class enforces the plugin-level half through WordPress's own
 * 'auto_update_plugin' filter — a real, code-level guarantee, not merely an
 * observed setting. The wp-admin "Enable auto-updates" toggle writes to the
 * SAME site option this filter overrides, so even if someone with the
 * capability flips it back on for WooCommerce or Counter, this filter still
 * refuses the update outright.
 */
class UpdatePolicy {

	/** The two plugins this shop can never afford to update unattended. */
	const PINNED_PLUGINS = [ 'woocommerce/woocommerce.php', 'counter/counter.php' ];

	public static function init(): void {
		add_filter( 'auto_update_plugin', [ self::class, 'block_pinned_plugins' ], 10, 2 );
	}

	/**
	 * WordPress calls this once per candidate update, $item->plugin already
	 * the exact basename ('woocommerce/woocommerce.php' etc.) — never
	 * re-derived here from a constant that could itself have drifted.
	 */
	public static function block_pinned_plugins( bool $update, object $item ): bool {
		if ( in_array( (string) ( $item->plugin ?? '' ), self::PINNED_PLUGINS, true ) ) {
			return false;
		}
		return $update;
	}

	/**
	 * The live, current truth — read fresh every call, never cached —
	 * shared by test_update_policy() and Health.php's own warning section,
	 * so the two can never quietly say different things about the same
	 * setting.
	 *
	 * @return array{core_value:string|false|null, core_ok:bool, plugins_in_auto_update_list:array, plugins_ok:bool}
	 */
	public static function status(): array {
		$core_value = defined( 'WP_AUTO_UPDATE_CORE' ) ? constant( 'WP_AUTO_UPDATE_CORE' ) : null;
		$core_ok    = false === $core_value || 'minor' === $core_value;

		$auto_update_list = (array) get_site_option( 'auto_update_plugins', [] );
		$in_list          = array_values( array_intersect( self::PINNED_PLUGINS, $auto_update_list ) );

		return [
			'core_value'                  => $core_value,
			'core_ok'                     => $core_ok,
			'plugins_in_auto_update_list' => $in_list,
			'plugins_ok'                  => empty( $in_list ),
		];
	}
}
