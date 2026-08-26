<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Guarded. See Install::PROTECTED_TABLES: the ledger and the VAT register are
 * never dropped by the normal "Delete" click on the plugins screen. Destroying
 * them requires CNTR_ALLOW_DESTRUCTIVE_UNINSTALL defined true in wp-config.php,
 * which is not something a misclick can do.
 */

define( 'CNTR_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Counter\\' ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, 8 ) );
		$path = CNTR_DIR . 'includes/' . $rel . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

\Counter\Install::uninstall_soft();

if ( \Counter\Install::may_uninstall_hard() ) {
	\Counter\Install::uninstall_hard();
}
