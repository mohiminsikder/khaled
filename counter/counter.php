<?php
/**
 * Plugin Name: Counter
 * Description: Point of sale, stockroom and back office for a WooCommerce shop.
 * Version:     0.1.24
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: counter
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CNTR_VERSION', '0.1.24' );  // must equal the Version: header — test_version()
define( 'CNTR_DB_VER', 1 );          // bump with every schema change
define( 'CNTR_FILE', __FILE__ );
define( 'CNTR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CNTR_URL', plugin_dir_url( __FILE__ ) );

// HPOS. This must run on before_woocommerce_init or WooCommerce marks the plugin
// incompatible and refuses to enable custom order tables.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

require_once CNTR_DIR . 'includes/Boot.php';
\Counter\Boot::init();
