<?php
namespace Counter;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every module. This is the only place that knows the list — adding a class
 * elsewhere means adding a file (the autoloader finds it by name), but adding a
 * module that needs to run on a hook means adding one line here.
 */
class Boot {

	public static function init(): void {
		spl_autoload_register( [ self::class, 'autoload' ] );

		// Registered unconditionally, not from boot_modules() — wp_schedule_event()
		// validates the interval name against wp_get_schedules() at call time
		// (WP 5.1+), and both on_activate() and every later WP-Cron run need
		// this filter already in place, including the activation request
		// itself, which fires after 'plugins_loaded' has already run.
		add_filter( 'cron_schedules', [ Pos\Queue::class, 'add_cron_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

		add_action( 'plugins_loaded', [ self::class, 'on_plugins_loaded' ] );
		register_activation_hook( CNTR_FILE, [ self::class, 'on_activate' ] );
		register_deactivation_hook( CNTR_FILE, [ self::class, 'on_deactivate' ] );
	}

	public static function autoload( string $class ): void {
		if ( 0 !== strpos( $class, 'Counter\\' ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, 8 ) );
		$path = CNTR_DIR . 'includes/' . $rel . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	public static function on_plugins_loaded(): void {
		load_plugin_textdomain( 'counter', false, dirname( plugin_basename( CNTR_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ self::class, 'notice_no_woo' ] );
			return;
		}

		if ( (int) get_option( 'cntr_db_ver', 0 ) < CNTR_DB_VER ) {
			Install::upgrade();
		}

		// A4 — capability grants are code, not schema, so CNTR_DB_VER doesn't
		// move for a capability-only change; tracked against CNTR_VERSION
		// instead. §0.1 rule 4's own promise ("roles only write on activation
		// and version bump") had no version-bump half actually wired up until
		// this — grant_all() previously ran only from on_activate(), so a
		// capability moved between roles (A4 itself: cntr_refund off Cashier)
		// would silently do nothing on an already-active install until someone
		// deactivated and reactivated the plugin by hand.
		if ( get_option( 'cntr_version' ) !== CNTR_VERSION ) {
			Capabilities::grant_all();
			update_option( 'cntr_version', CNTR_VERSION );
		}

		self::boot_modules();
	}

	public static function on_activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			// Refuse to seed a schema against a shop the plugin cannot serve.
			return;
		}
		Install::activate();
		Capabilities::grant_all();
		update_option( 'cntr_version', CNTR_VERSION );
		Reports\Expenses::seed_default_categories();

		// Both rewrite rules are normally registered on 'init', which has
		// already passed by the time this activation hook runs — add them
		// directly here so flush_rewrite_rules() actually has something to
		// flush, rather than leaving /pos/ and /counter-sw.js 404ing until
		// some other event happens to trigger a flush. Found live in P7.1:
		// ServiceWorker::add_rewrite() was missing from here, and every
		// deploy's own deactivate/reactivate cycle kept "fixing" /pos/
		// while leaving /counter-sw.js permanently 404 no matter how many
		// times the plugin was reactivated.
		Pos\Terminal::add_rewrite();
		Pos\ServiceWorker::add_rewrite();
		flush_rewrite_rules();

		Pos\Queue::schedule();
	}

	public static function on_deactivate(): void {
		Pos\Queue::unschedule();
	}

	/**
	 * Only wires modules that actually exist at the current phase — a class
	 * reference to a not-yet-built module passes php -l silently and only fatals
	 * when the hook actually fires on a real site (see the P1.1 commit for the bug
	 * this comment exists to stop someone reintroducing).
	 */
	private static function boot_modules(): void {
		Capabilities::init();
		Stock\Authority::init();
		Stock\Catalog::init();
		Pos\Queue::init();
		Pos\Customers::init();
		Pos\Accounts::init();
		Pos\Pin::init();
		People\Employees::init();
		People\Attendance::init();
		People\Leave::init();
		People\Payroll::init();
		Orders\Tax::init();
		Orders\Channel::init();
		Pricing\Groups::init();
		Reports\Rollup::init();
		Reports\Expenses::init();
		Rest\Router::init();
		Rest\Catalog::init();
		Rest\Customer::init();
		Rest\Sale::init();
		Rest\Shift::init();
		Rest\Expense::init();
		Rest\Returns::init();
		Rest\OrderLookup::init();
		Rest\Perf::init();
		Rest\Stock::init();
		Rest\EntitySearch::init();
		Rest\Pin::init();
		Rest\Attendance::init();
		Rest\Leave::init();
		Pos\Terminal::init();
		Pos\ServiceWorker::init();
		Orders\Refunds::admin_notice_init();
		Admin\Menu::init();
		Admin\UpdatePolicy::init();
		Admin\Screens\ShiftReport::init();
		Admin\Screens\Perf::init();
		Admin\Screens\Adjust::init();
		Admin\Screens\Receivables::init();
		Admin\Screens\LabelDesigner::init();
		Admin\Screens\VatExports::init();
		Admin\Screens\Fulfilment::init();
		Admin\Screens\Accounts::init();
		Admin\Screens\Reports::init();
		Admin\Screens\Expenses::init();
		Admin\Screens\Attendance::init();
		Admin\Screens\Leave::init();
		Admin\Screens\Payroll::init();
		Admin\Screens\OutboxFailures::init();
		Admin\Screens\OversellLog::init();
		Cli::init();
		Pos\Assets::init();
	}

	public static function notice_no_woo(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Counter requires WooCommerce to be installed and active.', 'counter' )
		);
	}
}
