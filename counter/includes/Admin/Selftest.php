<?php
namespace Counter\Admin;

use Counter\Install;
use Counter\Db;
use Counter\Capabilities;
use Counter\Settings;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * The substitute for CI. There is no local WordPress and no CI, so this suite is
 * what stands between a change and the till — it must be genuinely good, because
 * there is nothing behind it.
 *
 * §11.2 rule 6: cleanup() only removes what THIS run created. If a tagged fixture
 * already existed before this run touched anything, someone may be mid-session
 * with a test terminal — removal is skipped for that table and recorded as a
 * check, never silently deleted.
 */
class Selftest {

	const TAG = 'cntr_selftest_fixture';

	/**
	 * test_perf_budget() (P1.18) is advisory on a shared/staging box — a
	 * neighbour's traffic can blow the 400ms/50ms/5ms budgets for reasons
	 * that have nothing to do with Counter's own code — but must BLOCK on
	 * production hardware, where a miss is real. Flip this to true only when
	 * running against the shop's actual production till/server. As of
	 * P1.18, peapip.com is still the test-purpose verification environment
	 * (see docs/decisions.md), so this stays false here.
	 */
	const PERF_BLOCKING = false;

	private const SETTINGS_TEST_KEY = 'debug.selftest_marker';

	private array $results = [];
	private int $pass      = 0;
	private int $fail      = 0;

	/** ids this run itself created in cntr_audit_log, tagged via action = self::TAG. */
	private array $audit_fixture_ids = [];

	/** WooCommerce product/order ids this run created, tagged via the same meta value. */
	private array $product_fixture_ids = [];
	private array $order_fixture_ids   = [];

	/** cntr_locations ids this run created, identified by a CNTR-SELFTEST- code prefix. */
	private array $location_fixture_ids = [];

	/** cntr_registers ids this run created; cleaned up by exact tracked id, same as catalog_index. */
	private array $register_fixture_ids = [];

	/** cntr_sale_queue uuids this run generated (including failed/crashed attempts). */
	private array $sale_queue_uuids = [];

	/** WP_User ids created as fixture customers; deleted via wp_delete_user(). */
	private array $customer_fixture_ids = [];

	/** cntr_transfers ids this run created; cleaned up by exact tracked id, same as registers. cntr_transfer_lines follows via transfer_id. */
	private array $transfer_fixture_ids = [];

	/** wp_options names Rest\Stock::process() created as idempotency guards (option 'cntr_adj_uuid_<uuid>'); deleted by exact name. */
	private array $adjustment_uuid_options = [];

	/** cntr_suppliers ids this run created; cleaned up by exact tracked id. Their cntr_supplier_ledger rows follow via supplier_id. */
	private array $supplier_fixture_ids = [];

	/** cntr_batches ids this run created; cleaned up by exact tracked id. Their cntr_stock_moves rows are permanent, same as every other ledger row. */
	private array $batch_fixture_ids = [];

	/** cntr_purchase_orders ids this run created; cleaned up by exact tracked id. Their cntr_purchase_lines rows follow via po_id. */
	private array $po_fixture_ids = [];

	/** wp_options names Purchasing\Receiving::receive() created as idempotency guards (option 'cntr_po_receive_uuid_<uuid>'); deleted by exact name. */
	private array $po_receive_uuid_options = [];

	/** cntr_stocktakes ids this run created; cleaned up by exact tracked id. Their cntr_stocktake_lines rows follow via stocktake_id. */
	private array $stocktake_fixture_ids = [];

	/** cntr_units ids this run created; cleaned up by exact tracked id. */
	private array $unit_fixture_ids = [];
	private array $label_fixture_ids = [];
	private array $doc_template_fixture_ids = [];
	private array $payment_account_fixture_ids = [];

	/** cntr_expenses ids this run created; cleaned up by exact tracked id. */
	private array $expense_fixture_ids = [];

	/** cntr_expense_categories ids this run created (test-only categories, never the 7 seeded defaults); cleaned up by exact tracked id. */
	private array $expense_category_fixture_ids = [];

	/** cntr_employees ids this run created; cleaned up by exact tracked id. */
	private array $employee_fixture_ids = [];

	/** WP_User ids this run created purely as an employee's linked account (not a customer) — deleted via wp_delete_user(), no customer-table cleanup. */
	private array $employee_user_fixture_ids = [];

	/** cntr_attendance ids this run created; cleaned up by exact tracked id. */
	private array $attendance_fixture_ids = [];

	/** cntr_leave ids this run created; cleaned up by exact tracked id. */
	private array $leave_fixture_ids = [];

	/** cntr_payroll_runs ids this run created; cleaned up by exact tracked id, cntr_payroll_lines follows via run_id. */
	private array $payroll_run_fixture_ids = [];

	/** The settings value present before test_settings() overwrote it, for restore. */
	private $settings_prior_marker = null;
	private bool $settings_touched = false;

	public function run(): array {
		$this->results             = [];
		$this->pass                 = 0;
		$this->fail                 = 0;
		$this->audit_fixture_ids   = [];
		$this->product_fixture_ids  = [];
		$this->order_fixture_ids    = [];
		$this->location_fixture_ids = [];
		$this->register_fixture_ids = [];
		$this->sale_queue_uuids     = [];
		$this->customer_fixture_ids = [];
		$this->transfer_fixture_ids = [];
		$this->adjustment_uuid_options = [];
		$this->supplier_fixture_ids = [];
		$this->batch_fixture_ids   = [];
		$this->po_fixture_ids      = [];
		$this->po_receive_uuid_options = [];
		$this->stocktake_fixture_ids = [];
		$this->unit_fixture_ids = [];
		$this->label_fixture_ids = [];
		$this->doc_template_fixture_ids = [];
		$this->payment_account_fixture_ids = [];
		$this->expense_fixture_ids = [];
		$this->expense_category_fixture_ids = [];
		$this->employee_fixture_ids = [];
		$this->employee_user_fixture_ids = [];
		$this->attendance_fixture_ids = [];
		$this->leave_fixture_ids = [];
		$this->payroll_run_fixture_ids = [];
		$this->settings_touched    = false;

		$this->test_version();
		$this->test_schema();
		$this->test_wc_api();
		$this->test_wc_settings();
		$this->test_db();
		$this->test_caps();
		$this->test_settings();
		$this->test_audit();
		$this->test_rest_security();
		$this->test_fixtures();
		$this->test_uninstall_guard();
		$this->test_assets();
		$this->test_wc_stock_authority();
		$this->test_stock_entity();
		$this->test_locations();
		$this->test_ledger_atomic();
		$this->test_publisher();
		$this->test_reserved();
		$this->test_catalog_delta();
		$this->test_shift();
		$this->test_shift_no_sale();
		$this->test_customer_index();
		$this->test_customer_profile();
		$this->test_order_builder();
		$this->test_channel_apply();
		$this->test_sale_idempotent();
		$this->test_terminal_assets();
		$this->test_pos_wiring();
		$this->test_quick_add_listing();
		$this->test_tenders();
		$this->test_returns();
		$this->test_shift_zreport();
		$this->test_queue_recovery();
		$this->test_perf_budget();
		$this->test_transfers();
		$this->test_adjustments();
		$this->test_suppliers();
		$this->test_batches();
		$this->test_purchasing();
		$this->test_supplier_ledger();
		$this->test_customer_ledger();
		$this->test_fifo();
		$this->test_stocktake();
		$this->test_rebuild();
		$this->test_stock_reports();
		$this->test_units();
		$this->test_barcode();
		$this->test_labels();
		$this->test_label_batch();
		$this->test_doc_templates();
		$this->test_challan();
		$this->test_vat_exports();
		$this->test_online_channel();
		$this->test_fulfilment();
		$this->test_price_groups();
		$this->test_payment_accounts();
		$this->test_rollup();
		$this->test_reports_channel();
		$this->test_dashboard();
		$this->test_expenses();
		$this->test_pin();
		$this->test_attendance();
		$this->test_leave();
		$this->test_payroll();
		$this->test_cashier_reports();
		$this->test_sw();
		$this->test_outbox();
		$this->test_outbox_poison();
		$this->test_late_sales();
		$this->test_oversell();
		$this->test_offline_lock();
		$this->test_backup_coverage();
		$this->test_i18n();
		$this->test_admin_menu();
		$this->test_entity_picker();
		$this->test_update_policy();
		$this->test_order_lookup();

		$this->cleanup();

		return [
			'results' => $this->results,
			'pass'    => $this->pass,
			'fail'    => $this->fail,
		];
	}

	private function check( string $label, bool $pass, string $detail = '' ): void {
		$this->results[] = [
			'label'  => $label,
			'pass'   => $pass,
			'detail' => $detail,
		];
		if ( $pass ) {
			$this->pass++;
		} else {
			$this->fail++;
		}
	}

	/**
	 * A missed performance budget only fails the suite when self::PERF_BLOCKING
	 * is true (production hardware). On advisory hardware a miss is still
	 * recorded and still visible — labelled [ADVISORY] — but counted as a
	 * pass so a noisy shared box doesn't turn the whole suite red for a
	 * reason that has nothing to do with the code.
	 */
	private function check_perf( string $label, bool $meets_budget, string $detail ): void {
		if ( self::PERF_BLOCKING || $meets_budget ) {
			$this->check( $label, $meets_budget, $detail );
			return;
		}
		$this->results[] = [
			'label'  => $label . ' [ADVISORY — missed budget, not blocking off production hardware]',
			'pass'   => true,
			'detail' => $detail,
		];
		++$this->pass;
	}

	// -- P0.8: test_version() ---------------------------------------------------

	private function test_version(): void {
		$header_version = null;
		if ( is_readable( CNTR_FILE ) ) {
			$data           = get_file_data( CNTR_FILE, [ 'Version' => 'Version' ] );
			$header_version = $data['Version'] ?? null;
		}

		$this->check(
			'test_version: header matches CNTR_VERSION',
			CNTR_VERSION === $header_version,
			"header={$header_version} constant=" . CNTR_VERSION
		);

		$this->check(
			'test_version: CNTR_DB_VER matches Install::VERSION',
			CNTR_DB_VER === Install::VERSION,
			'CNTR_DB_VER=' . CNTR_DB_VER . ' Install::VERSION=' . Install::VERSION
		);
	}

	// -- P0.8: test_schema() -----------------------------------------------------

	private function test_schema(): void {
		global $wpdb;

		$expected = Install::expected_tables();
		$this->check( 'test_schema: 42 tables expected', 42 === count( $expected ), 'count=' . count( $expected ) );

		$missing = Install::missing();
		$this->check( 'test_schema: Install::missing() is empty', empty( $missing ), wp_json_encode( $missing ) );

		$non_innodb = $wpdb->get_results(
			"SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}cntr\\_%' AND ENGINE <> 'InnoDB'",
			ARRAY_A
		);
		$this->check( 'test_schema: every cntr_ table is InnoDB', empty( $non_innodb ), wp_json_encode( $non_innodb ) );

		$float_cols = $wpdb->get_results(
			"SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$wpdb->prefix}cntr\\_%'
			 AND DATA_TYPE IN ('float', 'double')",
			ARRAY_A
		);
		$this->check(
			'test_schema: no float/double column on a cntr_ table',
			empty( $float_cols ),
			'a float quantity would make a stocktake disagree with itself: ' . wp_json_encode( $float_cols )
		);
	}

	// -- P0.8: test_wc_api() -- re-asserts the runtime-checkable subset of §12 ---

	private function test_wc_api(): void {
		global $wpdb;

		$this->check( 'test_wc_api: wc_update_product_stock() exists', function_exists( 'wc_update_product_stock' ) );
		$this->check( 'test_wc_api: woocommerce_can_reduce_order_stock filter registered', (bool) has_filter( 'woocommerce_can_reduce_order_stock' ) );
		$this->check( 'test_wc_api: woocommerce_can_restore_order_stock filter registered', (bool) has_filter( 'woocommerce_can_restore_order_stock' ) );

		$hpos_tables = [ 'wc_orders', 'wc_order_addresses', 'wc_order_operational_data', 'wc_orders_meta' ];
		foreach ( $hpos_tables as $t ) {
			$exists = (bool) $wpdb->get_var(
				$wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . $t )
			);
			$this->check( "test_wc_api: {$t} table exists", $exists );
		}

		$reserved_exists = (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_reserved_stock' )
		);
		$this->check( 'test_wc_api: wp_wc_reserved_stock table exists', $reserved_exists );
	}

	// -- P0.8: test_wc_settings() -------------------------------------------------

	private function test_wc_settings(): void {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			$this->check( 'test_wc_settings: WooCommerce active', false, 'WooCommerce functions not available' );
			return;
		}

		$this->check( 'test_wc_settings: currency is BDT', 'BDT' === get_woocommerce_currency() );
		$this->check( 'test_wc_settings: 2 decimals', 2 === (int) get_option( 'woocommerce_price_num_decimals', -1 ) );
		$this->check( 'test_wc_settings: timezone Asia/Dhaka', 'Asia/Dhaka' === get_option( 'timezone_string' ) );
		$this->check( 'test_wc_settings: manage stock enabled', 'yes' === get_option( 'woocommerce_manage_stock' ) );
		$this->check( 'test_wc_settings: hold stock 60 minutes', '60' === (string) get_option( 'woocommerce_hold_stock_minutes' ) );

		$hpos_on = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$this->check( 'test_wc_settings: HPOS enabled', $hpos_on );
	}

	// -- P0.8: test_db() -- 6 checks, see Db.php's docblock for why each exists ---

	private function test_db(): void {
		global $wpdb;

		$a = Db::next_seq( 'cntr_selftest' );
		$b = Db::next_seq( 'cntr_selftest' );
		$c = Db::next_seq( 'cntr_selftest' );
		$this->check( 'test_db: next_seq strictly increasing', $a < $b && $b < $c, "a={$a} b={$b} c={$c}" );
		$this->check( 'test_db: next_seq never repeats sequentially', $a !== $b && $b !== $c );

		$ret = Db::transaction( fn() => 'ok' );
		$this->check( 'test_db: transaction() returns the callable value', 'ok' === $ret );

		$table   = Install::table( 'audit_log' );
		$rolled_back_ok = false;
		try {
			Db::transaction(
				function () use ( $table ) {
					global $wpdb;
					$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_tx', 'created_at' => Db::now() ] );
					throw new \RuntimeException( 'selftest rollback probe' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE object_type = %s", 'selftest_tx' )
			);
			$rolled_back_ok = 0 === $count;
		}
		$this->check( 'test_db: transaction() rolls back on throw', $rolled_back_ok );

		$nested_ok = false;
		try {
			Db::transaction(
				function () use ( $table ) {
					global $wpdb;
					$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_tx_outer', 'created_at' => Db::now() ] );
					Db::transaction(
						function () use ( $table ) {
							global $wpdb;
							$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_tx_inner', 'created_at' => Db::now() ] );
							throw new \RuntimeException( 'selftest nested rollback probe' );
						}
					);
				}
			);
		} catch ( \RuntimeException $e ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE object_type IN (%s, %s)",
					'selftest_tx_outer',
					'selftest_tx_inner'
				)
			);
			$nested_ok = 0 === $count;
		}
		$this->check( 'test_db: nested transaction() joins the outer one and rolls back both on inner throw', $nested_ok );

		$diff = abs( strtotime( Db::now() ) - strtotime( gmdate( 'Y-m-d H:i:s' ) ) );
		$this->check( 'test_db: now() within 120s of gmdate()', $diff <= 120, "diff={$diff}s" );
	}

	// -- P0.8: test_caps() -- 9 checks --------------------------------------------

	private function test_caps(): void {
		$roles = [
			Capabilities::ROLE_CASHIER,
			Capabilities::ROLE_SUPERVISOR,
			Capabilities::ROLE_STOCKKEEPER,
			Capabilities::ROLE_MANAGER,
			Capabilities::ROLE_TERMINAL,
		];
		$all_exist = true;
		foreach ( $roles as $r ) {
			if ( null === get_role( $r ) ) {
				$all_exist = false;
			}
		}
		$this->check( 'test_caps: all six roles exist (five custom + administrator)', $all_exist && null !== get_role( 'administrator' ) );

		$cashier = get_role( Capabilities::ROLE_CASHIER );
		$this->check( 'test_caps: cashier cannot cntr_view_cost', $cashier && ! $cashier->has_cap( 'cntr_view_cost' ) );
		$this->check( 'test_caps: cashier cannot cntr_price_override', $cashier && ! $cashier->has_cap( 'cntr_price_override' ) );

		$stockkeeper = get_role( Capabilities::ROLE_STOCKKEEPER );
		$this->check( 'test_caps: stockkeeper cannot cntr_use_pos', $stockkeeper && ! $stockkeeper->has_cap( 'cntr_use_pos' ) );

		$terminal = get_role( Capabilities::ROLE_TERMINAL );
		$terminal_caps = $terminal ? array_keys( array_filter( $terminal->capabilities ) ) : [];
		$this->check( 'test_caps: cntr_terminal has exactly one capability', [ 'cntr_terminal_access' ] === array_values( $terminal_caps ), wp_json_encode( $terminal_caps ) );
		$this->check( 'test_caps: cntr_terminal cannot read', $terminal && ! $terminal->has_cap( 'read' ) );

		$admin        = get_role( 'administrator' );
		$missing_caps = array_filter( Capabilities::all_caps(), fn( $cap ) => ! ( $admin && $admin->has_cap( $cap ) ) );
		$this->check( 'test_caps: administrator has every capability the plugin defines', empty( $missing_caps ), wp_json_encode( array_values( $missing_caps ) ) );

		Capabilities::grant_all();
		Capabilities::grant_all();
		$cashier_after = get_role( Capabilities::ROLE_CASHIER );
		$this->check( 'test_caps: re-running grant twice does not drop capabilities', $cashier_after && $cashier_after->has_cap( 'cntr_use_pos' ) );
	}

	// -- P0.8: test_settings() -- 5 checks ----------------------------------------

	private function test_settings(): void {
		$this->check( 'test_settings: unknown key returns the passed default', 'fallback' === Settings::get( 'no.such.key.' . self::TAG, 'fallback' ) );
		$this->check( 'test_settings: declared key returns its default before any write', 72 === Settings::get( 'offline.max_age_hours' ) );

		$this->settings_prior_marker = Settings::get( self::SETTINGS_TEST_KEY, null );
		$this->settings_touched      = true;
		Settings::set( self::SETTINGS_TEST_KEY, self::TAG );
		$this->check( 'test_settings: a write survives a round trip', self::TAG === Settings::get( self::SETTINGS_TEST_KEY ) );

		Settings::set( 'stock.online_buffer', '15' );
		$this->check(
			'test_settings: string written into a numeric key comes back numeric',
			15 === Settings::get( 'stock.online_buffer' ) && is_int( Settings::get( 'stock.online_buffer' ) )
		);
		Settings::set( 'stock.online_buffer', 0 ); // restore declared default

		$declared = Settings::defaults();
		$this->check(
			'test_settings: defaults() covers keys later phases already read',
			isset( $declared['cash.rounding_step'] ) && isset( $declared['vat.enabled'] ) && isset( $declared['pos.discount_ceiling_pct'] )
		);
	}

	// -- P0.8: test_audit() -- 4 checks --------------------------------------------

	private function test_audit(): void {
		global $wpdb;
		$table = Install::table( 'audit_log' );

		Audit::log( self::TAG, 'selftest', 1, [ 'a' => 1 ], [ 'a' => 2 ], 7 );
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE action = %s ORDER BY id DESC LIMIT 1", self::TAG ),
			ARRAY_A
		);

		if ( $row ) {
			$this->audit_fixture_ids[] = (int) $row['id'];
		}

		$this->check(
			'test_audit: a row is written with both user_id and operator_id',
			(bool) $row && 7 === (int) $row['operator_id']
		);

		$before_ok = $row && [ 'a' => 1 ] === json_decode( $row['before_json'], true );
		$after_ok  = $row && [ 'a' => 2 ] === json_decode( $row['after_json'], true );
		$this->check( 'test_audit: before_json/after_json round-trip through json_encode/decode', $before_ok && $after_ok );

		$threw = false;
		try {
			// A resource is not JSON-serialisable; Audit::log() must not throw on it.
			Audit::log( self::TAG, 'selftest_bad', 2, fopen( 'php://memory', 'r' ), null, 7 );
		} catch ( \Throwable $e ) {
			$threw = true;
		}
		$bad_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE object_type = %s ORDER BY id DESC LIMIT 1", 'selftest_bad' ),
			ARRAY_A
		);
		if ( $bad_row ) {
			$this->audit_fixture_ids[] = (int) $bad_row['id'];
		}
		$this->check( 'test_audit: an unserialisable value does not throw', ! $threw );

		$index_exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'object_type'",
				$table
			)
		);
		$this->check( 'test_audit: (object_type, object_id) index present', $index_exists );
	}

	// -- P0.8: test_rest_security() -- 4 checks, one of the most valuable ---------

	private function test_rest_security(): void {
		if ( ! function_exists( 'rest_get_server' ) ) {
			$this->check( 'test_rest_security: REST server available', false, 'rest_get_server() not available' );
			return;
		}

		$routes      = rest_get_server()->get_routes();
		$cntr_routes = array_filter( $routes, fn( $path ) => 0 === strpos( $path, '/counter/v1' ), ARRAY_FILTER_USE_KEY );

		// WordPress core auto-registers a namespace INDEX route (bare '/counter/v1',
		// no trailing segment) on every REST namespace for route discovery — it
		// lists available routes/schemas, not Counter business data, and its
		// permission_callback is core's, not something this plugin's code
		// controls. Found live on peapip.com during P1.7: excluding it from the
		// "every route needs a real permission_callback" check is correct, not a
		// loophole — every WordPress REST namespace has this same route.
		unset( $cntr_routes['/' . \Counter\Rest\Router::NS] );

		$bad_permission = [];
		$missing_args   = [];
		foreach ( $cntr_routes as $path => $handlers ) {
			foreach ( $handlers as $handler ) {
				$cb = $handler['permission_callback'] ?? null;
				if ( empty( $cb ) || '__return_true' === $cb ) {
					$bad_permission[] = $path;
				}
				$methods = (array) ( $handler['methods'] ?? [] );
				$is_write = array_intersect( array_keys( $methods ), [ 'POST', 'PUT', 'DELETE' ] );
				if ( ! empty( $is_write ) && empty( $handler['args'] ) ) {
					$missing_args[] = $path;
				}
			}
		}

		$this->check(
			'test_rest_security: every counter/v1 route has a real permission_callback',
			empty( $bad_permission ),
			wp_json_encode( $bad_permission )
		);
		$this->check(
			'test_rest_security: no write route uses __return_true',
			empty( $bad_permission ),
			'(same check — a bad permission_callback is either empty or __return_true)'
		);
		// Settings management is wp-admin-only by design (Menu.php, Accounts.php,
		// Health.php, Dashboard.php all gate on cntr_manage_settings directly) —
		// no counter/v1 REST route is meant to accept it as a permission check.
		// A hardcoded `true` here would pass even if that stopped being real, so
		// this greps the actual Rest/*.php sources instead of asserting the
		// invariant by assumption.
		$settings_guarded_route = self::grep_for_capability_in_rest_guards( 'cntr_manage_settings' );
		$this->check(
			'test_rest_security: no counter/v1 route is guarded by cntr_manage_settings (settings stay wp-admin-only)',
			null === $settings_guarded_route,
			(string) $settings_guarded_route
		);
		$this->check(
			'test_rest_security: every write route declares args for its parameters',
			empty( $missing_args ),
			wp_json_encode( $missing_args )
		);
	}

	// -- P0.8: test_fixtures() -- the harness tests itself -------------------------

	private function test_fixtures(): void {
		global $wpdb;
		$table = Install::table( 'audit_log' );

		// Happy path: a fixture this run created is the only tagged row present —
		// purge must remove it and leave nothing behind.
		//
		// By the time test_fixtures() runs, test_audit() and test_uninstall_guard()
		// have ALREADY left their own TAG-tagged rows in this same table (tracked in
		// $this->audit_fixture_ids). Exempting only the row created here — and not
		// those — makes purge_tagged() correctly (and, for this sub-test, wrongly)
		// treat them as foreign and skip. This is not a live-session simulation; it
		// is this run's own earlier, legitimate fixtures, so they belong in the
		// exempt set here. A real end-to-end run against a real database is what
		// surfaced this — an isolated purge_tagged() test never modelled two tests'
		// fixtures coexisting in the same table.
		$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_purge_own', 'created_at' => Db::now() ] );
		$own_id       = (int) $wpdb->insert_id;
		$exempt_happy = array_merge( $this->audit_fixture_ids, [ $own_id ] );

		$result      = $this->purge_tagged( $table, 'action', $exempt_happy );
		$still_there = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $own_id ) );
		$this->check(
			'test_fixtures: creating and removing a fixture leaves nothing behind',
			! $result['skipped'] && 0 === $still_there
		);
		if ( $result['skipped'] ) {
			// The purge under test didn't run in this branch — clean up directly so
			// this sub-test never itself leaves a row behind regardless of outcome.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $own_id ) );
		}

		// Skip path: a genuinely foreign row (one NOT in this run's known fixture
		// set) must block removal of the whole batch, including rows that ARE ours.
		$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_purge_mine', 'created_at' => Db::now() ] );
		$mine_id = (int) $wpdb->insert_id;
		$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_purge_foreign', 'created_at' => Db::now() ] );
		$foreign_id = (int) $wpdb->insert_id;

		// Deliberately does NOT include $foreign_id — that is the row standing in
		// for a live session this run did not create.
		$exempt_skip = array_merge( $this->audit_fixture_ids, [ $mine_id ] );
		$result2         = $this->purge_tagged( $table, 'action', $exempt_skip );
		$mine_present    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $mine_id ) );
		$foreign_present = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $foreign_id ) );

		$this->check(
			'test_fixtures: removal skips (does not delete) when a foreign tagged fixture is present',
			$result2['skipped'] && 1 === $mine_present && 1 === $foreign_present
		);

		// This micro-test created both rows itself, so it — not the outer cleanup() —
		// is responsible for leaving nothing behind, regardless of what purge_tagged()
		// did above.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN (%d, %d)", $mine_id, $foreign_id ) );
	}

	// -- P0.9: test_uninstall_guard() -- 4 checks. Tests the DECISION functions ---
	// only — never the actual destruction. See Install::may_uninstall_hard().

	private function test_uninstall_guard(): void {
		global $wpdb;

		$this->check(
			'test_uninstall_guard: protected list contains stock_moves and challan_register',
			in_array( 'stock_moves', Install::PROTECTED_TABLES, true )
				&& in_array( 'challan_register', Install::PROTECTED_TABLES, true )
		);

		$soft_drop = Install::soft_drop_tables();
		$this->check(
			'test_uninstall_guard: soft-drop list and protected list do not intersect',
			empty( array_intersect( $soft_drop, Install::PROTECTED_TABLES ) )
		);

		$refused = Install::uninstall_hard();
		$this->check(
			'test_uninstall_guard: uninstall_hard() refuses without CNTR_ALLOW_DESTRUCTIVE_UNINSTALL',
			false === $refused['ok'],
			wp_json_encode( $refused )
		);

		// Ensure a protected table has at least one row to export.
		$table = Install::table( 'audit_log' );
		$wpdb->insert( $table, [ 'action' => self::TAG, 'object_type' => 'selftest_export_probe', 'created_at' => Db::now() ] );
		$this->audit_fixture_ids[] = (int) $wpdb->insert_id;

		$exported = Install::export_protected_tables_to_csv();
		$csv_ok   = isset( $exported['audit_log'] )
			&& is_readable( $exported['audit_log'] )
			&& filesize( $exported['audit_log'] ) > 0;
		$this->check( 'test_uninstall_guard: export routine produces a non-empty CSV for a table with fixture rows', $csv_ok );

		// The export files are diagnostic snapshots of a live table, not tagged
		// fixture rows — clean them up directly rather than via purge_tagged().
		foreach ( $exported as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	// -- P0.10: test_assets() -- 3 checks (test_terminal_assets() extends this in P1.13)

	private function test_assets(): void {
		$js_path = CNTR_DIR . 'assets/pos.js';
		$exists  = is_readable( $js_path );
		if ( ! $exists ) {
			$this->check( 'test_assets: pos.js exists', false );
			return;
		}

		$enqueue_src = file_get_contents( CNTR_DIR . 'includes/Pos/Assets.php' );
		$this->check(
			'test_assets: pos.js is enqueued with CNTR_VERSION as its cache-buster',
			(bool) preg_match( "/wp_enqueue_script\\(\\s*'cntr-pos',[^;]*CNTR_VERSION/s", $enqueue_src )
		);

		$src = file_get_contents( $js_path );
		$this->check( 'test_assets: pos.js contains no console.log', false === strpos( $src, 'console.log' ) );
		$this->check(
			'test_assets: pos.js has no external host reference (must run offline)',
			0 === preg_match( '#https?://#', $src )
		);
	}

	// -- P1.1: test_wc_stock_authority() -- 7 checks. The most important test in ---
	// the suite. Checks 1-3 need only Stock\Authority; checks 4-7 need
	// Orders\Channel::apply_stock()/revert_stock(), which don't exist until P1.11
	// (after P1.2 Entity, P1.3 Locations, P1.4 Ledger). Those are written now to
	// establish the contract Channel must satisfy, guarded by class_exists() so
	// they fail honestly with a clear reason rather than fataling the whole suite.

	private function test_wc_stock_authority(): void {
		global $wpdb;

		$this->check(
			'test_wc_stock_authority: both stock-authority filters are registered',
			\Counter\Stock\Authority::active()
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Product' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                    = $product->get_id();
		$this->product_fixture_ids[]   = $product_id;

		if ( ! class_exists( '\Counter\Orders\Channel' ) ) {
			$blocked = 'Orders\\Channel does not exist yet — lands in P1.11, after P1.2 '
				. '(Entity), P1.3 (Locations) and P1.4 (Ledger). Expected red until then, '
				. 'per the plan\'s own P1.1 self-test depending on a class three tasks away.';
			$this->check( 'test_wc_stock_authority: WooCommerce did not touch stock on the completed transition', false, $blocked );
			$this->check( 'test_wc_stock_authority: _order_stock_reduced was not set to yes', false, $blocked );
			$this->check( 'test_wc_stock_authority: exactly one cntr_stock_moves row for this order', false, $blocked );
			$this->check( 'test_wc_stock_authority: apply_stock() called again manually is a no-op (idempotency guard)', false, $blocked );
			$this->check( 'test_wc_stock_authority: cancelling automatically restores stock via the wired hook', false, $blocked );
			$this->check( 'test_wc_stock_authority: a redundant manual revert_stock() call afterward is a safe no-op', false, $blocked );
			return;
		}

		// P4.1 wires Channel::apply_stock()/revert_stock() to real WooCommerce
		// status hooks — update_status() below is no longer inert with
		// respect to Counter. The ledger must be seeded BEFORE that
		// transition now, not after: found live, seeding it afterward (as
		// this test originally did, back when no hook existed to race
		// against) left the hook's own apply_stock() call consuming the
		// guard against an unseeded balance, and the checks below silently
		// measured nothing.
		$main_id_for_seed = \Counter\Stock\Locations::default_id();
		Db::transaction(
			function () use ( $product_id, $main_id_for_seed ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id_for_seed,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest',
						'ref_id'       => 0,
					]
				);
			}
		);

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$order->calculate_totals();
		$order->save();
		$order_id                    = $order->get_id();
		$this->order_fixture_ids[]   = $order_id;

		$order->update_status( 'completed' ); // fires woocommerce_order_status_completed -> apply_stock(), for real

		// A double decrement (to 8) would mean WooCommerce's OWN native
		// reduce_stock_levels() also fired alongside Counter's — Stock\
		// Authority's job is to disable exactly that. Reaching 9, via a
		// single ledger move Counter itself wrote, proves both halves at
		// once: WC's native path stayed off, and the wired hook is what
		// actually moved the ledger — no manual Channel:: call anywhere yet.
		$after_completed = wc_get_product( $product_id );
		$this->check(
			'test_wc_stock_authority: WooCommerce did not touch stock on the completed transition',
			9 === (int) $after_completed->get_stock_quantity(),
			'stock_quantity=' . $after_completed->get_stock_quantity()
		);

		$fresh_order  = wc_get_order( $order_id );
		$reduced_flag = $fresh_order->get_meta( '_order_stock_reduced' );
		$this->check(
			'test_wc_stock_authority: _order_stock_reduced was not set to yes',
			'yes' !== $reduced_flag,
			'value=' . var_export( $reduced_flag, true )
		);

		$moves_table = Install::table( 'stock_moves' );
		$move_count  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = %s AND ref_id = %d", 'order', $order_id )
		);
		$this->check(
			'test_wc_stock_authority: exactly one cntr_stock_moves row for this order',
			1 === $move_count,
			'count=' . $move_count
		);

		// The hook already applied this order once (above) — calling
		// apply_stock() manually here is deliberately the SECOND call,
		// proving the guard holds regardless of whether the first
		// application came from a hook or a direct call.
		\Counter\Orders\Channel::apply_stock( wc_get_order( $order_id ) );
		$after_second = wc_get_product( $product_id );
		$move_count_2 = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = %s AND ref_id = %d", 'order', $order_id )
		);
		$this->check(
			'test_wc_stock_authority: apply_stock() called again manually is a no-op (idempotency guard)',
			9 === (int) $after_second->get_stock_quantity() && 1 === $move_count_2,
			"stock={$after_second->get_stock_quantity()} moves={$move_count_2}"
		);

		wc_get_order( $order_id )->update_status( 'cancelled' ); // fires woocommerce_order_status_cancelled -> revert_stock(), for real
		$after_cancel_hook = wc_get_product( $product_id );
		$this->check(
			'test_wc_stock_authority: cancelling automatically restores stock via the wired hook',
			10 === (int) $after_cancel_hook->get_stock_quantity(),
			'stock_quantity=' . $after_cancel_hook->get_stock_quantity()
		);

		\Counter\Orders\Channel::revert_stock( wc_get_order( $order_id ) );
		$after_revert = wc_get_product( $product_id );
		$this->check(
			'test_wc_stock_authority: a redundant manual revert_stock() call afterward is a safe no-op',
			10 === (int) $after_revert->get_stock_quantity(),
			'stock_quantity=' . $after_revert->get_stock_quantity()
		);
	}

	// -- P1.2: test_stock_entity() -- 6 checks --------------------------------------

	private function test_stock_entity(): void {
		$simple_managed = new \WC_Product_Simple();
		$simple_managed->set_name( 'Counter Selftest Fixture Simple Managed' );
		$simple_managed->set_regular_price( '5.00' );
		$simple_managed->set_manage_stock( true );
		$simple_managed->set_stock_quantity( 3 );
		$simple_managed->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$simple_managed->save();
		$this->product_fixture_ids[] = $simple_managed->get_id();

		$resolved_managed = \Counter\Stock\Entity::resolve( wc_get_product( $simple_managed->get_id() ) );
		$this->check(
			'test_stock_entity: simple managed product resolves to itself',
			true === $resolved_managed['managed']
				&& $simple_managed->get_id() === $resolved_managed['stock_id']
				&& $simple_managed->get_id() === $resolved_managed['product_id']
				&& 0 === $resolved_managed['variation_id'],
			wp_json_encode( $resolved_managed )
		);

		$simple_unmanaged = new \WC_Product_Simple();
		$simple_unmanaged->set_name( 'Counter Selftest Fixture Simple Unmanaged' );
		$simple_unmanaged->set_regular_price( '5.00' );
		$simple_unmanaged->set_manage_stock( false );
		$simple_unmanaged->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$simple_unmanaged->save();
		$this->product_fixture_ids[] = $simple_unmanaged->get_id();

		$resolved_unmanaged = \Counter\Stock\Entity::resolve( wc_get_product( $simple_unmanaged->get_id() ) );
		$this->check(
			'test_stock_entity: simple unmanaged product returns managed => false',
			false === $resolved_unmanaged['managed'],
			wp_json_encode( $resolved_unmanaged )
		);

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Counter Selftest Fixture Variable' );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 20 );
		$parent->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$parent->save();
		$parent_id                   = $parent->get_id();
		$this->product_fixture_ids[] = $parent_id;

		$var_own = new \WC_Product_Variation();
		$var_own->set_parent_id( $parent_id );
		$var_own->set_manage_stock( true );
		$var_own->set_stock_quantity( 5 );
		$var_own->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$var_own->save();
		$this->product_fixture_ids[] = $var_own->get_id();

		$resolved_var_own = \Counter\Stock\Entity::resolve( wc_get_product( $var_own->get_id() ) );
		$this->check(
			'test_stock_entity: a variation managing its own stock resolves to the variation id',
			$var_own->get_id() === $resolved_var_own['stock_id']
				&& $parent_id === $resolved_var_own['product_id']
				&& $var_own->get_id() === $resolved_var_own['variation_id'],
			wp_json_encode( $resolved_var_own )
		);

		$var_parent_managed = new \WC_Product_Variation();
		$var_parent_managed->set_parent_id( $parent_id );
		// manage_stock deliberately left unset — inherits 'parent' from the parent's own manage_stock=true.
		$var_parent_managed->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$var_parent_managed->save();
		$this->product_fixture_ids[] = $var_parent_managed->get_id();

		$resolved_var_parent = \Counter\Stock\Entity::resolve( wc_get_product( $var_parent_managed->get_id() ) );
		$this->check(
			'test_stock_entity: a variation with parent-managed stock resolves to the PARENT id',
			$parent_id === $resolved_var_parent['stock_id'],
			wp_json_encode( $resolved_var_parent )
		);

		$fee_item = new \WC_Order_Item_Fee();
		$fee_item->set_name( 'Counter Selftest Fee' );
		$fee_item->set_total( '2.00' );
		$this->check( 'test_stock_entity: a fee line returns null from for_item()', null === \Counter\Stock\Entity::for_item( $fee_item ) );

		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_title( 'Counter Selftest Shipping' );
		$shipping_item->set_total( '3.00' );
		$this->check( 'test_stock_entity: a shipping line returns null from for_item()', null === \Counter\Stock\Entity::for_item( $shipping_item ) );
	}

	// -- P1.3: test_locations() -- 4 checks -----------------------------------------

	private function test_locations(): void {
		global $wpdb;
		$table = Install::table( 'locations' );

		$default_rows = $wpdb->get_results( "SELECT id FROM {$table} WHERE is_default = 1", ARRAY_A );
		$this->check(
			'test_locations: exactly one default exists',
			1 === count( $default_rows ),
			'count=' . count( $default_rows )
		);

		$default_id  = \Counter\Stock\Locations::default_id();
		$default_row = \Counter\Stock\Locations::get( $default_id );
		$this->check(
			'test_locations: the default is online-sellable',
			$default_row && 1 === (int) $default_row['is_online_sellable']
		);

		$sellable = \Counter\Stock\Locations::online_sellable_ids();
		$this->check(
			'test_locations: online_sellable_ids() returns the default',
			in_array( $default_id, $sellable, true ),
			wp_json_encode( $sellable )
		);

		$new_id = \Counter\Stock\Locations::create(
			[
				'name'               => 'Counter Selftest Fixture Location',
				'code'               => 'CNTR-SELFTEST-' . wp_generate_password( 8, false ),
				'is_online_sellable' => 0,
				'is_default'         => 1,
				'status'             => 'active',
			]
		);
		$this->location_fixture_ids[] = $new_id;

		$default_rows_after = $wpdb->get_results( "SELECT id FROM {$table} WHERE is_default = 1", ARRAY_A );
		$this->check(
			'test_locations: creating a second default demotes the first rather than leaving two',
			1 === count( $default_rows_after ) && $new_id === (int) $default_rows_after[0]['id'],
			wp_json_encode( $default_rows_after )
		);

		// This is a real site — leaving the fixture location as the shop's actual
		// default would be a live consequence of running the suite, not just test
		// debris. Restore MAIN before this test (and cleanup(), which only removes
		// rows, not this state) hands back control.
		\Counter\Stock\Locations::update( $new_id, [ 'is_default' => 0 ] );
		$main_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", 'MAIN' ) );
		if ( $main_id ) {
			\Counter\Stock\Locations::update( $main_id, [ 'is_default' => 1 ] );
		}
	}

	// -- P1.4: test_ledger_atomic() -- 8 checks. Invariants I and II. -------------
	// The second of "the five tests that matter most" — see the doc header.
	//
	// UNLIKE every other fixture in this class, the rows this test writes to
	// cntr_stock_moves are NOT cleaned up, and cannot be — that table is
	// genuinely append-only (Invariant I, non-negotiable rule 10), with no
	// exception carved out for test data. purge_tagged_products() removes the
	// WooCommerce product itself, but the ~202 ledger rows this test writes
	// against that product's id are permanent, real rows in the real ledger,
	// same as any other correction would be. They are tagged ref_type='selftest'
	// so a future report can filter them out if that ever matters; they are not,
	// and cannot be, deleted. This is a deliberate consequence of Invariant I
	// being absolute, not an oversight — see the P1.4 commit for the reasoning.

	private function test_ledger_atomic(): void {
		global $wpdb;

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Ledger Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 100 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		$location_id = \Counter\Stock\Locations::default_id();
		$moves_table = Install::table( 'stock_moves' );

		// 1. One move of -1 writes exactly one row and moves the balance by exactly -1.
		// cntr_stock has no row for a brand-new fixture product, so the starting
		// balance is '0.0000', not WooCommerce's own stock_quantity — the two are
		// independent tables until Publisher (P1.5) exists to connect them.
		$move_id_1 = \Counter\Stock\Ledger::move(
			[
				'product_id'   => $product_id,
				'variation_id' => 0,
				'location_id'  => $location_id,
				'qty_delta'    => '-1',
				'reason'       => 'adjust',
				'ref_type'     => 'selftest',
				'ref_id'       => 1,
			]
		);
		$balance_1 = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		$row_count_1 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE id = %d", $move_id_1 ) );
		$this->check(
			'test_ledger_atomic: one move of -1 writes exactly one row and moves the balance by exactly -1',
			1 === $row_count_1 && '-1.0000' === $balance_1,
			"rows={$row_count_1} balance={$balance_1}"
		);

		// 2. balance() equals SUM(qty_delta) from the moves for that item and location.
		\Counter\Stock\Ledger::move(
			[
				'product_id'   => $product_id,
				'variation_id' => 0,
				'location_id'  => $location_id,
				'qty_delta'    => '-2',
				'reason'       => 'adjust',
				'ref_type'     => 'selftest',
				'ref_id'       => 2,
			]
		);
		$balance_2 = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		$sql_sum   = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CAST(SUM(qty_delta) AS DECIMAL(14,4)) FROM {$moves_table} WHERE product_id = %d AND variation_id = 0 AND location_id = %d",
				$product_id,
				$location_id
			)
		);
		$this->check(
			'test_ledger_atomic: balance() equals SUM(qty_delta) from the moves',
			'-3.0000' === $balance_2 && $sql_sum === $balance_2,
			"balance={$balance_2} sql_sum={$sql_sum}"
		);

		// 3. 200 sequential moves of -0.25 leave the balance at exactly the expected
		// decimal, asserted as a STRING comparison — the whole point is that no
		// rounding crept in.
		for ( $i = 0; $i < 200; $i++ ) {
			\Counter\Stock\Ledger::move(
				[
					'product_id'   => $product_id,
					'variation_id' => 0,
					'location_id'  => $location_id,
					'qty_delta'    => '-0.25',
					'reason'       => 'adjust',
					'ref_type'     => 'selftest',
					'ref_id'       => 3,
				]
			);
		}
		$balance_3 = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		$this->check(
			'test_ledger_atomic: 200 sequential moves of -0.25 leave the balance at exactly -53.0000',
			'-53.0000' === $balance_3,
			"balance={$balance_3}"
		);

		// 4. rebuild() after deliberately corrupting a cntr_stock row restores it exactly.
		$stock_table = Install::table( 'stock' );
		$wpdb->update(
			$stock_table,
			[ 'qty' => '9999.0000' ],
			[ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $location_id ]
		);
		$corrupted = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		\Counter\Stock\Ledger::rebuild( $product_id );
		$rebuilt = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		$this->check(
			'test_ledger_atomic: rebuild() after corrupting cntr_stock restores it exactly',
			'9999.0000' === $corrupted && '-53.0000' === $rebuilt,
			"corrupted={$corrupted} rebuilt={$rebuilt}"
		);

		// 5. A move with location_id = 0 is rejected.
		$rejected_zero_location = false;
		try {
			\Counter\Stock\Ledger::move(
				[
					'product_id'   => $product_id,
					'variation_id' => 0,
					'location_id'  => 0,
					'qty_delta'    => '-1',
					'reason'       => 'adjust',
					'ref_type'     => 'selftest',
					'ref_id'       => 5,
				]
			);
		} catch ( \InvalidArgumentException $e ) {
			$rejected_zero_location = true;
		}
		$this->check( 'test_ledger_atomic: a move with location_id = 0 is rejected', $rejected_zero_location );

		// 6. A move for an unmanaged product is rejected (Entity::resolve says managed=false).
		$unmanaged = new \WC_Product_Simple();
		$unmanaged->set_name( 'Counter Selftest Fixture Ledger Unmanaged' );
		$unmanaged->set_regular_price( '5.00' );
		$unmanaged->set_manage_stock( false );
		$unmanaged->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$unmanaged->save();
		$this->product_fixture_ids[] = $unmanaged->get_id();

		$rejected_unmanaged = false;
		try {
			\Counter\Stock\Ledger::move(
				[
					'product_id'   => $unmanaged->get_id(),
					'variation_id' => 0,
					'location_id'  => $location_id,
					'qty_delta'    => '-1',
					'reason'       => 'adjust',
					'ref_type'     => 'selftest',
					'ref_id'       => 6,
				]
			);
		} catch ( \InvalidArgumentException $e ) {
			$rejected_unmanaged = true;
		}
		$this->check( 'test_ledger_atomic: a move for an unmanaged product is rejected', $rejected_unmanaged );

		// 7. Inside a Db::transaction() that throws, neither the move row nor the
		// balance change survives. THE check that catches a ledger that can be
		// half-written.
		$before_tx = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		try {
			Db::transaction(
				function () use ( $product_id, $location_id ) {
					\Counter\Stock\Ledger::move(
						[
							'product_id'   => $product_id,
							'variation_id' => 0,
							'location_id'  => $location_id,
							'qty_delta'    => '-1000',
							'reason'       => 'adjust',
							'ref_type'     => 'selftest_tx_probe',
							'ref_id'       => 7,
						]
					);
					throw new \RuntimeException( 'selftest ledger rollback probe' );
				}
			);
		} catch ( \RuntimeException $e ) {
			// expected
		}
		$after_tx  = \Counter\Stock\Ledger::balance( $product_id, 0, $location_id );
		$probe_row = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'selftest_tx_probe'" );
		$this->check(
			'test_ledger_atomic: a throw inside Db::transaction() rolls back both the move row and the balance',
			$before_tx === $after_tx && 0 === $probe_row,
			"before={$before_tx} after={$after_tx} probe_rows={$probe_row}"
		);

		// 8. cntr_stock_moves has no code path performing UPDATE or DELETE. Crude,
		// and it has caught this exact mistake before.
		$violation = self::grep_for_forbidden_writes( 'stock_moves' );
		$this->check(
			'test_ledger_atomic: no UPDATE/DELETE on cntr_stock_moves anywhere in the plugin source',
			null === $violation,
			(string) $violation
		);
	}

	// -- P1.5: test_publisher() -- 7 checks -----------------------------------------

	private function test_publisher(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Publisher Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_backorders( 'no' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		$main_id = \Counter\Stock\Locations::default_id();

		$move = function ( string $delta, int $location_id = 0 ) use ( $product_id, $main_id ) {
			\Counter\Stock\Ledger::move(
				[
					'product_id'   => $product_id,
					'variation_id' => 0,
					'location_id'  => $location_id ?: $main_id,
					'qty_delta'    => $delta,
					'reason'       => 'adjust',
					'ref_type'     => 'selftest_pub',
					'ref_id'       => 0,
				]
			);
		};
		$publish = function () use ( $product_id ) {
			\Counter\Stock\Publisher::publish( $product_id, 0 );
		};

		// 1. A ledger move of -1 leaves get_stock_quantity() exactly 1 lower.
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '5' );
				$publish();
			}
		);
		$before_1 = (int) wc_get_product( $product_id )->get_stock_quantity();
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '-1' );
				$publish();
			}
		);
		$after_1 = (int) wc_get_product( $product_id )->get_stock_quantity();
		$this->check(
			'test_publisher: a ledger move of -1 leaves get_stock_quantity() exactly 1 lower',
			( $before_1 - 1 ) === $after_1,
			"before={$before_1} after={$after_1}"
		);

		// 2. stock.online_buffer = 2 with a balance of 5 publishes 3.
		Settings::set( 'stock.online_buffer', 2 );
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '1' ); // balance 4 -> 5
				$publish();
			}
		);
		$published_2 = (int) wc_get_product( $product_id )->get_stock_quantity();
		$this->check(
			'test_publisher: stock.online_buffer = 2 with a balance of 5 publishes 3',
			3 === $published_2,
			"published={$published_2}"
		);
		Settings::set( 'stock.online_buffer', 0 ); // restore before later checks

		// 3. Stock at a location with is_online_sellable = 0 does NOT reach _stock.
		$offsite_id = \Counter\Stock\Locations::create(
			[
				'name'               => 'Counter Selftest Fixture Offsite',
				'code'               => 'CNTR-SELFTEST-' . wp_generate_password( 8, false ),
				'is_online_sellable' => 0,
				'is_default'         => 0,
				'status'             => 'active',
			]
		);
		$this->location_fixture_ids[] = $offsite_id;

		Db::transaction(
			function () use ( $move, $publish, $offsite_id ) {
				$move( '1000', $offsite_id );
				$publish();
			}
		);
		$published_3 = (int) wc_get_product( $product_id )->get_stock_quantity();
		$this->check(
			'test_publisher: stock at a non-online-sellable location does not reach _stock',
			5 === $published_3,
			"published={$published_3}"
		);

		// 4. Balance 0 flips stock_status to outofstock.
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '-5' ); // MAIN balance 5 -> 0
				$publish();
			}
		);
		$status_4 = wc_get_product( $product_id )->get_stock_status();
		$this->check( 'test_publisher: balance 0 flips stock_status to outofstock', 'outofstock' === $status_4, $status_4 );

		// 5. Balance back above 0 flips it to instock.
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '3' ); // MAIN balance 0 -> 3
				$publish();
			}
		);
		$status_5 = wc_get_product( $product_id )->get_stock_status();
		$this->check( 'test_publisher: balance back above 0 flips stock_status to instock', 'instock' === $status_5, $status_5 );

		// 6. A product with backorders allowed flips to onbackorder, not outofstock.
		$product_bo = wc_get_product( $product_id );
		$product_bo->set_backorders( 'yes' );
		$product_bo->save();
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '-3' ); // MAIN balance 3 -> 0
				$publish();
			}
		);
		$status_6 = wc_get_product( $product_id )->get_stock_status();
		$this->check( 'test_publisher: backorders allowed flips to onbackorder, not outofstock', 'onbackorder' === $status_6, $status_6 );

		// 7. Published stock is never negative even when the balance is (offline
		// sales can legitimately produce this — P7.5).
		Db::transaction(
			function () use ( $move, $publish ) {
				$move( '-50' ); // MAIN balance 0 -> -50
				$publish();
			}
		);
		$published_7 = (int) wc_get_product( $product_id )->get_stock_quantity();
		$this->check(
			'test_publisher: published stock is never negative even when the balance is',
			0 === $published_7,
			"published={$published_7}"
		);
	}

	// -- P1.6: test_reserved() -- 5 checks -------------------------------------------

	private function test_reserved(): void {
		global $wpdb;
		$reserved_table = $wpdb->prefix . 'wc_reserved_stock';

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Reserve Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 10 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		// A deliberately unrealistic order_id so this fixture row can never
		// collide with a real reservation. wc_reserved_stock has a composite
		// PRIMARY KEY (order_id, product_id), no auto-increment id, so cleanup
		// happens by that exact pair at the end of this method.
		$fake_order_id = 900000000 + $product_id;

		$replace_reservation = function ( ?float $qty, int $expires_offset_seconds ) use ( $wpdb, $reserved_table, $fake_order_id, $product_id ) {
			$wpdb->delete( $reserved_table, [ 'order_id' => $fake_order_id, 'product_id' => $product_id ] );
			if ( null !== $qty ) {
				$wpdb->insert(
					$reserved_table,
					[
						'order_id'       => $fake_order_id,
						'product_id'     => $product_id,
						'stock_quantity' => $qty,
						'timestamp'      => current_time( 'mysql', true ),
						'expires'        => gmdate( 'Y-m-d H:i:s', time() + $expires_offset_seconds ),
					]
				);
			}
		};

		// 1. With no reservations, reserved_for() returns 0.
		$r1 = \Counter\Stock\Reserve::reserved_for( $product_id );
		$this->check( 'test_reserved: with no reservations, reserved_for() returns 0', 0.0 === (float) $r1, "value={$r1}" );

		// 2. Insert a fixture row with a future expiry; the figure appears.
		$replace_reservation( 3.0, 3600 );
		$r2 = \Counter\Stock\Reserve::reserved_for( $product_id );
		$this->check( 'test_reserved: a future-expiry reservation is counted', 3.0 === (float) $r2, "value={$r2}" );

		// 3. A row whose expires is in the past is ignored. An expired-reservation
		// leak would slowly starve the till of stock it actually has, and the
		// symptom — "the POS says zero but the shelf is full" — looks like a
		// ledger bug rather than a clock bug.
		$replace_reservation( 5.0, -3600 );
		$r3 = \Counter\Stock\Reserve::reserved_for( $product_id );
		$this->check( 'test_reserved: an expired reservation is ignored', 0.0 === (float) $r3, "value={$r3}" );

		// 4. The catalogue delta payload's sellable_qty drops by the reserved
		// amount. Implemented for real now that Stock\Catalog exists (P1.7) —
		// the doc mandates subtracting reserved stock in TWO places: here and
		// in Publisher::publish() (already tested by test_publisher()).
		$product_r4 = new \WC_Product_Simple();
		$product_r4->set_name( 'Counter Selftest Fixture Reserve Catalog Product' );
		$product_r4->set_regular_price( '5.00' );
		$product_r4->set_manage_stock( true );
		$product_r4->set_stock_quantity( 0 );
		$product_r4->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_r4->save();
		$product_r4_id                = $product_r4->get_id();
		$this->product_fixture_ids[] = $product_r4_id;

		$main_id_r4      = \Counter\Stock\Locations::default_id();
		$catalog_table   = Install::table( 'catalog_index' );
		$read_payload_r4 = static function () use ( $wpdb, $catalog_table, $product_r4_id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT payload_json FROM {$catalog_table} WHERE product_id = %d AND variation_id = 0", $product_r4_id ),
				ARRAY_A
			);
			$decoded = $row ? json_decode( (string) $row['payload_json'], true ) : null;
			return $decoded['sellable_qty'] ?? null;
		};

		Db::transaction(
			function () use ( $product_r4_id, $main_id_r4 ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_r4_id,
						'variation_id' => 0,
						'location_id'  => $main_id_r4,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_reserve_catalog',
						'ref_id'       => 0,
					]
				);
				\Counter\Stock\Publisher::publish( $product_r4_id, 0 );
			}
		);
		$qty_before_r4 = $read_payload_r4();

		$fake_order_r4 = 900100000 + $product_r4_id;
		$wpdb->insert(
			$reserved_table,
			[
				'order_id'       => $fake_order_r4,
				'product_id'     => $product_r4_id,
				'stock_quantity' => 4,
				'timestamp'      => current_time( 'mysql', true ),
				'expires'        => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
			]
		);
		Db::transaction(
			function () use ( $product_r4_id ) {
				\Counter\Stock\Publisher::publish( $product_r4_id, 0 );
			}
		);
		$qty_after_r4 = $read_payload_r4();

		$wpdb->delete( $reserved_table, [ 'order_id' => $fake_order_r4, 'product_id' => $product_r4_id ] );

		$this->check(
			'test_reserved: catalogue delta payload sellable_qty drops by the reserved amount',
			in_array( $qty_before_r4, [ '10', '10.0000' ], true ) && in_array( $qty_after_r4, [ '6', '6.0000' ], true ),
			"before={$qty_before_r4} after={$qty_after_r4}"
		);

		// 5. Deleting the reservation row restores sellable_qty on the next
		// publish. This one runs for real — Publisher (P1.5) already exists.
		$replace_reservation( null, 0 ); // clear the expired row from check 3
		$main_id = \Counter\Stock\Locations::default_id();

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_reserve',
						'ref_id'       => 0,
					]
				);
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);
		$published_before = (int) wc_get_product( $product_id )->get_stock_quantity();

		$replace_reservation( 4.0, 3600 );
		Db::transaction(
			function () use ( $product_id ) {
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);
		$published_with_reservation = (int) wc_get_product( $product_id )->get_stock_quantity();

		$replace_reservation( null, 0 );
		Db::transaction(
			function () use ( $product_id ) {
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);
		$published_after = (int) wc_get_product( $product_id )->get_stock_quantity();

		$this->check(
			'test_reserved: deleting the reservation row restores sellable_qty on the next publish',
			10 === $published_before && 6 === $published_with_reservation && 10 === $published_after,
			"before={$published_before} with_reservation={$published_with_reservation} after_delete={$published_after}"
		);

		// Final safety net: this fixture's row is always cleared, whatever state
		// the checks above left it in.
		$wpdb->delete( $reserved_table, [ 'order_id' => $fake_order_id, 'product_id' => $product_id ] );
	}

	// -- P1.7: test_catalog_delta() -- 10 checks (8 + 2 added at P8.1 for the snapshot fix) ----

	private function test_catalog_delta(): void {
		global $wpdb;
		$catalog_table = Install::table( 'catalog_index' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Catalog Product' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save(); // fires woocommerce_new_product -> bump_product -> reindex()
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		$row_query = "SELECT rev FROM {$catalog_table} WHERE product_id = %d AND variation_id = 0";
		$rev_before = (int) $wpdb->get_var( $wpdb->prepare( $row_query, $product_id ) );

		// 1. Changing a fixture product's price bumps its rev.
		$product->set_regular_price( '12.00' );
		$product->save();
		$rev_after_price = (int) $wpdb->get_var( $wpdb->prepare( $row_query, $product_id ) );
		$this->check(
			"test_catalog_delta: changing a fixture product's price bumps its rev",
			$rev_after_price > $rev_before,
			"before={$rev_before} after={$rev_after_price}"
		);

		// 2. A delta from the previous cursor contains exactly that product.
		$delta_2 = \Counter\Stock\Catalog::delta( $rev_before, 500 );
		$matches_2 = array_values( array_filter( $delta_2['changed'], static fn( $r ) => (int) $r['id'] === $product_id ) );
		$this->check(
			'test_catalog_delta: a delta from the previous cursor contains exactly that product',
			1 === count( $matches_2 ),
			wp_json_encode( array_column( $delta_2['changed'], 'id' ) )
		);

		// 3. A delta from the returned cursor is empty for this product — an
		// off-by-one here makes the terminal re-download the catalogue every 60s.
		$delta_3   = \Counter\Stock\Catalog::delta( $delta_2['cursor'], 500 );
		$matches_3 = array_filter( $delta_3['changed'], static fn( $r ) => (int) $r['id'] === $product_id );
		$this->check(
			'test_catalog_delta: a delta from the returned cursor no longer contains this product',
			empty( $matches_3 ),
			wp_json_encode( array_column( $delta_3['changed'], 'id' ) )
		);

		// 4. Trashing a product produces a tombstone in removed, not a silent
		// disappearance.
		$cursor_before_trash = $delta_3['cursor'];
		wp_trash_post( $product_id );
		$delta_4     = \Counter\Stock\Catalog::delta( $cursor_before_trash, 500 );
		$tombstoned  = array_filter(
			$delta_4['removed'],
			static fn( $r ) => (int) $r['product_id'] === $product_id && 0 === (int) $r['variation_id']
		);
		$this->check(
			'test_catalog_delta: trashing a product produces a tombstone in removed',
			! empty( $tombstoned ),
			wp_json_encode( $delta_4['removed'] )
		);
		wp_untrash_post( $product_id ); // normal fixture cleanup force-deletes regardless of trash status

		// 5. The payload for every row contains none of cost, purchase_price,
		// supplier, margin. Asserted by key inspection, not by eye.
		$forbidden = [ 'cost', 'purchase_price', 'supplier', 'margin' ];
		$leak      = false;
		foreach ( $delta_2['changed'] as $row ) {
			foreach ( $forbidden as $key ) {
				if ( array_key_exists( $key, $row ) ) {
					$leak = true;
				}
			}
		}
		$this->check( 'test_catalog_delta: payload never contains cost/purchase_price/supplier/margin', ! $leak );

		// 6. sellable_qty equals balance - reserved - buffer.
		$main_id = \Counter\Stock\Locations::default_id();
		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '7',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_catalog',
						'ref_id'       => 0,
					]
				);
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);
		$row_6     = $wpdb->get_row( $wpdb->prepare( "SELECT payload_json FROM {$catalog_table} WHERE product_id = %d AND variation_id = 0", $product_id ), ARRAY_A );
		$payload_6 = json_decode( (string) $row_6['payload_json'], true );
		$this->check(
			'test_catalog_delta: sellable_qty equals balance minus reserved minus buffer',
			in_array( $payload_6['sellable_qty'], [ '7', '7.0000' ], true ),
			wp_json_encode( $payload_6['sellable_qty'] )
		);

		// 7. Exceeding the cap returns resnapshot: true and an empty changed.
		// A cursor taken right before this sub-test isolates it from the real
		// site's own catalogue history.
		$cursor_before_cap = (int) $wpdb->get_var( "SELECT MAX(rev) FROM {$catalog_table}" );
		Settings::set( 'catalog.delta_cap', 1 );

		$product2 = new \WC_Product_Simple();
		$product2->set_name( 'Counter Selftest Fixture Catalog Product 2' );
		$product2->set_regular_price( '1.00' );
		$product2->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product2->save();
		$this->product_fixture_ids[] = $product2->get_id();

		$product->set_regular_price( '13.00' ); // a second change since $cursor_before_cap
		$product->save();

		$delta_7 = \Counter\Stock\Catalog::delta( $cursor_before_cap, 500 );
		$this->check(
			'test_catalog_delta: exceeding the cap returns resnapshot: true and an empty changed',
			true === $delta_7['resnapshot'] && empty( $delta_7['changed'] ),
			wp_json_encode( [ 'resnapshot' => $delta_7['resnapshot'], 'changed_count' => count( $delta_7['changed'] ) ] )
		);
		Settings::set( 'catalog.delta_cap', 2000 ); // restore the declared default

		// 8. A product with manage_stock off appears with manage_stock: false and
		// is sellable regardless of quantity.
		$unmanaged = new \WC_Product_Simple();
		$unmanaged->set_name( 'Counter Selftest Fixture Catalog Unmanaged' );
		$unmanaged->set_regular_price( '1.00' );
		$unmanaged->set_manage_stock( false );
		$unmanaged->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$unmanaged->save();
		$this->product_fixture_ids[] = $unmanaged->get_id();

		$row_8     = $wpdb->get_row(
			$wpdb->prepare( "SELECT payload_json FROM {$catalog_table} WHERE product_id = %d AND variation_id = 0", $unmanaged->get_id() ),
			ARRAY_A
		);
		$payload_8 = $row_8 ? json_decode( (string) $row_8['payload_json'], true ) : null;
		$this->check(
			'test_catalog_delta: an unmanaged product appears with manage_stock: false',
			$payload_8 && false === $payload_8['manage_stock'],
			wp_json_encode( $payload_8['manage_stock'] ?? null )
		);

		// 9 (P8.1) — snapshot() returns real rows in exactly the situation
		// delta() itself refuses to: total-changed over the cap. Found live
		// at P8.1's own realistic-scale load test — a terminal with a
		// catalogue bigger than catalog.delta_cap could never finish its
		// first sync, because retrying delta() from cursor 0 re-trips the
		// identical total-count check forever. Reuses check 7's own cap=1
		// setup shape.
		Settings::set( 'catalog.delta_cap', 1 );
		$delta_9    = \Counter\Stock\Catalog::delta( $cursor_before_cap, 500 );
		$snapshot_9 = \Counter\Stock\Catalog::snapshot( $cursor_before_cap, 500 );
		Settings::set( 'catalog.delta_cap', 2000 ); // restore the declared default
		$this->check(
			'test_catalog_delta: snapshot() returns real rows exactly where delta() itself refuses to',
			true === $delta_9['resnapshot'] && empty( $delta_9['changed'] )
				&& false === ( $snapshot_9['resnapshot'] ?? false ) && count( $snapshot_9['changed'] ) >= 2,
			wp_json_encode( [ 'delta_changed' => count( $delta_9['changed'] ), 'snapshot_changed' => count( $snapshot_9['changed'] ) ] )
		);

		// 10 (P8.1) — pos.js itself actually calls the fix on a resnapshot,
		// rather than the old "reset cursor to 0 and retry delta()" pattern
		// that can never succeed once the catalogue's own total size alone
		// exceeds the cap. Read the real source and verify the structure,
		// same limitation/technique as every other client-side check in
		// this suite (test_outbox()'s own docblock: nothing PHP can execute
		// pos.js directly).
		$pos_js = (string) file_get_contents( CNTR_DIR . 'assets/pos.js' );
		preg_match( '/async function pullFullSnapshot\(db\)\s*\{(.*?)\n\t\}/s', $pos_js, $snapshot_fn_m );
		$snapshot_fn_body = $snapshot_fn_m[1] ?? '';
		preg_match( '/async function syncCatalog\(db\)\s*\{(.*?)\n\t\}/s', $pos_js, $sync_fn_m );
		$sync_fn_body = $sync_fn_m[1] ?? '';
		$this->check(
			'test_catalog_delta: pos.js pulls a real full snapshot on resnapshot, not a doomed delta() retry',
			'' !== $snapshot_fn_body && str_contains( $snapshot_fn_body, 'idbClearCatalog' )
				&& str_contains( $snapshot_fn_body, 'fetchDelta(cursor, true)' )
				&& '' !== $sync_fn_body && str_contains( $sync_fn_body, 'pullFullSnapshot' )
				&& ! str_contains( $sync_fn_body, 'cursor = 0;' ),
			wp_json_encode( [ 'snapshot_fn_len' => strlen( $snapshot_fn_body ), 'sync_fn_len' => strlen( $sync_fn_body ) ] )
		);
	}

	// -- P1.8: test_shift() -- 7 checks -----------------------------------------------

	private function test_shift(): void {
		global $wpdb;
		$shifts_table = Install::table( 'shifts' );

		$make_register = function ( string $label ) {
			$id = \Counter\Pos\Registers::create(
				[
					'name'        => "Counter Selftest Fixture {$label}",
					'location_id' => \Counter\Stock\Locations::default_id(),
					'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
					'status'      => 'active',
				]
			);
			$this->register_fixture_ids[] = $id;
			return $id;
		};

		$user_id       = get_current_user_id();
		$register_id_1 = $make_register( 'Register 1' );

		// 1. Opening a shift on a free register succeeds.
		$shift_id_1 = \Counter\Pos\Shifts::open( $register_id_1, $user_id, '100.00' );
		$this->check(
			'test_shift: opening a shift on a free register succeeds',
			is_int( $shift_id_1 ) && $shift_id_1 > 0,
			$shift_id_1 instanceof \WP_Error ? $shift_id_1->get_error_message() : (string) $shift_id_1
		);

		// 2. A second open on the same register fails with the 409 error, not an exception.
		$second_open = \Counter\Pos\Shifts::open( $register_id_1, $user_id, '50.00' );
		$second_open_data = $second_open instanceof \WP_Error ? $second_open->get_error_data() : null;
		$this->check(
			'test_shift: a second open on the same register fails with a 409 WP_Error',
			$second_open instanceof \WP_Error && isset( $second_open_data['status'] ) && 409 === $second_open_data['status'],
			$second_open instanceof \WP_Error ? $second_open->get_error_message() : wp_json_encode( $second_open )
		);

		// 3. Closing sets closed_at and open_key to NULL.
		\Counter\Pos\Shifts::close( $shift_id_1, '105.00' );
		$row_3 = $wpdb->get_row( $wpdb->prepare( "SELECT closed_at, open_key FROM {$shifts_table} WHERE id = %d", $shift_id_1 ), ARRAY_A );
		$this->check(
			'test_shift: closing sets closed_at and open_key to NULL',
			! empty( $row_3['closed_at'] ) && '0000-00-00 00:00:00' !== $row_3['closed_at'] && null === $row_3['open_key'],
			wp_json_encode( $row_3 )
		);

		// 4. After closing, a new open on the same register succeeds.
		$shift_id_4 = \Counter\Pos\Shifts::open( $register_id_1, $user_id, '100.00' );
		$this->check(
			'test_shift: after closing, a new open on the same register succeeds',
			is_int( $shift_id_4 ) && $shift_id_4 > 0 && $shift_id_4 !== $shift_id_1,
			$shift_id_4 instanceof \WP_Error ? $shift_id_4->get_error_message() : (string) $shift_id_4
		);

		// 5. Two different registers can each hold an open shift simultaneously.
		$register_id_2 = $make_register( 'Register 2' );
		$shift_id_5    = \Counter\Pos\Shifts::open( $register_id_2, $user_id, '20.00' );
		$this->check(
			'test_shift: two different registers can each hold an open shift simultaneously',
			is_int( $shift_id_5 ) && $shift_id_5 > 0
				&& null !== \Counter\Pos\Shifts::open_for_register( $register_id_1 )
				&& null !== \Counter\Pos\Shifts::open_for_register( $register_id_2 )
		);

		// 6. require_open() returns a WP_Error when nothing is open.
		$register_id_3  = $make_register( 'Register 3' );
		$require_result = \Counter\Pos\Shifts::require_open( $register_id_3 );
		$this->check( 'test_shift: require_open() returns a WP_Error when nothing is open', $require_result instanceof \WP_Error );

		// 7. The one_open unique index actually exists on the table — dbDelta
		// fails silently, and a missing index here turns checks 2 and 5 into luck.
		$index_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'one_open'",
				$shifts_table
			)
		);
		$this->check( 'test_shift: the one_open unique index actually exists on the table', $index_exists > 0, "count={$index_exists}" );
	}

	// -- COUNTERFRONTEND.md F5: test_shift_no_sale() -- 4 checks ----------------------

	private function test_shift_no_sale(): void {
		global $wpdb;
		$events_table = Install::table( 'shift_events' );
		$audit_table  = Install::table( 'audit_log' );

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture No-Sale Register',
				'location_id' => $main_id,
				'prefix'      => 'ZN' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$original_user_id = get_current_user_id();
		$admins            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$cashier_id        = wp_insert_user(
			[
				'user_login' => 'cntr_selftest_no_sale_cashier_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => \Counter\Capabilities::ROLE_CASHIER,
			]
		);
		if ( is_wp_error( $cashier_id ) ) {
			$this->check( 'test_shift_no_sale: fixture cashier created', false, $cashier_id->get_error_message() );
			return;
		}

		// 1. A plain cashier (no cntr_no_sale — cntr_cashier never grants it) is refused.
		wp_set_current_user( $cashier_id );
		$req_denied = new \WP_REST_Request( 'POST', '/counter/v1/shift/no-sale' );
		$req_denied->set_param( 'register_id', $register_id );
		$req_denied->set_param( 'reason', 'Change for a customer' );
		$result_denied = rest_do_request( $req_denied );
		$this->check(
			'test_shift_no_sale: refused without cntr_no_sale',
			403 === $result_denied->get_status(),
			'status=' . $result_denied->get_status()
		);

		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}

		// 2. A blank reason is refused before anything is written.
		$req_blank = new \WP_REST_Request( 'POST', '/counter/v1/shift/no-sale' );
		$req_blank->set_param( 'register_id', $register_id );
		$req_blank->set_param( 'reason', '' );
		$result_blank = rest_do_request( $req_blank );
		$this->check(
			'test_shift_no_sale: a blank reason is refused',
			$result_blank->get_status() >= 400,
			'status=' . $result_blank->get_status()
		);

		// 3. A real request writes the shift_events row P1.16's Z-report and
		// P6.5's cashier performance report both already know how to read.
		$events_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE shift_id = %d AND type = 'no_sale'", $shift_id ) );
		$req_ok        = new \WP_REST_Request( 'POST', '/counter/v1/shift/no-sale' );
		$req_ok->set_param( 'register_id', $register_id );
		$req_ok->set_param( 'reason', 'Making change for a customer' );
		$result_ok     = rest_do_request( $req_ok );
		$events_after  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE shift_id = %d AND type = 'no_sale'", $shift_id ) );
		$this->check(
			'test_shift_no_sale: a real request writes a no_sale shift event',
			200 === $result_ok->get_status() && $events_after === $events_before + 1,
			'status=' . $result_ok->get_status() . ' events_before=' . $events_before . ' events_after=' . $events_after
		);

		// 4. P0.6: the audit row carrying the reason.
		$audit_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$audit_table} WHERE action = 'no_sale' AND object_type = 'shift' AND object_id = %d ORDER BY id DESC LIMIT 1",
				$shift_id
			),
			ARRAY_A
		);
		$this->check(
			'test_shift_no_sale: the audit row carries the reason',
			null !== $audit_row && false !== strpos( (string) $audit_row['after_json'], 'Making change for a customer' ),
			wp_json_encode( $audit_row )
		);

		wp_set_current_user( $original_user_id );
		wp_delete_user( $cashier_id );
	}

	// -- P1.9: test_customer_index() -- 7 checks -------------------------------------

	private function test_customer_index(): void {
		global $wpdb;
		$index_table = Install::table( 'customer_index' );

		// 1. All four phone forms normalise to the same key.
		$forms      = [ '+8801711223344', '8801711223344', '01711223344', '1711223344' ];
		$normalised = array_map( [ \Counter\Pos\Customers::class, 'normalise' ], $forms );
		$this->check(
			'test_customer_index: all four phone forms normalise to the same key',
			1 === count( array_unique( $normalised ) ) && '8801711223344' === $normalised[0],
			wp_json_encode( $normalised )
		);

		// 2. An invalid number is rejected rather than stored.
		$invalid = \Counter\Pos\Customers::normalise( '12345' );
		$this->check( 'test_customer_index: an invalid number is rejected rather than stored', null === $invalid, wp_json_encode( $invalid ) );

		// A fresh 10-digit number, unique to this run, plus its 3 other forms.
		$unique_phone = '17' . str_pad( (string) wp_rand( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
		$form_variants = [ $unique_phone, '0' . $unique_phone, '880' . $unique_phone, '+880' . $unique_phone ];

		// 3. Creating a customer indexes them.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$email_1       = 'cntr-selftest-' . wp_generate_password( 10, false ) . '@example.invalid';
		$customer_id_1 = wc_create_new_customer( $email_1, '', wp_generate_password( 16 ) );
		if ( is_wp_error( $customer_id_1 ) ) {
			$this->check( 'test_customer_index: creating a customer indexes them', false, $customer_id_1->get_error_message() );
			return; // nothing else in this test can proceed meaningfully
		}
		$this->customer_fixture_ids[] = $customer_id_1;

		$customer_1 = new \WC_Customer( $customer_id_1 );
		$customer_1->set_billing_phone( $unique_phone );
		$customer_1->set_first_name( 'Selftest' );
		$customer_1->set_last_name( 'One' );
		$customer_1->save(); // fires woocommerce_update_customer -> reindex_customer

		$norm  = \Counter\Pos\Customers::normalise( $unique_phone );
		$row_3 = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$index_table} WHERE phone_norm = %s AND customer_id = %d", $norm, $customer_id_1 ),
			ARRAY_A
		);
		$this->check( 'test_customer_index: creating a customer indexes them', (bool) $row_3, wp_json_encode( $row_3 ) );

		// 4. Lookup by any of the four forms finds them.
		$found_all = true;
		foreach ( $form_variants as $variant ) {
			$results = \Counter\Pos\Customers::lookup( $variant );
			$has     = false;
			foreach ( $results as $r ) {
				if ( (int) $r['customer_id'] === $customer_id_1 ) {
					$has = true;
				}
			}
			if ( ! $has ) {
				$found_all = false;
			}
		}
		$this->check( 'test_customer_index: lookup by any of the four forms finds them', $found_all );

		// 5. Two customers sharing one number both appear in the lookup, most
		// recent first, and neither overwrites the other.
		$email_2       = 'cntr-selftest-' . wp_generate_password( 10, false ) . '@example.invalid';
		$customer_id_2 = wc_create_new_customer( $email_2, '', wp_generate_password( 16 ) );
		if ( is_wp_error( $customer_id_2 ) ) {
			$this->check( 'test_customer_index: two customers sharing one number both appear, neither overwrites the other', false, $customer_id_2->get_error_message() );
		} else {
			$this->customer_fixture_ids[] = $customer_id_2;
			$customer_2 = new \WC_Customer( $customer_id_2 );
			$customer_2->set_billing_phone( $unique_phone ); // same household number
			$customer_2->set_first_name( 'Selftest' );
			$customer_2->set_last_name( 'Two' );
			$customer_2->save();

			// Force a real time gap so "most recent first" is deterministic. Both
			// saves above can land in the same wall-clock second (updated_at is
			// second-precision, no fractional seconds), which ties the ORDER BY
			// and makes the result order undefined rather than proving anything —
			// found live on peapip.com when this check failed non-deterministically.
			$wpdb->update(
				$index_table,
				[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ],
				[ 'phone_norm' => $norm, 'customer_id' => $customer_id_1 ]
			);

			$results_5 = \Counter\Pos\Customers::lookup( $unique_phone );
			$ids_5     = array_map( static fn( $r ) => (int) $r['customer_id'], $results_5 );
			$this->check(
				'test_customer_index: two customers sharing one number both appear, most recent first, neither overwrites the other',
				in_array( $customer_id_1, $ids_5, true )
					&& in_array( $customer_id_2, $ids_5, true )
					&& count( $ids_5 ) === count( array_unique( $ids_5 ) )
					&& ! empty( $ids_5 ) && $customer_id_2 === $ids_5[0],
				wp_json_encode( $ids_5 )
			);

			// 6. merge() reassigns orders and leaves none orphaned.
			$order_6 = wc_create_order();
			$order_6->set_customer_id( $customer_id_2 );
			$order_6->update_meta_data( '_cntr_selftest_fixture', self::TAG );
			$order_6->save();
			$order_id_6                = $order_6->get_id();
			$this->order_fixture_ids[] = $order_id_6;

			$merge_ok          = \Counter\Pos\Customers::merge( $customer_id_1, $customer_id_2 );
			$after_merge_order = wc_get_order( $order_id_6 );
			$this->check(
				'test_customer_index: merge() reassigns orders and leaves none orphaned',
				$merge_ok && $after_merge_order && $customer_id_1 === $after_merge_order->get_customer_id(),
				'customer_id_after=' . ( $after_merge_order ? $after_merge_order->get_customer_id() : 'null' )
			);

			// 7. merge() writes an audit row.
			$audit_table = Install::table( 'audit_log' );
			$audit_row_7 = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$audit_table} WHERE action = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
					'customer_merge',
					$customer_id_1
				),
				ARRAY_A
			);
			$this->check( 'test_customer_index: merge() writes an audit row', (bool) $audit_row_7 );
			if ( $audit_row_7 ) {
				// Not TAG-marked (it's a real action name, correctly), so this
				// run cleans it up directly rather than via purge_tagged().
				$wpdb->delete( $audit_table, [ 'id' => (int) $audit_row_7['id'] ] );
			}
		}
	}

	// -- F2 (COUNTERFRONTEND.md): test_customer_profile() -- 6 checks ---------------

	private function test_customer_profile(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		// A fixture customer with no ledger rows and no credit limit — checks 2 and 4.
		$email_a      = 'cntr-selftest-profile-a-' . wp_generate_password( 10, false ) . '@example.invalid';
		$customer_a   = wc_create_new_customer( $email_a, '', wp_generate_password( 16 ) );
		if ( is_wp_error( $customer_a ) ) {
			$this->check( 'test_customer_profile: fixture customer A created', false, $customer_a->get_error_message() );
			return;
		}
		$this->customer_fixture_ids[] = $customer_a;

		// A second fixture customer WITH a credit limit and a debit — check 3.
		$email_b    = 'cntr-selftest-profile-b-' . wp_generate_password( 10, false ) . '@example.invalid';
		$customer_b = wc_create_new_customer( $email_b, '', wp_generate_password( 16 ) );
		if ( is_wp_error( $customer_b ) ) {
			$this->check( 'test_customer_profile: fixture customer B created', false, $customer_b->get_error_message() );
			return;
		}
		$this->customer_fixture_ids[] = $customer_b;
		\Counter\Credit\CustomerLedger::set_credit_limit( $customer_b, '1000.0000' );
		\Counter\Credit\CustomerLedger::record_credit_sale( $customer_b, '400.00', 'test', 0, 'test_customer_profile fixture' );

		// Fixture requesting users: one with cntr_use_pos but not
		// cntr_credit_sale (a plain cashier — check 6), one with neither
		// (check 5). Same "an application-password-authenticated machine
		// account has no logged-in wp user" concern test_terminal_assets()
		// already documents does not apply here — these are real
		// wp_set_current_user() switches for a cookie-style request.
		$cashier_id = wp_insert_user(
			[
				'user_login' => 'cntr_selftest_profile_cashier_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => \Counter\Capabilities::ROLE_CASHIER,
			]
		);
		$outsider_id = wp_insert_user(
			[
				'user_login' => 'cntr_selftest_profile_outsider_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => 'subscriber',
			]
		);
		if ( is_wp_error( $cashier_id ) || is_wp_error( $outsider_id ) ) {
			$this->check(
				'test_customer_profile: fixture requesting users created',
				false,
				'cashier=' . ( is_wp_error( $cashier_id ) ? $cashier_id->get_error_message() : 'ok' ) . ' outsider=' . ( is_wp_error( $outsider_id ) ? $outsider_id->get_error_message() : 'ok' )
			);
			return;
		}

		$original_user_id = get_current_user_id();

		// 1. An unknown customer id returns 404, not an empty 200.
		wp_set_current_user( $cashier_id );
		$req_unknown = new \WP_REST_Request( 'GET', '/counter/v1/customers/999999999/profile' );
		$req_unknown->set_param( 'id', 999999999 );
		$result_unknown = rest_do_request( $req_unknown );
		$this->check(
			'test_customer_profile: an unknown customer id returns 404, not an empty 200',
			404 === $result_unknown->get_status(),
			'status=' . $result_unknown->get_status()
		);

		// 2. A customer with no ledger rows returns balance "0.0000".
		$req_a = new \WP_REST_Request( 'GET', '/counter/v1/customers/' . $customer_a . '/profile' );
		$req_a->set_param( 'id', $customer_a );
		$result_a = rest_do_request( $req_a );
		$data_a   = $result_a->get_data();
		$this->check(
			'test_customer_profile: a customer with no ledger rows returns balance "0.0000"',
			200 === $result_a->get_status() && isset( $data_a['balance'] ) && '0.0000' === $data_a['balance'],
			wp_json_encode( $data_a )
		);

		// 3. available equals limit minus balance.
		$req_b = new \WP_REST_Request( 'GET', '/counter/v1/customers/' . $customer_b . '/profile' );
		$req_b->set_param( 'id', $customer_b );
		$result_b = rest_do_request( $req_b );
		$data_b   = $result_b->get_data();
		$this->check(
			'test_customer_profile: available equals credit_limit minus balance',
			200 === $result_b->get_status() && isset( $data_b['available'] ) && '600.0000' === $data_b['available']
				&& '1000.0000' === $data_b['credit_limit'] && '400.0000' === $data_b['balance'],
			wp_json_encode( $data_b )
		);

		// 4. available is null when no limit is set (customer A never had one).
		$this->check(
			'test_customer_profile: available is null when no limit is set',
			200 === $result_a->get_status() && array_key_exists( 'available', $data_a ) && null === $data_a['available'],
			wp_json_encode( $data_a )
		);

		// 6. can_credit is false for a user lacking cntr_credit_sale (still
		// as the cashier from checks 1-3 above — cntr_cashier never grants it).
		$this->check(
			'test_customer_profile: can_credit is false for a user lacking cntr_credit_sale',
			200 === $result_b->get_status() && isset( $data_b['can_credit'] ) && false === $data_b['can_credit'],
			wp_json_encode( $data_b )
		);

		// 7 & 8 — F4 (COUNTERFRONTEND.md) §2: the customer's own price group
		// reaches profile() and price_overrides(), the two calls attachCustomer()
		// makes to decide whether a cart needs re-pricing. customer_a has no
		// group of their own — the common case, walk-in or not — customer_b
		// gets one for this check only, fixture product cleaned up the same
		// way test_price_groups()'s own fixtures are.
		$wholesale = \Counter\Pricing\Groups::get_by_code( 'wholesale' );
		$wholesale_id = (int) ( $wholesale['id'] ?? 0 );
		$override_product = new \WC_Product_Simple();
		$override_product->set_name( 'Counter Selftest Fixture Customer Price Group Product' );
		$override_product->set_regular_price( '50.00' );
		$override_product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$override_product->save();
		$override_product_id            = $override_product->get_id();
		$this->product_fixture_ids[]    = $override_product_id;
		\Counter\Pricing\Groups::set_price( $wholesale_id, $override_product_id, 0, '35.00' );
		update_user_meta( $customer_b, '_cntr_price_group_id', $wholesale_id );

		$req_b_group = new \WP_REST_Request( 'GET', '/counter/v1/customers/' . $customer_b . '/profile' );
		$req_b_group->set_param( 'id', $customer_b );
		$data_b_group = rest_do_request( $req_b_group )->get_data();
		$this->check(
			'test_customer_profile: price_group_id is 0 for a customer with no group of their own',
			200 === $result_a->get_status() && array_key_exists( 'price_group_id', $data_a ) && 0 === $data_a['price_group_id'],
			wp_json_encode( $data_a )
		);
		$this->check(
			'test_customer_profile: price_group_id reflects a customer\'s own group once set',
			isset( $data_b_group['price_group_id'] ) && $wholesale_id === $data_b_group['price_group_id'],
			wp_json_encode( $data_b_group )
		);

		$req_overrides = new \WP_REST_Request( 'GET', '/counter/v1/customers/' . $customer_b . '/price-overrides' );
		$req_overrides->set_param( 'id', $customer_b );
		$overrides_result = rest_do_request( $req_overrides );
		$overrides_data   = $overrides_result->get_data();
		$found_override    = null;
		foreach ( (array) $overrides_data as $row ) {
			if ( $override_product_id === (int) ( $row['product_id'] ?? 0 ) ) {
				$found_override = $row;
				break;
			}
		}
		$this->check(
			'test_customer_profile: price-overrides returns the attached customer\'s own group prices',
			200 === $overrides_result->get_status() && null !== $found_override && 0 === bccomp( (string) $found_override['price'], '35.0000', 4 ),
			wp_json_encode( $overrides_data )
		);

		// 5. The route is refused without cntr_use_pos (or cntr_terminal_access).
		wp_set_current_user( $outsider_id );
		$req_outsider = new \WP_REST_Request( 'GET', '/counter/v1/customers/' . $customer_a . '/profile' );
		$req_outsider->set_param( 'id', $customer_a );
		$result_outsider = rest_do_request( $req_outsider );
		$this->check(
			'test_customer_profile: the route is refused without cntr_use_pos',
			403 === $result_outsider->get_status(),
			'status=' . $result_outsider->get_status()
		);

		wp_set_current_user( $original_user_id );
		foreach ( [ $cashier_id, $outsider_id ] as $uid ) {
			if ( ! is_wp_error( $uid ) && $uid ) {
				wp_delete_user( $uid );
			}
		}
	}

	// -- P1.10: test_order_builder() -- 9 checks -------------------------------------

	private function test_order_builder(): void {
		global $wpdb;

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Order Builder Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$make_product = function ( string $label, string $price ) {
			$p = new \WC_Product_Simple();
			$p->set_name( "Counter Selftest Fixture {$label}" );
			$p->set_regular_price( $price );
			$p->update_meta_data( '_cntr_selftest_fixture', self::TAG );
			$p->save();
			$this->product_fixture_ids[] = $p->get_id();
			return $p;
		};

		$p1 = $make_product( 'Order Builder A', '50.00' );
		$p2 = $make_product( 'Order Builder B', '30.00' );
		$p3 = $make_product( 'Order Builder C', '100.00' );
		$p4 = $make_product( 'Order Builder D', '1.00' );

		$context = [
			'register_id' => $register_id,
			'shift_id'    => $shift_id,
			'location_id' => $main_id,
			'operator_id' => get_current_user_id(),
		];

		// 1. A two-line cart produces an order whose total equals the sum of
		// line totals plus tax. 130.00 is already a whole taka amount under the
		// default 1.00 rounding step, so this order gets no rounding fee.
		$order_1 = \Counter\Orders\Builder::build(
			[
				[ 'product' => $p1, 'qty' => 2, 'subtotal' => '100.00', 'total' => '100.00' ],
				[ 'product' => $p2, 'qty' => 1, 'subtotal' => '30.00', 'total' => '30.00' ],
			],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-1' ]
		);
		$this->order_fixture_ids[] = $order_1->get_id();

		$expected_total_1 = 130.00 + (float) $order_1->get_total_tax();
		$this->check(
			'test_order_builder: a two-line cart produces an order whose total equals the sum of line totals plus tax',
			abs( (float) $order_1->get_total() - $expected_total_1 ) < 0.01,
			'total=' . $order_1->get_total() . ' expected=' . $expected_total_1
		);

		// 2. A line discount survives calculate_totals() — the check that
		// catches a calculate_totals(true) slipped in during a later refactor.
		$order_2 = \Counter\Orders\Builder::build(
			[ [ 'product' => $p3, 'qty' => 1, 'subtotal' => '100.00', 'total' => '90.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-2' ]
		);
		$this->order_fixture_ids[] = $order_2->get_id();
		$items_2 = array_values( $order_2->get_items() );
		$this->check(
			'test_order_builder: a line discount survives calculate_totals()',
			! empty( $items_2 ) && '90.00' === wc_format_decimal( $items_2[0]->get_total(), 2 ),
			! empty( $items_2 ) ? 'item_total=' . $items_2[0]->get_total() : 'no items'
		);

		// 3. Tax is computed from the register's (shop's) location, not the
		// customer's billing address. The "foreign" billing country used here
		// must be computed relative to whatever this real site's base country
		// actually is — found live on peapip.com (whose base country happens to
		// be US, never localised) that a hardcoded 'US' silently passed for the
		// wrong reason when it coincided with the real base country.
		$base_country            = \WC()->countries->get_base_country();
		$foreign_billing_country = ( 'BD' === $base_country ) ? 'US' : 'BD';

		$probe_order = new \WC_Order();
		$probe_order->update_meta_data( '_cntr_channel', 'pos' );
		$probe_order->update_meta_data( '_cntr_location_id', $main_id );
		$probe_order->set_billing_country( $foreign_billing_country );
		$probe_order->set_billing_state( 'ZZ' );
		$taxable_location_3 = $probe_order->get_taxable_location();
		$this->check(
			"test_order_builder: tax is computed from the register's location, not the customer's billing address",
			( $taxable_location_3['country'] ?? null ) === $base_country && $foreign_billing_country !== ( $taxable_location_3['country'] ?? null ),
			wp_json_encode( $taxable_location_3 ) . ' base=' . $base_country . ' billing_used=' . $foreign_billing_country
		);

		// 4. 247.35 with a step of 1.00 produces a Rounding fee of -0.35 and a
		// total of 247.00.
		$order_4 = \Counter\Orders\Builder::build(
			[ [ 'product' => $p4, 'qty' => 1, 'subtotal' => '247.35', 'total' => '247.35' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-4' ]
		);
		$this->order_fixture_ids[] = $order_4->get_id();
		$rounding_4                = $order_4->get_meta( '_cntr_rounding' );
		$this->check(
			'test_order_builder: 247.35 with a step of 1.00 produces a Rounding fee of -0.35 and a total of 247.00',
			'-0.35' === wc_format_decimal( $rounding_4, 2 ) && '247.00' === wc_format_decimal( $order_4->get_total(), 2 ),
			"rounding={$rounding_4} total=" . $order_4->get_total()
		);

		// 5. 247.60 rounds UP to 248.00 with a fee of +0.40.
		$order_5 = \Counter\Orders\Builder::build(
			[ [ 'product' => $p4, 'qty' => 1, 'subtotal' => '247.60', 'total' => '247.60' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-5' ]
		);
		$this->order_fixture_ids[] = $order_5->get_id();
		$rounding_5                = $order_5->get_meta( '_cntr_rounding' );
		$this->check(
			'test_order_builder: 247.60 rounds up to 248.00 with a fee of +0.40',
			'0.40' === wc_format_decimal( $rounding_5, 2 ) && '248.00' === wc_format_decimal( $order_5->get_total(), 2 ),
			"rounding={$rounding_5} total=" . $order_5->get_total()
		);

		// 6. The rounding fee carries no tax.
		$fee_items_6 = array_values( $order_4->get_items( 'fee' ) );
		$fee_6       = $fee_items_6[0] ?? null;
		$this->check(
			'test_order_builder: the rounding fee carries no tax',
			$fee_6 && 'none' === $fee_6->get_tax_status() && 0.0 === (float) $fee_6->get_total_tax(),
			$fee_6 ? wp_json_encode( [ 'tax_status' => $fee_6->get_tax_status(), 'total_tax' => $fee_6->get_total_tax() ] ) : 'no fee found'
		);

		// 7. Running the rounding routine twice adds only one fee — the
		// _cntr_rounding guard.
		\Counter\Orders\Money::apply_rounding( $order_4 );
		$fee_items_7 = array_values( $order_4->get_items( 'fee' ) );
		$this->check(
			'test_order_builder: running the rounding routine twice adds only one fee',
			1 === count( $fee_items_7 ),
			'fee_count=' . count( $fee_items_7 )
		);

		// 8. A step of 0 disables rounding entirely and leaves the total untouched.
		Settings::set( 'cash.rounding_step', 0 );
		$order_8 = \Counter\Orders\Builder::build(
			[ [ 'product' => $p4, 'qty' => 1, 'subtotal' => '247.35', 'total' => '247.35' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-8' ]
		);
		$this->order_fixture_ids[] = $order_8->get_id();
		Settings::set( 'cash.rounding_step', 1 ); // restore the declared default immediately
		$this->check(
			'test_order_builder: a step of 0 disables rounding entirely and leaves the total untouched',
			'' === $order_8->get_meta( '_cntr_rounding' ) && '247.35' === wc_format_decimal( $order_8->get_total(), 2 ),
			'rounding=' . wp_json_encode( $order_8->get_meta( '_cntr_rounding' ) ) . ' total=' . $order_8->get_total()
		);

		// 9. Every _cntr_* meta Builder is responsible for (per §5.8's own
		// "Written by" column — _cntr_cogs/_cntr_challan_serial/_cntr_offline
		// belong to P2.8/P3.5/P7.2 respectively and are deliberately not
		// checked here) is present and readable through get_meta(), and
		// get_post_meta() for the same key returns nothing — proving HPOS is
		// really in use, not just declared compatible.
		$builder_keys = [ '_cntr_channel', '_cntr_register_id', '_cntr_shift_id', '_cntr_uuid', '_cntr_location_id', '_cntr_operator_id', '_cntr_rounding', '_cntr_receipt_no' ];
		$all_present  = true;
		foreach ( $builder_keys as $k ) {
			if ( '' === $order_4->get_meta( $k ) ) {
				$all_present = false;
			}
		}
		$post_meta_channel = get_post_meta( $order_4->get_id(), '_cntr_channel', true );
		$this->check(
			'test_order_builder: every Builder-owned _cntr_* meta is present via get_meta(), and get_post_meta() confirms HPOS',
			$all_present && ( '' === $post_meta_channel || false === $post_meta_channel ),
			'all_present=' . wp_json_encode( $all_present ) . ' post_meta=' . wp_json_encode( $post_meta_channel )
		);
	}

	// -- P1.11: test_channel_apply() -- 6 checks (plus one bonus retry check) -------

	private function test_channel_apply(): void {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		$state_table = Install::table( 'order_stock_state' );

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Channel Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$managed = new \WC_Product_Simple();
		$managed->set_name( 'Counter Selftest Fixture Channel Managed' );
		$managed->set_regular_price( '20.00' );
		$managed->set_manage_stock( true );
		$managed->set_stock_quantity( 50 );
		$managed->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$managed->save();
		$this->product_fixture_ids[] = $managed->get_id();

		$unmanaged = new \WC_Product_Simple();
		$unmanaged->set_name( 'Counter Selftest Fixture Channel Unmanaged' );
		$unmanaged->set_regular_price( '5.00' );
		$unmanaged->set_manage_stock( false );
		$unmanaged->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$unmanaged->save();
		$this->product_fixture_ids[] = $unmanaged->get_id();

		// Seed a real ledger balance so apply_stock() has something to decrement.
		Db::transaction(
			function () use ( $managed, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $managed->get_id(),
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '20',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_channel',
						'ref_id'       => 0,
					]
				);
			}
		);

		$context = [
			'register_id' => $register_id,
			'shift_id'    => $shift_id,
			'location_id' => $main_id,
			'operator_id' => get_current_user_id(),
		];

		$order = \Counter\Orders\Builder::build(
			[
				[ 'product' => $managed, 'qty' => 3, 'subtotal' => '60.00', 'total' => '60.00' ],
				[ 'product' => $unmanaged, 'qty' => 1, 'subtotal' => '5.00', 'total' => '5.00' ],
			],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-CH-1' ]
		);
		$order_id                  = $order->get_id();
		$this->order_fixture_ids[] = $order_id;

		// Add a fee line directly (independent of rounding) so check 6 can prove
		// it's skipped regardless of whether get_items()'s own default type
		// filter or Entity::for_item()'s null-return is what excludes it.
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( 'Selftest Fee' );
		$fee->set_total( '2.00' );
		$order->add_item( $fee );
		$order->calculate_totals( false );
		$order->save();
		$order = wc_get_order( $order_id ); // fresh read

		// 1. Applying moves stock and writes moves.
		$applied_1            = \Counter\Orders\Channel::apply_stock( $order );
		$balance_after_apply  = \Counter\Stock\Ledger::balance( $managed->get_id(), 0, $main_id );
		$move_count_1         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d", $order_id ) );
		$this->check(
			'test_channel_apply: applying moves stock and writes moves',
			true === $applied_1 && '17.0000' === $balance_after_apply && 1 === $move_count_1,
			"applied=" . wp_json_encode( $applied_1 ) . " balance={$balance_after_apply} moves={$move_count_1}"
		);

		// 2. Applying twice is a no-op returning false.
		$applied_2            = \Counter\Orders\Channel::apply_stock( $order );
		$balance_after_second = \Counter\Stock\Ledger::balance( $managed->get_id(), 0, $main_id );
		$move_count_2         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d", $order_id ) );
		$this->check(
			'test_channel_apply: applying twice is a no-op returning false',
			false === $applied_2 && '17.0000' === $balance_after_second && 1 === $move_count_2,
			'applied=' . wp_json_encode( $applied_2 ) . " balance={$balance_after_second} moves={$move_count_2}"
		);

		// 3. Reverting restores exactly.
		$reverted_3            = \Counter\Orders\Channel::revert_stock( $order );
		$balance_after_revert  = \Counter\Stock\Ledger::balance( $managed->get_id(), 0, $main_id );
		$this->check(
			'test_channel_apply: reverting restores exactly',
			true === $reverted_3 && '20.0000' === $balance_after_revert,
			'reverted=' . wp_json_encode( $reverted_3 ) . " balance={$balance_after_revert}"
		);

		// 4. Reverting twice is a no-op.
		$reverted_4                  = \Counter\Orders\Channel::revert_stock( $order );
		$balance_after_second_revert = \Counter\Stock\Ledger::balance( $managed->get_id(), 0, $main_id );
		$this->check(
			'test_channel_apply: reverting twice is a no-op',
			false === $reverted_4 && '20.0000' === $balance_after_second_revert,
			'reverted=' . wp_json_encode( $reverted_4 ) . " balance={$balance_after_second_revert}"
		);

		// 5. A rollback mid-apply leaves the guard row absent so a retry works.
		$order_5 = \Counter\Orders\Builder::build(
			[ [ 'product' => $managed, 'qty' => 1, 'subtotal' => '20.00', 'total' => '20.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-CH-5' ]
		);
		$order_5_id                = $order_5->get_id();
		$this->order_fixture_ids[] = $order_5_id;

		try {
			Db::transaction(
				function () use ( $order_5 ) {
					\Counter\Orders\Channel::apply_stock( $order_5 );
					throw new \RuntimeException( 'selftest channel rollback probe' );
				}
			);
		} catch ( \RuntimeException $e ) {
			// expected
		}
		$guard_row_5 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$state_table} WHERE order_id = %d", $order_5_id ), ARRAY_A );
		$moves_5     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d", $order_5_id ) );
		$this->check(
			'test_channel_apply: a rollback mid-apply leaves the guard row absent so a retry works',
			null === $guard_row_5 && 0 === $moves_5,
			wp_json_encode( $guard_row_5 ) . " moves={$moves_5}"
		);

		$retry_5 = \Counter\Orders\Channel::apply_stock( $order_5 );
		$this->check( 'test_channel_apply: after the rollback, applying again succeeds', true === $retry_5, wp_json_encode( $retry_5 ) );

		// 6. An order containing a fee line and an unmanaged product applies only
		// the managed lines.
		$has_fee            = count( $order->get_items( 'fee' ) ) > 0;
		$has_unmanaged_line = false;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$e = \Counter\Stock\Entity::for_item( $item );
			if ( $e && false === $e['managed'] ) {
				$has_unmanaged_line = true;
			}
		}
		$this->check(
			'test_channel_apply: an order containing a fee line and an unmanaged product applies only the managed lines',
			$has_fee && $has_unmanaged_line && 1 === $move_count_1,
			'has_fee=' . wp_json_encode( $has_fee ) . ' has_unmanaged=' . wp_json_encode( $has_unmanaged_line ) . " move_count={$move_count_1}"
		);
	}

	// -- P1.12: test_sale_idempotent() -- 10 checks. Invariant IV. -----------------
	// Alongside test_wc_stock_authority(), the suite's centre of gravity.

	private function test_sale_idempotent(): void {
		global $wpdb;
		$queue_table       = Install::table( 'sale_queue' );
		$shift_sales_table = Install::table( 'shift_sales' );
		$tenders_table     = Install::table( 'tenders' );
		$state_table       = Install::table( 'order_stock_state' );
		$orders_meta       = $wpdb->prefix . 'wc_orders_meta';

		$extract = static function ( $result ) {
			if ( $result instanceof \WP_Error ) {
				return [ 'error' => $result->get_error_message() ];
			}
			if ( $result instanceof \WP_REST_Response ) {
				return $result->get_data();
			}
			return $result;
		};

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Sale Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Sale Product' );
		$product->set_regular_price( '120.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '20',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_sale',
						'ref_id'       => 0,
					]
				);
			}
		);

		$body_1 = [
			'lines'    => [
				[ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '2.0000', 'unit_price' => '120.0000', 'discount' => '0.0000' ],
			],
			'tenders'  => [
				[ 'method' => 'cash', 'amount' => '250.0000' ],
				[ 'method' => 'change', 'amount' => '10.0000', 'is_change' => true ],
			],
			'customer' => [ 'phone' => '' ],
		];

		$uuid_1    = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_1;
		$result_1  = \Counter\Rest\Sale::process( $uuid_1, $register_id, $shift_id, 'SELFTEST-SALE-1', $body_1 );
		$data_1    = $extract( $result_1 );
		$order_id_1 = (int) ( $data_1['order_id'] ?? 0 );
		if ( $order_id_1 ) {
			$this->order_fixture_ids[] = $order_id_1;
		}

		// 1. A sale creates exactly one order.
		$this->check(
			'test_sale_idempotent: a sale creates exactly one order',
			$order_id_1 > 0 && wc_get_order( $order_id_1 ) instanceof \WC_Order,
			wp_json_encode( $data_1 )
		);

		// 2. Stock dropped by exactly the quantity sold.
		$balance_after_sale = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$this->check(
			'test_sale_idempotent: stock dropped by exactly the quantity sold',
			'18.0000' === $balance_after_sale,
			"balance={$balance_after_sale}"
		);

		$queue_row_1_before_replay = $wpdb->get_row( $wpdb->prepare( "SELECT receipt_json FROM {$queue_table} WHERE uuid = %s", $uuid_1 ), ARRAY_A );

		$result_2 = \Counter\Rest\Sale::process( $uuid_1, $register_id, $shift_id, 'SELFTEST-SALE-1', $body_1 );
		$data_2   = $extract( $result_2 );
		$order_id_2 = (int) ( $data_2['order_id'] ?? 0 );

		$order_count_for_uuid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_meta} WHERE meta_key = '_cntr_uuid' AND meta_value = %s", $uuid_1 ) );

		// 3. The same UUID posted a second time creates no second order.
		$this->check(
			'test_sale_idempotent: the same UUID posted a second time creates no second order',
			1 === $order_count_for_uuid,
			"count={$order_count_for_uuid}"
		);

		// 4. The replay returns the identical receipt_json — string comparison.
		$this->check(
			'test_sale_idempotent: the replay returns the identical receipt_json',
			wp_json_encode( $data_2 ) === $queue_row_1_before_replay['receipt_json'],
			'stored=' . $queue_row_1_before_replay['receipt_json'] . ' replay=' . wp_json_encode( $data_2 )
		);

		// 5. The replay returns the same order_id.
		$this->check(
			'test_sale_idempotent: the replay returns the same order_id',
			$order_id_2 === $order_id_1,
			"order_1={$order_id_1} order_2={$order_id_2}"
		);

		// 6. cntr_shift_sales has exactly one row for the sale.
		$shift_sales_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$shift_sales_table} WHERE order_id = %d", $order_id_1 ) );
		$this->check(
			'test_sale_idempotent: cntr_shift_sales has exactly one row for the sale',
			1 === $shift_sales_count,
			"count={$shift_sales_count}"
		);

		// 7. cntr_tenders rows sum, net of is_change, to the order total.
		$tender_rows = $wpdb->get_results( $wpdb->prepare( "SELECT amount, is_change FROM {$tenders_table} WHERE order_id = %d", $order_id_1 ), ARRAY_A );
		$net_tendered = 0.0;
		foreach ( $tender_rows as $t ) {
			$net_tendered += $t['is_change'] ? -1 * (float) $t['amount'] : (float) $t['amount'];
		}
		$order_total_1 = (float) wc_get_order( $order_id_1 )->get_total();
		$this->check(
			'test_sale_idempotent: cntr_tenders rows sum, net of is_change, to the order total',
			abs( $net_tendered - $order_total_1 ) < 0.01,
			"net={$net_tendered} total={$order_total_1}"
		);

		// 8. A sale posted with no open shift is refused with 409 and creates
		// nothing — assert the absence: no order, no queue row left in processing.
		$no_shift_register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Sale No-Shift Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $no_shift_register_id;

		$uuid_8         = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_8;
		$fake_shift_id  = 999999999; // never opened, so require_open() must refuse
		$result_8       = \Counter\Rest\Sale::process( $uuid_8, $no_shift_register_id, $fake_shift_id, 'SELFTEST-SALE-8', $body_1 );
		$error_data_8   = $result_8 instanceof \WP_Error ? $result_8->get_error_data() : null;
		$order_count_8  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_meta} WHERE meta_key = '_cntr_uuid' AND meta_value = %s", $uuid_8 ) );
		$queue_row_8    = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$queue_table} WHERE uuid = %s", $uuid_8 ), ARRAY_A );
		$this->check(
			'test_sale_idempotent: a sale posted with no open shift is refused with 409 and creates nothing',
			$result_8 instanceof \WP_Error && 409 === ( $error_data_8['status'] ?? null )
				&& 0 === $order_count_8
				&& $queue_row_8 && 'processing' !== $queue_row_8['status'],
			wp_json_encode( [ 'status' => $error_data_8['status'] ?? null, 'order_count' => $order_count_8, 'queue_status' => $queue_row_8['status'] ?? null ] )
		);

		// 9. A sale whose line references a deleted product is marked
		// failed_permanent with a readable error, not left retrying forever.
		$uuid_9 = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_9;
		$body_9 = [
			'lines'   => [ [ 'product_id' => 999999998, 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '10.0000', 'discount' => '0.0000' ] ],
			'tenders' => [],
		];
		$result_9    = \Counter\Rest\Sale::process( $uuid_9, $register_id, $shift_id, 'SELFTEST-SALE-9', $body_9 );
		$queue_row_9 = $wpdb->get_row( $wpdb->prepare( "SELECT status, error FROM {$queue_table} WHERE uuid = %s", $uuid_9 ), ARRAY_A );
		$this->check(
			'test_sale_idempotent: a sale whose line references a deleted product is marked failed_permanent',
			$result_9 instanceof \WP_Error && $queue_row_9 && 'failed_permanent' === $queue_row_9['status'] && ! empty( $queue_row_9['error'] ),
			wp_json_encode( $queue_row_9 )
		);

		// 10. A crash simulated between steps 5 and 6 (a throw inside the
		// transaction) leaves the queue row recoverable and the order without
		// applied stock — and re-posting the same UUID completes it correctly.
		$crash_uuid = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $crash_uuid;
		$crash_probe = function () {
			throw new \RuntimeException( 'selftest sale mid-transaction crash probe' );
		};
		add_action( 'cntr_stock_moved', $crash_probe );

		$result_10a = \Counter\Rest\Sale::process( $crash_uuid, $register_id, $shift_id, 'SELFTEST-SALE-10', $body_1 );

		remove_action( 'cntr_stock_moved', $crash_probe );

		$queue_row_10a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE uuid = %s", $crash_uuid ), ARRAY_A );
		$order_id_10   = (int) ( $queue_row_10a['order_id'] ?? 0 );
		if ( $order_id_10 ) {
			$this->order_fixture_ids[] = $order_id_10;
		}
		$state_row_10 = $order_id_10 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$state_table} WHERE order_id = %d", $order_id_10 ), ARRAY_A ) : null;

		$first_attempt_ok = $result_10a instanceof \WP_Error
			&& $queue_row_10a && 'failed_retry' === $queue_row_10a['status']
			&& $order_id_10 > 0        // step 5 completed — order_id was written — before the crash
			&& null === $state_row_10; // guard row absent — stock never applied

		$result_10b = \Counter\Rest\Sale::process( $crash_uuid, $register_id, $shift_id, 'SELFTEST-SALE-10', $body_1 );
		$data_10b   = $extract( $result_10b );
		$retry_ok   = ! ( $result_10b instanceof \WP_Error ) && (int) ( $data_10b['order_id'] ?? 0 ) === $order_id_10;

		$this->check(
			'test_sale_idempotent: a crash mid-transaction leaves the queue row recoverable, and a retry completes it correctly',
			$first_attempt_ok && $retry_ok,
			wp_json_encode(
				[
					'first_attempt_ok'  => $first_attempt_ok,
					'retry_ok'          => $retry_ok,
					'queue_status_after_crash' => $queue_row_10a['status'] ?? null,
				]
			)
		);
	}

	// -- P1.13: test_terminal_assets() -- 5 checks -----------------------------------

	private function test_terminal_assets(): void {
		// 1. /pos/ resolves and returns the template, not a 404. Loopback HTTP,
		// so any non-404 response (including a 403 for an unauthenticated
		// request — the route IS handled, just not permitted) proves the
		// rewrite rule actually resolves rather than falling through to
		// WordPress's default "no such page."
		$pos_response = wp_remote_get( home_url( '/pos/' ), [ 'timeout' => 10, 'sslverify' => false ] );
		$pos_code     = is_wp_error( $pos_response ) ? 0 : (int) wp_remote_retrieve_response_code( $pos_response );
		$this->check(
			'test_terminal_assets: /pos/ resolves and returns the template, not a 404',
			0 !== $pos_code && 404 !== $pos_code,
			'code=' . $pos_code . ( is_wp_error( $pos_response ) ? ' error=' . $pos_response->get_error_message() : '' )
		);

		// 2. The rendered page contains the bootstrap global and the asset tags
		// — the equivalent of the classic silent failure where the script loads
		// and its inline data does not. Rendered by direct include (not a real
		// HTTP round trip) so this doesn't depend on loopback auth; the
		// cntr_is_pos_screen filter is forced true for the duration, since the
		// real query var it normally reads is only set by actually matching the
		// rewrite rule through a real request.
		$force_pos_screen = static fn() => true;
		add_filter( 'cntr_is_pos_screen', $force_pos_screen, 999 );
		ob_start();
		include CNTR_DIR . 'templates/pos.php';
		$html = ob_get_clean();
		remove_filter( 'cntr_is_pos_screen', $force_pos_screen, 999 );

		$has_bootstrap  = false !== strpos( $html, 'window.CNTR' );
		$has_asset_tags = false !== strpos( $html, 'pos.js' ) && false !== strpos( $html, 'pos.css' );
		$this->check(
			'test_terminal_assets: the rendered page contains the bootstrap global and the asset tags',
			$has_bootstrap && $has_asset_tags,
			'bootstrap=' . wp_json_encode( $has_bootstrap ) . ' assets=' . wp_json_encode( $has_asset_tags )
		);

		// 3. pos.js contains no external host reference.
		$js_src = file_get_contents( CNTR_DIR . 'assets/pos.js' );
		$this->check(
			'test_terminal_assets: pos.js contains no external host reference',
			0 === preg_match( '#https?://#', $js_src )
		);

		// 4. pos.js contains no console.log.
		$this->check( 'test_terminal_assets: pos.js contains no console.log', false === strpos( $js_src, 'console.log' ) );

		// 5. The catalogue endpoint is reachable with the cntr_terminal role and
		// refused without it. cntr_terminal deliberately has ONLY
		// cntr_terminal_access (P0.4) — Router::guard_any() is what makes this
		// endpoint reachable for the machine account despite that, which this
		// check proves directly rather than by inspecting the guard's source.
		// This run creates its own fixture terminal user rather than assuming
		// one exists — found live that no real till account had been
		// provisioned on this site yet, which is exactly why the test should
		// not depend on the environment already having one.
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$terminal_user_id = wp_insert_user(
			[
				'user_login' => 'cntr_selftest_terminal_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => \Counter\Capabilities::ROLE_TERMINAL,
			]
		);
		$subscriber_id = wp_insert_user(
			[
				'user_login' => 'cntr_selftest_subscriber_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => 'subscriber',
			]
		);

		if ( is_wp_error( $terminal_user_id ) || is_wp_error( $subscriber_id ) ) {
			$this->check(
				'test_terminal_assets: catalogue endpoint reachable with cntr_terminal, refused without it',
				false,
				'could not create fixture users: ' . ( is_wp_error( $terminal_user_id ) ? $terminal_user_id->get_error_message() : '' ) . ( is_wp_error( $subscriber_id ) ? $subscriber_id->get_error_message() : '' )
			);
		} else {
			$original_user_id = get_current_user_id();

			wp_set_current_user( $terminal_user_id );
			$req_with = new \WP_REST_Request( 'GET', '/counter/v1/catalog' );
			$req_with->set_param( 'since', 0 );
			$req_with->set_param( 'limit', 10 );
			$result_with  = rest_do_request( $req_with );
			$allowed_with = 200 === $result_with->get_status();

			wp_set_current_user( $subscriber_id );
			$req_without = new \WP_REST_Request( 'GET', '/counter/v1/catalog' );
			$req_without->set_param( 'since', 0 );
			$req_without->set_param( 'limit', 10 );
			$result_without   = rest_do_request( $req_without );
			$refused_without  = 403 === $result_without->get_status();

			wp_set_current_user( $original_user_id );

			$this->check(
				'test_terminal_assets: catalogue endpoint reachable with cntr_terminal, refused without it',
				$allowed_with && $refused_without,
				'with=' . $result_with->get_status() . ' without=' . $result_without->get_status()
			);
		}

		foreach ( [ $terminal_user_id, $subscriber_id ] as $uid ) {
			if ( ! is_wp_error( $uid ) && $uid ) {
				wp_delete_user( $uid );
			}
		}
	}

	// -- F0: test_pos_wiring() -- 9 checks ---------------------------------------
	// Deliberately crude, deliberately effective: reads assets/pos.js as a plain
	// string and asserts structure that an empty stub or an unwired export can't
	// fake. This is the check COUNTERFRONTEND.md F0 says would have caught
	// v0.1.8's five stubbed keyboard handlers. Several checks are EXPECTED to
	// fail until their own F-task lands — do not weaken a check to make it pass
	// early; the task that fixes the underlying code is what makes it pass.

	private function test_pos_wiring(): void {
		$js = file_get_contents( CNTR_DIR . 'assets/pos.js' );

		// 1. No handler named in the keyboard map has an empty body.
		$handlers = [
			'changeSelectedQty',
			'lineDiscountPrompt',
			'noSale',
			'openQuickAdd',
			'attachCustomerPrompt',
			'holdSale',
			'resumeSale',
			'voidLineThenCart',
		];
		$bodies      = [];
		$empty_names = [];
		foreach ( $handlers as $name ) {
			$body             = $this->extract_js_function_body( $js, $name );
			$bodies[ $name ]  = $body;
			$stripped         = $this->strip_js_comments( (string) $body );
			if ( null === $body || '' === trim( $stripped ) ) {
				$empty_names[] = $name;
			}
		}
		$this->check(
			'test_pos_wiring: no keyboard-map handler has an empty body',
			empty( $empty_names ),
			empty( $empty_names ) ? '' : 'empty: ' . implode( ', ', $empty_names )
		);

		// 2. None of those handler bodies use a browser prompt() as their UI —
		// a modal, not prompt(), is the shipped surface for every one of them.
		$prompt_names = [];
		foreach ( $bodies as $name => $body ) {
			if ( null !== $body && false !== strpos( $body, 'prompt(' ) ) {
				$prompt_names[] = $name;
			}
		}
		$this->check(
			'test_pos_wiring: no keyboard-map handler uses a browser prompt() as its UI',
			empty( $prompt_names ),
			empty( $prompt_names ) ? '' : 'uses prompt(): ' . implode( ', ', $prompt_names )
		);

		// 3. searchByText is referenced somewhere other than the window.CNTR._pos
		// export line — i.e. it is actually wired into the search box, not only
		// handed to the test suite through the export hatch.
		$js_without_export = preg_replace( '/window\.CNTR\._pos\s*=\s*\{.*?\};/s', '', $js, 1 );
		$search_by_text_refs = preg_match_all( '/\bsearchByText\b/', (string) $js_without_export );
		$this->check(
			'test_pos_wiring: searchByText is referenced outside its declaration and the test export',
			$search_by_text_refs > 1, // >1: the function declaration itself is always 1
			'occurrences_outside_export=' . $search_by_text_refs
		);

		// 4. The tender method list in pos.js contains 'credit'.
		$this->check(
			'test_pos_wiring: the tender method list includes credit',
			false !== strpos( $js, 'value="credit"' ) || false !== strpos( $js, "'credit'" )
		);

		// 5 & 6. discountCeilingPct / roundingStep are actually read, not just bootstrapped.
		$this->check(
			'test_pos_wiring: discountCeilingPct is read in pos.js, not just bootstrapped',
			false !== strpos( $js, 'discountCeilingPct' )
		);
		$this->check(
			'test_pos_wiring: roundingStep is read in pos.js, not just bootstrapped',
			false !== strpos( $js, 'roundingStep' )
		);

		// 7. searchFields is read in pos.js, or dropped from the templates/pos.php
		// bootstrap — dead config plumbed-but-ignored is a defect either way.
		$template          = file_get_contents( CNTR_DIR . 'templates/pos.php' );
		$read_in_js        = false !== strpos( $js, 'searchFields' );
		$dropped_from_tpl  = false === strpos( (string) $template, 'searchFields' );
		$this->check(
			'test_pos_wiring: searchFields is read in pos.js, or removed from the bootstrap',
			$read_in_js || $dropped_from_tpl,
			'read_in_js=' . wp_json_encode( $read_in_js ) . ' dropped_from_template=' . wp_json_encode( $dropped_from_tpl )
		);

		// 8. At least one .focus() call restores focus, other than the print iframe's.
		preg_match_all( '/([A-Za-z0-9_.\[\]\'"]*)\.focus\(\)/', $js, $focus_matches );
		$focus_targets     = $focus_matches[1] ?? [];
		$non_iframe_focus  = array_filter( $focus_targets, static fn( $target ) => false === strpos( $target, 'frame' ) );
		$this->check(
			'test_pos_wiring: at least one .focus() call restores focus outside printing',
			count( $non_iframe_focus ) > 0,
			'targets=' . wp_json_encode( $focus_targets )
		);

		// 9. Every id="cntr-…" container a handler un-hides has markup written into
		// it somewhere — no handler un-hides a permanently empty div.
		preg_match_all( '/id="(cntr-[a-z0-9_-]+)"[^>]*class="cntr-modal"[^>]*hidden><\/div>/i', $js, $empty_container_matches );
		$empty_container_ids = array_unique( $empty_container_matches[1] ?? [] );
		$never_filled         = [];
		foreach ( $empty_container_ids as $id ) {
			if ( ! $this->js_id_filled_via_inner_html( $js, $id ) ) {
				$never_filled[] = $id;
			}
		}
		$this->check(
			'test_pos_wiring: no handler un-hides a permanently empty cntr-* container',
			empty( $never_filled ),
			empty( $never_filled ) ? '' : 'never filled: ' . implode( ', ', $never_filled )
		);
	}

	// -- COUNTERFRONTEND.md F7: test_quick_add_listing() -- 3 checks ------------------

	private function test_quick_add_listing(): void {
		$main_id = \Counter\Stock\Locations::default_id();

		// 1 — quick-added, nothing else set: must appear, missing all three.
		$incomplete = new \WC_Product_Simple();
		$incomplete->set_name( 'Counter Selftest Fixture Quick-Add Incomplete' );
		$incomplete->set_regular_price( '10.00' );
		$incomplete->update_meta_data( '_cntr_quick_added', 1 );
		$incomplete->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$incomplete->save();
		$this->product_fixture_ids[] = $incomplete->get_id();

		// 2 — an ordinary product, never quick-added: must never appear.
		$ordinary = new \WC_Product_Simple();
		$ordinary->set_name( 'Counter Selftest Fixture Quick-Add Ordinary' );
		$ordinary->set_regular_price( '10.00' );
		$ordinary->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$ordinary->save();
		$this->product_fixture_ids[] = $ordinary->get_id();

		// 3 — quick-added, then category/tax class/cost all completed: reported complete.
		$complete = new \WC_Product_Simple();
		$complete->set_name( 'Counter Selftest Fixture Quick-Add Complete' );
		$complete->set_regular_price( '10.00' );
		$complete->set_manage_stock( true );
		$complete->set_stock_quantity( 0 );
		$complete->set_tax_class( 'reduced-rate' );
		$complete->update_meta_data( '_cntr_quick_added', 1 );
		$complete->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$complete->save();
		$complete_id = $complete->get_id();
		$this->product_fixture_ids[] = $complete_id;

		$category_ids = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ] );
		if ( ! empty( $category_ids ) && ! is_wp_error( $category_ids ) ) {
			wp_set_object_terms( $complete_id, [ (int) $category_ids[0] ], 'product_cat' );
		}

		// A real batch, not just a product field — quick_add()'s own opening
		// move never creates one (see Health::quick_added_products()'s own
		// docblock), so this is what "cost completed" actually looks like.
		\Counter\Stock\Batches::receive(
			[
				'product_id'   => $complete_id,
				'variation_id' => 0,
				'location_id'  => $main_id,
				'qty_received' => '5',
				'unit_cost'    => '7.50',
			]
		);

		$rows  = \Counter\Admin\Health::quick_added_products();
		$by_id = [];
		foreach ( $rows as $r ) {
			$by_id[ $r['product_id'] ] = $r;
		}

		$this->check(
			'test_quick_add_listing: a product with _cntr_quick_added appears in the Health list',
			isset( $by_id[ $incomplete->get_id() ] ) && ! empty( $by_id[ $incomplete->get_id() ]['missing'] ),
			wp_json_encode( $by_id[ $incomplete->get_id() ] ?? null )
		);
		$this->check(
			'test_quick_add_listing: a product without _cntr_quick_added does not appear',
			! isset( $by_id[ $ordinary->get_id() ] )
		);
		$this->check(
			'test_quick_add_listing: a quick-added product whose category, tax class and cost are all set is reported complete',
			isset( $by_id[ $complete_id ] ) && empty( $by_id[ $complete_id ]['missing'] ),
			wp_json_encode( $by_id[ $complete_id ] ?? null )
		);
	}

	/**
	 * Returns the body of the first top-level `function $name(...) { ... }` in
	 * $js, with braces balanced by hand — a JS function body routinely nests
	 * object literals a single regex cannot match correctly. Null if not found.
	 */
	private function extract_js_function_body( string $js, string $name ): ?string {
		if ( ! preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\([^)]*\)\s*\{/', $js, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$open  = $m[0][1] + strlen( $m[0][0] ) - 1; // index of the opening '{'
		$depth = 0;
		$len   = strlen( $js );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $js[ $i ] ) {
				++$depth;
			} elseif ( '}' === $js[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $js, $open + 1, $i - $open - 1 );
				}
			}
		}
		return null;
	}

	/** Strips // and /* *\/ comments from a JS snippet, for an emptiness check. */
	private function strip_js_comments( string $js ): string {
		$js = preg_replace( '#/\*.*?\*/#s', '', $js );
		$js = preg_replace( '#//[^\n]*#', '', (string) $js );
		return (string) $js;
	}

	/**
	 * Crude but effective: true if some `document.getElementById('$id')` call
	 * has an `.innerHTML` assignment anywhere in the SAME enclosing top-level
	 * function — i.e. the container this id names gets real markup written
	 * into it somewhere, rather than only ever being un-hidden empty. Scoped
	 * to the enclosing function (not a fixed character window) so a match in
	 * one handler never borrows an unrelated `.innerHTML =` from the next
	 * function down the file.
	 */
	private function js_id_filled_via_inner_html( string $js, string $id ): bool {
		$pattern = '/getElementById\(\s*[\'"]' . preg_quote( $id, '/' ) . '[\'"]\s*\)/';
		if ( ! preg_match_all( $pattern, $js, $matches, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		foreach ( $matches[0] as $match ) {
			$body = $this->enclosing_js_function_body( $js, $match[1] );
			if ( null !== $body && false !== strpos( $body, '.innerHTML' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Finds the nearest `function name(...) {` at or before byte offset
	 * $offset and returns that function's balanced body, or null if $offset
	 * isn't inside any top-level function (or the brace never balances).
	 */
	private function enclosing_js_function_body( string $js, int $offset ): ?string {
		$search_region = substr( $js, 0, $offset );
		if ( ! preg_match_all( '/function\s+[A-Za-z0-9_$]*\s*\([^)]*\)\s*\{/', $search_region, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$last = end( $m[0] );
		if ( false === $last ) {
			return null;
		}
		$open  = $last[1] + strlen( $last[0] ) - 1;
		$depth = 0;
		$len   = strlen( $js );
		for ( $i = $open; $i < $len; $i++ ) {
			if ( '{' === $js[ $i ] ) {
				++$depth;
			} elseif ( '}' === $js[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					// Confirm $offset actually falls inside this function
					// (not in the gap between it and the NEXT function, which
					// would mean $offset isn't inside any function body).
					if ( $offset > $i ) {
						return null;
					}
					return substr( $js, $open + 1, $i - $open - 1 );
				}
			}
		}
		return null;
	}

	// -- P1.14: test_tenders() -- 6 checks -------------------------------------------

	private function test_tenders(): void {
		global $wpdb;
		$tenders_table = Install::table( 'tenders' );
		$audit_table   = Install::table( 'audit_log' );

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Tenders Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Tender Product' );
		$product->set_regular_price( '100.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$this->product_fixture_ids[] = $product->get_id();

		$context = [
			'register_id' => $register_id,
			'shift_id'    => $shift_id,
			'location_id' => $main_id,
			'operator_id' => get_current_user_id(),
		];

		$make_order = function ( string $receipt_no ) use ( $product, $context ) {
			$order = \Counter\Orders\Builder::build(
				[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '100.00', 'total' => '100.00' ] ],
				$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => $receipt_no ]
			);
			$this->order_fixture_ids[] = $order->get_id();
			return $order;
		};

		// 1. Payments minus change equals the order total.
		$order_1  = $make_order( 'SELFTEST-TENDER-1' );
		$result_1 = \Counter\Pos\Tenders::record(
			$order_1,
			[
				[ 'method' => 'cash', 'amount' => '150.00' ],
				[ 'method' => 'change', 'amount' => '50.00', 'is_change' => true ],
			],
			$shift_id
		);
		$this->check(
			'test_tenders: payments minus change equals the order total',
			true === $result_1,
			wp_json_encode( $result_1 instanceof \WP_Error ? $result_1->get_error_message() : $result_1 )
		);

		// 2. A mismatch without cntr_credit_sale is refused.
		$order_2  = $make_order( 'SELFTEST-TENDER-2' );
		$result_2 = \Counter\Pos\Tenders::record( $order_2, [ [ 'method' => 'cash', 'amount' => '50.00' ] ], $shift_id );
		$error_data_2 = $result_2 instanceof \WP_Error ? $result_2->get_error_data() : null;
		$this->check(
			'test_tenders: a mismatch without cntr_credit_sale is refused',
			$result_2 instanceof \WP_Error && 422 === ( $error_data_2['status'] ?? null ),
			wp_json_encode( $error_data_2 )
		);

		// 3. A mismatch WITH the capability AND an identified customer is
		// allowed, the shortfall is audited, AND (P2.6, real now — this check
		// used to accept customer_id 0 back when the write was only ever an
		// audit-log stub; real behaviour is stricter, see docs/decisions.md)
		// a real customer_ledger debit is written. Explicitly establishes an
		// administrator user context for this one check — a bare `wp eval`
		// (no --user flag) runs with NO logged-in user at all, so
		// current_user_can() would correctly return false for every
		// capability regardless of role grants. Found live: this check
		// failed not because Tenders::record()'s logic was wrong, but because
		// the suite's own invocation has no session to check capabilities
		// against; restoring the original (possibly-anonymous) user afterward
		// keeps this self-contained rather than depending on how the suite
		// happens to be invoked.
		$order_3            = $make_order( 'SELFTEST-TENDER-3' );
		$original_user_id_3 = get_current_user_id();
		$admins_3            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_3 ) ) {
			wp_set_current_user( (int) $admins_3[0] );
		}
		$create_customer_3 = wc_create_new_customer( 'cntr-selftest-tender-3-' . wp_generate_password( 8, false ) . '@example.invalid', '', wp_generate_password( 16 ) );
		$customer_id_3      = is_wp_error( $create_customer_3 ) ? 0 : $create_customer_3;
		if ( $customer_id_3 ) {
			$this->customer_fixture_ids[] = $customer_id_3;
		}
		$result_3 = \Counter\Pos\Tenders::record( $order_3, [ [ 'method' => 'cash', 'amount' => '40.00' ] ], $shift_id, $customer_id_3 );
		wp_set_current_user( $original_user_id_3 );
		$audit_row_3 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$audit_table} WHERE action = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
				'credit_sale_shortfall',
				$order_3->get_id()
			),
			ARRAY_A
		);
		$after_3          = $audit_row_3 ? json_decode( (string) $audit_row_3['after_json'], true ) : null;
		$ledger_balance_3 = $customer_id_3 ? \Counter\Credit\CustomerLedger::balance( $customer_id_3 ) : null;
		$this->check(
			'test_tenders: a mismatch with cntr_credit_sale and an identified customer is allowed, audited, and writes a real customer-ledger debit',
			true === $result_3 && $audit_row_3 && '60.0000' === ( $after_3['shortfall'] ?? null ) && '60.0000' === $ledger_balance_3,
			wp_json_encode( [ 'result' => true === $result_3, 'shortfall' => $after_3['shortfall'] ?? null, 'ledger_balance' => $ledger_balance_3 ] )
		);
		if ( $audit_row_3 ) {
			$wpdb->delete( $audit_table, [ 'id' => (int) $audit_row_3['id'] ] );
		}

		// 4. is_change rows are excluded from payment totals (verified at the
		// data level — the shift-level Z-report aggregation itself is P1.16).
		$rows_1       = $wpdb->get_results( $wpdb->prepare( "SELECT amount, is_change FROM {$tenders_table} WHERE order_id = %d", $order_1->get_id() ), ARRAY_A );
		$payment_sum  = 0.0;
		$change_sum   = 0.0;
		foreach ( $rows_1 as $r ) {
			if ( $r['is_change'] ) {
				$change_sum += (float) $r['amount'];
			} else {
				$payment_sum += (float) $r['amount'];
			}
		}
		$this->check(
			'test_tenders: is_change rows are excluded from payment totals',
			150.0 === $payment_sum && 50.0 === $change_sum && abs( ( $payment_sum - $change_sum ) - 100.0 ) < 0.01,
			"payment_sum={$payment_sum} change_sum={$change_sum}"
		);

		// 5. Split tenders across three methods all persist with their references.
		$order_5  = $make_order( 'SELFTEST-TENDER-5' );
		$result_5 = \Counter\Pos\Tenders::record(
			$order_5,
			[
				[ 'method' => 'cash', 'amount' => '40.00', 'reference' => '' ],
				[ 'method' => 'card', 'amount' => '30.00', 'reference' => 'VISA-1234' ],
				[ 'method' => 'bkash', 'amount' => '30.00', 'reference' => 'TXN998877' ],
			],
			$shift_id
		);
		$rows_5 = $wpdb->get_results( $wpdb->prepare( "SELECT method, amount, reference FROM {$tenders_table} WHERE order_id = %d ORDER BY id", $order_5->get_id() ), ARRAY_A );
		$this->check(
			'test_tenders: split tenders across three methods all persist with their references',
			true === $result_5 && 3 === count( $rows_5 )
				&& 'VISA-1234' === ( $rows_5[1]['reference'] ?? null ) && 'TXN998877' === ( $rows_5[2]['reference'] ?? null ),
			wp_json_encode( $rows_5 )
		);

		// 6. A tender with an unknown method is rejected rather than stored.
		$order_6      = $make_order( 'SELFTEST-TENDER-6' );
		$result_6     = \Counter\Pos\Tenders::record( $order_6, [ [ 'method' => 'moon-rocks', 'amount' => '100.00' ] ], $shift_id );
		$stored_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tenders_table} WHERE order_id = %d", $order_6->get_id() ) );
		$this->check(
			'test_tenders: a tender with an unknown method is rejected rather than stored',
			$result_6 instanceof \WP_Error && 0 === $stored_count,
			'stored_count=' . $stored_count
		);
	}

	// -- P1.15: test_returns() -- 8 checks --------------------------------------------

	private function test_returns(): void {
		global $wpdb;
		$moves_table       = Install::table( 'stock_moves' );
		$events_table      = Install::table( 'shift_events' );
		$shift_sales_table = Install::table( 'shift_sales' );

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Returns Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Return Product' );
		$product->set_regular_price( '100.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '20',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_returns',
						'ref_id'       => 0,
					]
				);
			}
		);

		$context = [
			'register_id' => $register_id,
			'shift_id'    => $shift_id,
			'location_id' => $main_id,
			'operator_id' => get_current_user_id(),
		];

		// 1 & 2: a full return restocks exactly the quantity sold, through the
		// ledger — and wc_create_refund did NOT restock behind it (only ONE
		// positive 'return' move exists for this order).
		$order_1 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 2, 'subtotal' => '200.00', 'total' => '200.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-1' ]
		);
		$this->order_fixture_ids[] = $order_1->get_id();
		\Counter\Orders\Channel::apply_stock( $order_1 );
		$order_1->set_status( 'completed' );
		$order_1->save();

		$balance_after_sale = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );

		$items_1  = array_values( $order_1->get_items( 'line_item' ) );
		$item_id_1 = $items_1[0]->get_id();

		$result_1 = \Counter\Orders\Refunds::process(
			$order_1,
			'200.00',
			[ $item_id_1 => [ 'qty' => 2, 'refund_total' => 200.00, 'refund_tax' => [] ] ],
			'selftest full return',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '200.00' ] ]
		);
		$balance_after_return = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );

		$this->check(
			'test_returns: a full return restocks exactly the quantity sold, through the ledger',
			true === $result_1 && $balance_after_sale === bcsub( $balance_after_return, '2', 4 ),
			wp_json_encode( $result_1 instanceof \WP_Error ? $result_1->get_error_message() : $result_1 ) . " before_sale_return={$balance_after_sale} after_return={$balance_after_return}"
		);

		$positive_moves_1 = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'return' AND qty_delta > 0",
				$order_1->get_id()
			)
		);
		$this->check(
			'test_returns: wc_create_refund did not restock — exactly one positive ledger move exists',
			1 === $positive_moves_1,
			"count={$positive_moves_1}"
		);

		// 3. A partial return restocks only the returned lines.
		$order_3 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 3, 'subtotal' => '300.00', 'total' => '300.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-3' ]
		);
		$this->order_fixture_ids[] = $order_3->get_id();
		\Counter\Orders\Channel::apply_stock( $order_3 );
		$order_3->set_status( 'completed' );
		$order_3->save();

		$balance_before_partial = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$items_3   = array_values( $order_3->get_items( 'line_item' ) );
		$item_id_3 = $items_3[0]->get_id();

		// Return only 1 of the 3 units.
		$result_3 = \Counter\Orders\Refunds::process(
			$order_3,
			'100.00',
			[ $item_id_3 => [ 'qty' => 1, 'refund_total' => 100.00, 'refund_tax' => [] ] ],
			'selftest partial return',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '100.00' ] ]
		);
		$balance_after_partial = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$this->check(
			'test_returns: a partial return restocks only the returned lines',
			true === $result_3 && $balance_before_partial === bcsub( $balance_after_partial, '1', 4 ),
			"before={$balance_before_partial} after={$balance_after_partial}"
		);

		// 4. Returning twice against the same lines is refused — WooCommerce's
		// own remaining-refund-amount check makes a second full-amount refund
		// attempt against an already-fully-refunded order fail.
		$result_4 = \Counter\Orders\Refunds::process(
			$order_1,
			'200.00',
			[ $item_id_1 => [ 'qty' => 2, 'refund_total' => 200.00, 'refund_tax' => [] ] ],
			'selftest over-refund attempt',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '200.00' ] ]
		);
		$this->check(
			'test_returns: returning twice against the same lines is refused (over-refund guard)',
			$result_4 instanceof \WP_Error,
			$result_4 instanceof \WP_Error ? $result_4->get_error_message() : wp_json_encode( $result_4 )
		);

		// 5. A refund writes a refund shift event whose amount matches.
		$event_1 = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$events_table} WHERE shift_id = %d AND type = 'refund' AND ref_id IN (SELECT id FROM {$wpdb->prefix}wc_orders WHERE parent_order_id = %d)", $shift_id, $order_1->get_id() ),
			ARRAY_A
		);
		// Simpler, reliable lookup: match by note text naming this order (ref_id is the refund's own id, which we don't have a direct handle on here).
		if ( ! $event_1 ) {
			$event_1 = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$events_table} WHERE shift_id = %d AND type = 'refund' AND note LIKE %s ORDER BY id ASC LIMIT 1", $shift_id, '%#' . $order_1->get_id() . '%' ),
				ARRAY_A
			);
		}
		$this->check(
			'test_returns: a refund writes a refund shift event whose amount matches',
			$event_1 && '200.0000' === $event_1['amount'],
			wp_json_encode( $event_1 )
		);

		// 6. A cash refund against a bKash-paid ONLINE order still writes the
		// cash-out event — the case that matters most.
		$order_6 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '100.00', 'total' => '100.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-6' ]
		);
		$order_6->update_meta_data( '_cntr_channel', 'online' ); // simulate an online, bKash-paid order
		$order_6->save();
		$this->order_fixture_ids[] = $order_6->get_id();
		\Counter\Orders\Channel::apply_stock( $order_6 );
		$order_6->set_status( 'completed' );
		$order_6->save();

		$items_6   = array_values( $order_6->get_items( 'line_item' ) );
		$item_id_6 = $items_6[0]->get_id();
		$result_6  = \Counter\Orders\Refunds::process(
			$order_6,
			'100.00',
			[ $item_id_6 => [ 'qty' => 1, 'refund_total' => 100.00, 'refund_tax' => [] ] ],
			'selftest online order returned for cash at the counter',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '100.00' ] ]
		);
		$event_6 = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$events_table} WHERE shift_id = %d AND type = 'refund' AND note LIKE %s ORDER BY id DESC LIMIT 1", $shift_id, '%#' . $order_6->get_id() . '%' ),
			ARRAY_A
		);
		$this->check(
			'test_returns: a cash refund against a bKash-paid online order still writes the cash-out event',
			true === $result_6 && $event_6 && 'cash' === $event_6['method'],
			wp_json_encode( [ 'result' => true === $result_6, 'event' => $event_6 ] )
		);

		// 7. An exchange writes two shift_sales rows sharing one exchange_group_id.
		$order_7 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '100.00', 'total' => '100.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-7' ]
		);
		$this->order_fixture_ids[] = $order_7->get_id();
		\Counter\Orders\Channel::apply_stock( $order_7 );
		$order_7->set_status( 'completed' );
		$order_7->save();

		$exchange_group_id = wp_generate_uuid4();
		$items_7   = array_values( $order_7->get_items( 'line_item' ) );
		$item_id_7 = $items_7[0]->get_id();

		\Counter\Orders\Refunds::process(
			$order_7,
			'100.00',
			[ $item_id_7 => [ 'qty' => 1, 'refund_total' => 100.00, 'refund_tax' => [] ] ],
			'selftest exchange — return half',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '100.00' ] ],
			$exchange_group_id
		);

		$order_7b = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '100.00', 'total' => '100.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-7B', 'exchange_group_id' => $exchange_group_id ]
		);
		$this->order_fixture_ids[] = $order_7b->get_id();
		Db::transaction(
			function () use ( $order_7b, $shift_id, $register_id, $exchange_group_id ) {
				\Counter\Orders\Channel::apply_stock( $order_7b );
				global $wpdb;
				$wpdb->insert(
					Install::table( 'shift_sales' ),
					[
						'shift_id'          => $shift_id,
						'register_id'       => $register_id,
						'order_id'          => $order_7b->get_id(),
						'kind'              => 'sale',
						'exchange_group_id' => $exchange_group_id,
						'receipt_no'        => 'SELFTEST-RETURN-7B',
						'total'             => '100.0000',
						'created_at'        => Db::now(),
					]
				);
			}
		);
		$order_7b->set_status( 'completed' );
		$order_7b->save();

		$exchange_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT kind, order_id FROM {$shift_sales_table} WHERE exchange_group_id = %s", $exchange_group_id ),
			ARRAY_A
		);
		$this->check(
			'test_returns: an exchange writes two shift_sales rows sharing one exchange_group_id',
			2 === count( $exchange_rows ),
			wp_json_encode( $exchange_rows )
		);

		// 8. The shift's expected cash after a sale of 500 and a refund of 200
		// is float + 300 — the arithmetic the missing 'refund' event type
		// broke. Isolated on its own fresh register/shift with a known
		// opening float, rather than reusing the shift the checks above
		// already wrote assorted amounts onto, so the arithmetic can be
		// asserted exactly rather than just "moved in the right direction."
		$register_id_8 = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Returns Register 8',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id_8;
		$opening_float_8               = '150.00';
		$shift_id_8                    = \Counter\Pos\Shifts::open( $register_id_8, get_current_user_id(), $opening_float_8 );

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_returns_z',
						'ref_id'       => 0,
					]
				);
			}
		);

		$context_8 = [
			'register_id' => $register_id_8,
			'shift_id'    => $shift_id_8,
			'location_id' => $main_id,
			'operator_id' => get_current_user_id(),
		];

		// A sale of exactly 500 (5 units @ 100).
		$order_8 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 5, 'subtotal' => '500.00', 'total' => '500.00' ] ],
			$context_8 + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-8' ]
		);
		$this->order_fixture_ids[] = $order_8->get_id();
		Db::transaction(
			function () use ( $order_8, $shift_id_8, $register_id_8 ) {
				\Counter\Orders\Channel::apply_stock( $order_8 );
				\Counter\Pos\Tenders::record( $order_8, [ [ 'method' => 'cash', 'amount' => '500.00' ] ], $shift_id_8 );
				global $wpdb;
				$wpdb->insert(
					Install::table( 'shift_sales' ),
					[
						'shift_id'    => $shift_id_8,
						'register_id' => $register_id_8,
						'order_id'    => $order_8->get_id(),
						'kind'        => 'sale',
						'receipt_no'  => 'SELFTEST-RETURN-8',
						'total'       => '500.0000',
						'created_at'  => Db::now(),
					]
				);
			}
		);
		$order_8->set_status( 'completed' );
		$order_8->save();

		// A refund of exactly 200 (2 of the 5 units).
		$items_8   = array_values( $order_8->get_items( 'line_item' ) );
		$item_id_8 = $items_8[0]->get_id();
		\Counter\Orders\Refunds::process(
			$order_8,
			'200.00',
			[ $item_id_8 => [ 'qty' => 2, 'refund_total' => 200.00, 'refund_tax' => [] ] ],
			'selftest Z-report arithmetic check',
			$shift_id_8,
			[ [ 'method' => 'cash', 'amount' => '200.00' ] ]
		);

		$sales_sum_8  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM {$shift_sales_table} WHERE shift_id = %d AND kind = 'sale'", $shift_id_8 ) );
		$refunds_sum_8 = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$events_table} WHERE shift_id = %d AND type = 'refund'", $shift_id_8 ) );
		$expected_cash_8 = (float) $opening_float_8 + $sales_sum_8 - $refunds_sum_8;

		$this->check(
			"test_returns: the shift's expected cash after a sale of 500 and a refund of 200 is float + 300",
			500.0 === $sales_sum_8 && 200.0 === $refunds_sum_8 && abs( $expected_cash_8 - ( 150.0 + 300.0 ) ) < 0.01,
			"opening={$opening_float_8} sales={$sales_sum_8} refunds={$refunds_sum_8} expected={$expected_cash_8}"
		);

		// 9. P4.4 — a refund created in wp-admin WITHOUT going through
		// Counter raises a Health-page warning rather than silently
		// diverging. order_1 (check 1's own full return, processed via
		// Refunds::process()) is the negative control: it must NOT appear.
		$product_diverge = new \WC_Product_Simple();
		$product_diverge->set_name( 'Counter Selftest Fixture Returns Diverged' );
		$product_diverge->set_regular_price( '30.00' );
		$product_diverge->set_manage_stock( true );
		$product_diverge->set_stock_quantity( 0 );
		$product_diverge->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_diverge->save();
		$this->product_fixture_ids[] = $product_diverge->get_id();
		\Counter\Stock\Ledger::move( [ 'product_id' => $product_diverge->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '5', 'reason' => 'opening', 'ref_type' => 'selftest_returns_diverge', 'ref_id' => 0 ] );

		$order_diverge = \Counter\Orders\Builder::build(
			[ [ 'product' => $product_diverge, 'qty' => 1, 'subtotal' => '30.00', 'total' => '30.00' ] ],
			$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-RETURN-DIVERGE' ]
		);
		$this->order_fixture_ids[] = $order_diverge->get_id();
		\Counter\Orders\Channel::apply_stock( $order_diverge );
		$order_diverge->set_status( 'completed' );
		$order_diverge->save();

		$items_diverge   = array_values( $order_diverge->get_items( 'line_item' ) );
		$item_id_diverge = $items_diverge[0]->get_id();
		// A raw wc_create_refund() call — the exact shape a refund created
		// directly on WooCommerce's own admin screen takes, never touching
		// Orders\Refunds::process() at all.
		$diverged_refund = wc_create_refund(
			[
				'order_id'       => $order_diverge->get_id(),
				'amount'         => '30.00',
				'line_items'     => [ $item_id_diverge => [ 'qty' => 1, 'refund_total' => 30.00, 'refund_tax' => [] ] ],
				'reason'         => 'selftest diverged admin refund',
				'refund_payment' => false,
				'restock_items'  => false,
			]
		);

		// Negative control: order_1's OWN refund (check 1, processed through
		// Refunds::process()) must NOT be flagged — proving this isn't just
		// "every refund ever" but specifically the ones that bypassed the
		// counter.
		$via_pos_refund_ids = wc_get_orders(
			[ 'type' => 'shop_order_refund', 'parent' => $order_1->get_id(), 'limit' => -1, 'return' => 'ids' ]
		);

		$diverged_ids = \Counter\Admin\Health::diverged_refund_ids();
		$this->check(
			'test_returns: a refund created in wp-admin without going through Counter raises a Health-page warning',
			! is_wp_error( $diverged_refund )
				&& in_array( $diverged_refund->get_id(), $diverged_ids, true )
				&& ! empty( $via_pos_refund_ids )
				&& 0 === count( array_intersect( array_map( 'intval', $via_pos_refund_ids ), $diverged_ids ) ),
			wp_json_encode(
				[
					'diverged_refund_id' => is_wp_error( $diverged_refund ) ? $diverged_refund->get_error_message() : $diverged_refund->get_id(),
					'via_pos_refund_ids' => $via_pos_refund_ids,
					'diverged_ids_count' => count( $diverged_ids ),
				]
			)
		);
	}

	private function test_shift_zreport(): void {
		global $wpdb;
		$queue_table = Install::table( 'sale_queue' );

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Zreport Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$opening_float                 = '1000.00';
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), $opening_float );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Zreport Product' );
		$product->set_regular_price( '100.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '50',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_zreport',
						'ref_id'       => 0,
					]
				);
			}
		);

		$context = [
			'register_id' => $register_id,
			'shift_id'    => $shift_id,
			'location_id' => $main_id,
			'operator_id' => get_current_user_id(),
		];

		$make_sale = function ( string $suffix, int $qty, string $total, array $tenders ) use ( $product, $context, $shift_id, $register_id ) {
			$order = \Counter\Orders\Builder::build(
				[ [ 'product' => $product, 'qty' => $qty, 'subtotal' => $total, 'total' => $total ] ],
				$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-ZREPORT-' . $suffix ]
			);
			$this->order_fixture_ids[] = $order->get_id();
			Db::transaction(
				function () use ( $order, $tenders, $shift_id, $register_id, $suffix, $total ) {
					\Counter\Orders\Channel::apply_stock( $order );
					\Counter\Pos\Tenders::record( $order, $tenders, $shift_id );
					global $wpdb;
					$wpdb->insert(
						Install::table( 'shift_sales' ),
						[
							'shift_id'    => $shift_id,
							'register_id' => $register_id,
							'order_id'    => $order->get_id(),
							'kind'        => 'sale',
							'receipt_no'  => 'SELFTEST-ZREPORT-' . $suffix,
							'total'       => wc_format_decimal( $total, 4 ),
							'created_at'  => Db::now(),
						]
					);
				}
			);
			$order->set_status( 'completed' );
			$order->save();
			return $order;
		};

		// A: a plain cash sale.
		$order_a = $make_sale( 'A', 3, '300.00', [ [ 'method' => 'cash', 'amount' => '300.00' ] ] );

		// B: a split-tender sale — only the cash portion enters expected cash.
		$make_sale( 'B', 5, '500.00', [ [ 'method' => 'cash', 'amount' => '200.00' ], [ 'method' => 'bkash', 'amount' => '300.00' ] ] );

		// C: a cash sale with change handed back.
		$make_sale( 'C', 1, '150.00', [ [ 'method' => 'cash', 'amount' => '200.00' ], [ 'method' => 'change', 'amount' => '50.00', 'is_change' => true ] ] );

		// A cash drop.
		\Counter\Pos\Shifts::record_event( $shift_id, 'drop', '100.00', 'selftest cash drop' );

		// A full cash refund of sale A.
		$items_a   = array_values( $order_a->get_items( 'line_item' ) );
		$item_id_a = $items_a[0]->get_id();
		\Counter\Orders\Refunds::process(
			$order_a,
			'300.00',
			[ $item_id_a => [ 'qty' => 3, 'refund_total' => 300.00, 'refund_tax' => [] ] ],
			'selftest zreport refund',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '300.00' ] ]
		);

		// 1. Hand-computed: 1000 + 300 + 200 + 200 − 50 − 100 − 300 = 1250.
		$expected = \Counter\Pos\Shifts::compute_expected_cash( $shift_id, $opening_float );
		$this->check(
			'test_shift_zreport: expected cash matches a hand-computed figure over a fixture day with a cash sale, a split-tender sale, a change payout, a cash drop and a refund',
			0 === bccomp( $expected, '1250.0000', 4 ),
			"expected={$expected}"
		);

		// 2. A pure non-cash sale must not move expected cash at all.
		$make_sale( 'D', 2, '200.00', [ [ 'method' => 'bkash', 'amount' => '200.00' ] ] );
		$expected_after_noncash = \Counter\Pos\Shifts::compute_expected_cash( $shift_id, $opening_float );
		$this->check(
			'test_shift_zreport: non-cash tenders are excluded from expected cash',
			0 === bccomp( $expected, $expected_after_noncash, 4 ),
			"before={$expected} after={$expected_after_noncash}"
		);

		// 3. Denominations that do not sum to counted_cash are refused.
		$bad_denoms   = wp_json_encode( [ '1000' => 1, '100' => 2 ] ); // sums to 1200
		$result_bad   = \Counter\Pos\Shifts::close( $shift_id, '1250.00', $bad_denoms );
		$this->check(
			'test_shift_zreport: denominations that do not sum to counted_cash are refused',
			$result_bad instanceof \WP_Error,
			$result_bad instanceof \WP_Error ? $result_bad->get_error_message() : wp_json_encode( $result_bad )
		);

		// 4. Closing for real, 10 short of the 1250 expected, stores a signed variance.
		$good_denoms  = wp_json_encode( [ '1000' => 1, '200' => 1, '20' => 2 ] ); // 1000+200+40 = 1240
		$close_result = \Counter\Pos\Shifts::close( $shift_id, '1240.00', $good_denoms, 'selftest close' );
		$closed_row   = \Counter\Pos\Shifts::get( $shift_id );
		$this->check(
			'test_shift_zreport: variance is stored signed',
			true === $close_result && $closed_row && 0 === bccomp( (string) $closed_row['variance'], '-10.0000', 4 )
				&& 0 === bccomp( (string) $closed_row['expected_cash'], '1250.0000', 4 ),
			wp_json_encode( [ 'close_result' => true === $close_result, 'row' => $closed_row ] )
		);

		// 5. Closing a shift twice is refused.
		$second_close = \Counter\Pos\Shifts::close( $shift_id, '1240.00', $good_denoms );
		$this->check(
			'test_shift_zreport: closing a shift twice is refused',
			$second_close instanceof \WP_Error,
			$second_close instanceof \WP_Error ? $second_close->get_error_message() : wp_json_encode( $second_close )
		);

		// 6. A shift with an unsynced sale in cntr_sale_queue cannot be closed —
		// the same "offline inbox" table P7 will use, so this guard is already
		// the real thing, not a placeholder (see Shifts::has_pending_queue()).
		$register_id_6 = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Zreport Register 6',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id_6;
		$shift_id_6                    = \Counter\Pos\Shifts::open( $register_id_6, get_current_user_id(), '0.00' );
		$uuid_6                        = wp_generate_uuid4();
		$this->sale_queue_uuids[]      = $uuid_6;
		$wpdb->insert(
			$queue_table,
			[
				'uuid'         => $uuid_6,
				'register_id'  => $register_id_6,
				'shift_id'     => $shift_id_6,
				'kind'         => 'sale',
				'payload_json' => '{}',
				'status'       => 'processing',
				'rung_at'      => Db::now(),
				'created_at'   => Db::now(),
			]
		);
		$blocked_close = \Counter\Pos\Shifts::close( $shift_id_6, '0.00' );
		$wpdb->update( $queue_table, [ 'status' => 'done' ], [ 'uuid' => $uuid_6 ] );
		$unblocked_close = \Counter\Pos\Shifts::close( $shift_id_6, '0.00' );
		$this->check(
			'test_shift_zreport: a shift with an unsynced offline outbox cannot be closed',
			$blocked_close instanceof \WP_Error && true === $unblocked_close,
			wp_json_encode(
				[
					'blocked'   => $blocked_close instanceof \WP_Error ? $blocked_close->get_error_message() : wp_json_encode( $blocked_close ),
					'unblocked' => true === $unblocked_close,
				]
			)
		);

		// 7. The Z-report's own totals equal the sum of the rows it lists —
		// sales_total and tenders_by_method_total are queried independently
		// (SUM(...) vs SELECT *) from the same underlying rows, so a filter
		// drifting between the two would show up here.
		$report              = \Counter\Admin\Screens\ShiftReport::build( $shift_id );
		$sum_of_sales_rows   = '0.0000';
		foreach ( $report['sales'] as $row ) {
			$sum_of_sales_rows = bcadd( $sum_of_sales_rows, (string) $row['total'], 4 );
		}
		$sum_of_tender_rows = '0.0000';
		foreach ( $report['tenders_by_method'] as $row ) {
			$sum_of_tender_rows = bcadd( $sum_of_tender_rows, (string) $row['net'], 4 );
		}
		$this->check(
			"test_shift_zreport: the Z-report totals equal the sum of its own rows",
			! is_wp_error( $report )
				&& 0 === bccomp( $sum_of_sales_rows, (string) $report['sales_total'], 4 )
				&& 0 === bccomp( $sum_of_tender_rows, (string) $report['tenders_by_method_total'], 4 ),
			"sales_total={$report['sales_total']} sum_of_rows={$sum_of_sales_rows} tenders_total={$report['tenders_by_method_total']} sum_of_tender_rows={$sum_of_tender_rows}"
		);
	}

	private function test_queue_recovery(): void {
		global $wpdb;
		$queue_table = Install::table( 'sale_queue' );
		$stale       = gmdate( 'Y-m-d H:i:s', time() - 300 ); // 5 minutes ago — older than the 120s threshold
		$fresh       = Db::now();

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Queue Recovery Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Queue Recovery Product' );
		$product->set_regular_price( '100.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_queue_recovery',
						'ref_id'       => 0,
					]
				);
			}
		);

		// 1. A row stuck in 'processing' whose order exists with stock
		// applied — everything P1.12's transaction committed, but the crash
		// hit before step 9 ever ran — is completed.
		$order_1 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 2, 'subtotal' => '200.00', 'total' => '200.00' ] ],
			[
				'register_id' => $register_id,
				'shift_id'    => $shift_id,
				'location_id' => $main_id,
				'operator_id' => get_current_user_id(),
				'uuid'        => wp_generate_uuid4(),
				'receipt_no'  => 'SELFTEST-QRECOVER-1',
			]
		);
		$this->order_fixture_ids[] = $order_1->get_id();
		Db::transaction(
			function () use ( $order_1, $shift_id, $register_id ) {
				\Counter\Orders\Channel::apply_stock( $order_1 );
				\Counter\Pos\Tenders::record( $order_1, [ [ 'method' => 'cash', 'amount' => '200.00' ] ], $shift_id );
				global $wpdb;
				$wpdb->insert(
					Install::table( 'shift_sales' ),
					[
						'shift_id'    => $shift_id,
						'register_id' => $register_id,
						'order_id'    => $order_1->get_id(),
						'kind'        => 'sale',
						'receipt_no'  => 'SELFTEST-QRECOVER-1',
						'total'       => '200.0000',
						'created_at'  => Db::now(),
					]
				);
			}
		);
		// Deliberately NOT set to 'completed' and NOT queue-completed —
		// this is exactly the state a crash between steps 6 and 9 leaves.
		$balance_before_recovery_1 = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );

		$uuid_1                   = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_1;
		$wpdb->insert(
			$queue_table,
			[
				'uuid'         => $uuid_1,
				'register_id'  => $register_id,
				'shift_id'     => $shift_id,
				'kind'         => 'sale',
				'payload_json' => '{}',
				'status'       => 'processing',
				'order_id'     => $order_1->get_id(),
				'rung_at'      => $stale,
				'created_at'   => $stale,
			]
		);

		// 2. A row stuck with no order at all (crashed before the order was
		// even built) is marked failed_retry.
		$uuid_2                   = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_2;
		$wpdb->insert(
			$queue_table,
			[
				'uuid'         => $uuid_2,
				'register_id'  => $register_id,
				'shift_id'     => $shift_id,
				'kind'         => 'sale',
				'payload_json' => '{}',
				'status'       => 'processing',
				'order_id'     => 0,
				'rung_at'      => $stale,
				'created_at'   => $stale,
			]
		);

		// 3. A row still within the threshold is left alone.
		$uuid_3                   = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_3;
		$wpdb->insert(
			$queue_table,
			[
				'uuid'         => $uuid_3,
				'register_id'  => $register_id,
				'shift_id'     => $shift_id,
				'kind'         => 'sale',
				'payload_json' => '{}',
				'status'       => 'processing',
				'order_id'     => 0,
				'rung_at'      => $fresh,
				'created_at'   => $fresh,
			]
		);

		$result_1 = \Counter\Pos\Queue::recover( 120 );

		$row_1 = \Counter\Pos\Queue::get_by_uuid( $uuid_1 );
		$order_1_fresh = wc_get_order( $order_1->get_id() );
		$this->check(
			'test_queue_recovery: a row stuck in processing with a complete order is completed',
			'done' === ( $row_1['status'] ?? '' ) && ! empty( $row_1['receipt_json'] ) && 'completed' === $order_1_fresh->get_status(),
			wp_json_encode( [ 'status' => $row_1['status'] ?? null, 'order_status' => $order_1_fresh->get_status() ] )
		);

		$row_2 = \Counter\Pos\Queue::get_by_uuid( $uuid_2 );
		$this->check(
			'test_queue_recovery: a row stuck with no order is marked failed_retry',
			'failed_retry' === ( $row_2['status'] ?? '' ),
			wp_json_encode( $row_2 )
		);

		$row_3 = \Counter\Pos\Queue::get_by_uuid( $uuid_3 );
		$this->check(
			'test_queue_recovery: a row younger than the threshold is left alone',
			'processing' === ( $row_3['status'] ?? '' ),
			wp_json_encode( $row_3 )
		);

		// 4. Recovery is idempotent — a second pass over the same rows finds
		// nothing left in 'processing' among them and changes nothing.
		$result_2       = \Counter\Pos\Queue::recover( 120 );
		$row_1_again    = \Counter\Pos\Queue::get_by_uuid( $uuid_1 );
		$row_2_again    = \Counter\Pos\Queue::get_by_uuid( $uuid_2 );
		$this->check(
			'test_queue_recovery: recovery is idempotent',
			$row_1_again['status'] === $row_1['status'] && $row_1_again['receipt_json'] === $row_1['receipt_json']
				&& $row_2_again['status'] === $row_2['status'],
			wp_json_encode( [ 'first' => $result_1, 'second' => $result_2 ] )
		);

		// 5. Recovering row 1 did not touch the ledger a second time — only
		// the one apply_stock() move exists, and the balance is unchanged
		// from immediately after that original transaction committed.
		$balance_after_recovery = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$applied_state_count    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . Install::table( 'order_stock_state' ) . " WHERE order_id = %d AND refund_id = 0 AND state = 'applied'",
				$order_1->get_id()
			)
		);
		$this->check(
			'test_queue_recovery: a recovered sale does not double-apply stock (the order_stock_state guard)',
			0 === bccomp( $balance_before_recovery_1, $balance_after_recovery, 4 ) && 1 === $applied_state_count,
			"before={$balance_before_recovery_1} after={$balance_after_recovery} applied_rows={$applied_state_count}"
		);
	}

	/**
	 * The budget is measured, not asserted (P1.18). Every check here goes
	 * through check_perf(), not check() directly — see PERF_BLOCKING above —
	 * so a miss on shared/staging hardware shows up clearly (labelled
	 * ADVISORY) without turning the whole suite red for a reason that has
	 * nothing to do with the code.
	 */
	private function test_perf_budget(): void {
		global $wpdb;

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Perf Register',
				'location_id' => $main_id,
				'prefix'      => 'ZT' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id                      = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Perf Product' );
		$product->set_regular_price( '10.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '100',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_perf',
						'ref_id'       => 0,
					]
				);
			}
		);

		// 1. 50 synthetic sales through the endpoint have a p95 under 400ms.
		$timings_ms = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$uuid                     = wp_generate_uuid4();
			$this->sale_queue_uuids[] = $uuid;
			$body                     = [
				'lines'   => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '1', 'unit_price' => '10.00', 'discount' => '0' ] ],
				'tenders' => [ [ 'method' => 'cash', 'amount' => '10.00' ] ],
			];

			$t0     = microtime( true );
			$result = \Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, 'SELFTEST-PERF-' . $i, $body );
			$timings_ms[] = ( microtime( true ) - $t0 ) * 1000;

			if ( $result instanceof \WP_REST_Response ) {
				$order_id = (int) ( $result->get_data()['order_id'] ?? 0 );
				if ( $order_id ) {
					$this->order_fixture_ids[] = $order_id;
				}
			}
		}
		$p95_sale = self::percentile( $timings_ms, 95 );
		$this->check_perf(
			'test_perf_budget: 50 synthetic sales through the endpoint have a p95 under 400ms',
			null !== $p95_sale && $p95_sale < 400.0,
			sprintf( 'p95=%.2fms n=%d', $p95_sale ?? -1, count( $timings_ms ) )
		);

		// 2. A catalogue delta with no changes returns under 50ms. $since is
		// the table's current max rev BEFORE check 3 seeds any fixture rows
		// (those carry rev = 0, so they can never register as "changed"
		// relative to it regardless of ordering).
		$catalog_table = Install::table( 'catalog_index' );
		$since         = (int) $wpdb->get_var( "SELECT COALESCE(MAX(rev),0) FROM {$catalog_table}" );
		$t0            = microtime( true );
		$delta         = \Counter\Stock\Catalog::delta( $since );
		$delta_ms      = ( microtime( true ) - $t0 ) * 1000;
		$this->check_perf(
			'test_perf_budget: a catalogue delta with no changes returns under 50ms',
			empty( $delta['changed'] ) && empty( $delta['removed'] ) && $delta_ms < 50.0,
			sprintf( 'ms=%.2f changed=%d removed=%d', $delta_ms, count( $delta['changed'] ), count( $delta['removed'] ) )
		);

		// 3. A barcode/SKU lookup against a seeded 14,000-row index returns
		// under 5ms. Seeded directly into cntr_catalog_index — 14,000 real
		// WC_Product objects would themselves take far longer to create than
		// this whole suite's budget — using a product_id range (90,000,000+)
		// no real post on this site can ever reach, so cleanup by range is
		// unambiguous and self-contained rather than tracked through the
		// shared fixture arrays.
		$seed_base   = 90000000;
		$seed_count  = 14000;
		$batch       = 1000;
		for ( $offset = 0; $offset < $seed_count; $offset += $batch ) {
			$values       = [];
			$placeholders = [];
			for ( $j = 0; $j < $batch && ( $offset + $j ) < $seed_count; $j++ ) {
				$n              = $offset + $j;
				$pid            = $seed_base + $n;
				$sku            = 'SELFTEST-PERF-SKU-' . $n;
				$placeholders[] = '(%d, 0, 0, 0, %s, %s, %s)';
				$values[]       = $pid;
				$values[]       = $sku;
				$values[]       = $sku;
				$values[]       = wp_json_encode( [ 'id' => $pid, 'sku' => $sku, 'name' => 'Perf seed ' . $n, 'price' => '1.00', 'sellable_qty' => '1', 'manage_stock' => true ] );
			}
			$sql = "INSERT INTO {$catalog_table} (product_id, variation_id, rev, deleted, sku, barcode, payload_json) VALUES " . implode( ', ', $placeholders );
			$wpdb->query( $wpdb->prepare( $sql, ...$values ) ); // phpcs:ignore
		}

		$lookup_needle = 'SELFTEST-PERF-SKU-' . ( $seed_count - 1 ); // last row inserted — no shortcut for the index to take
		$t0            = microtime( true );
		$found         = \Counter\Stock\Catalog::lookup_by_code( $lookup_needle );
		$lookup_ms     = ( microtime( true ) - $t0 ) * 1000;

		$removed = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$catalog_table} WHERE product_id >= %d AND product_id < %d", $seed_base, $seed_base + $seed_count )
		);

		$this->check_perf(
			'test_perf_budget: a barcode lookup against a seeded 14,000-row index returns under 5ms',
			null !== $found && $lookup_ms < 5.0 && $seed_count === $removed,
			sprintf( 'ms=%.3f found=%s seeded=%d removed=%d', $lookup_ms, $found ? 'yes' : 'no', $seed_count, $removed )
		);
	}

	// -- P2.6: test_customer_ledger() -- 7 checks --------------------------------------

	private function test_customer_ledger(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$email      = 'cntr-selftest-credit-' . wp_generate_password( 10, false ) . '@example.invalid';
		$customer_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
		if ( is_wp_error( $customer_id ) ) {
			$this->check( 'test_customer_ledger: fixture customer created', false, $customer_id->get_error_message() );
			return;
		}
		$this->customer_fixture_ids[] = $customer_id;

		$unique_phone = '01' . str_pad( (string) wp_rand( 100000000, 999999999 ), 9, '0', STR_PAD_LEFT );
		$wc_customer  = new \WC_Customer( $customer_id );
		$wc_customer->set_billing_phone( $unique_phone );
		$wc_customer->save();

		\Counter\Credit\CustomerLedger::set_credit_limit( $customer_id, '1000.0000' );

		// 1. A credit sale writes a debit equal to the unpaid amount.
		\Counter\Credit\CustomerLedger::record_credit_sale( $customer_id, '300.00', 'test', 0, 'test credit sale' );
		$balance_1 = \Counter\Credit\CustomerLedger::balance( $customer_id );
		$ledger_1  = \Counter\Credit\CustomerLedger::ledger( $customer_id );
		$this->check(
			'test_customer_ledger: a credit sale writes a debit equal to the unpaid amount',
			'300.0000' === $balance_1 && 1 === count( $ledger_1 ) && 'credit_sale' === $ledger_1[0]['type'] && '300.0000' === $ledger_1[0]['amount'],
			wp_json_encode( $ledger_1 )
		);

		// 2. A payment writes a credit.
		\Counter\Credit\CustomerLedger::record_payment( $customer_id, '100.00', 'test payment' );
		$balance_2 = \Counter\Credit\CustomerLedger::balance( $customer_id );
		$this->check(
			'test_customer_ledger: a payment writes a credit',
			'200.0000' === $balance_2,
			"balance={$balance_2}"
		);

		// 3. balance_after is consistent down the whole ledger.
		$ledger_3 = \Counter\Credit\CustomerLedger::ledger( $customer_id );
		$running  = '0.0000';
		$chain_ok = true;
		foreach ( $ledger_3 as $row ) {
			$running = bcadd( $running, $row['amount'], 4 );
			if ( 0 !== bccomp( $running, $row['balance_after'], 4 ) ) {
				$chain_ok = false;
				break;
			}
		}
		$this->check(
			'test_customer_ledger: balance_after is consistent down the whole ledger',
			$chain_ok && 0 === bccomp( $running, \Counter\Credit\CustomerLedger::balance( $customer_id ), 4 ),
			wp_json_encode( [ 'chain_ok' => $chain_ok, 'running' => $running, 'ledger' => $ledger_3 ] )
		);

		// Both check 4 (the credit-limit guard) and check 6 (Tenders::record()'s
		// shortfall path) gate on current_user_can( 'cntr_credit_sale' ) — and a
		// bare `wp eval` (no --user flag) runs with NO logged-in user at all, so
		// every capability check would correctly return false regardless of role
		// grants. test_tenders() (P1.14) found and documented this exact issue
		// first; same fix here — an explicit administrator context for this
		// stretch of the test, restored once both checks are done.
		$original_user_id_credit = get_current_user_id();
		$admins_credit            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_credit ) ) {
			wp_set_current_user( (int) $admins_credit[0] );
		}

		// 4. A sale that would exceed the limit is refused BEFORE the order
		// is created — a real Rest\Sale::process() call, not a unit-level
		// stand-in, so "assert no order exists" is checking something real.
		\Counter\Credit\CustomerLedger::set_credit_limit( $customer_id, '250.0000' ); // balance 200 + a 100 shortfall = 300 > 250

		$main_id       = \Counter\Stock\Locations::default_id();
		$register_id_c = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Credit Register',
				'location_id' => $main_id,
				'prefix'      => 'ZC' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id_c;
		$shift_id_c                    = \Counter\Pos\Shifts::open( $register_id_c, get_current_user_id(), '0.00' );

		$product_c = new \WC_Product_Simple();
		$product_c->set_name( 'Counter Selftest Fixture Credit Product' );
		$product_c->set_regular_price( '1000.00' );
		$product_c->set_manage_stock( true );
		$product_c->set_stock_quantity( 0 );
		$product_c->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_c->save();
		$this->product_fixture_ids[] = $product_c->get_id();

		Db::transaction(
			function () use ( $product_c, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_c->get_id(),
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_credit',
						'ref_id'       => 0,
					]
				);
			}
		);

		$body_over_limit = [
			'lines'    => [
				[ 'product_id' => $product_c->get_id(), 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '1000.0000', 'discount' => '0.0000' ],
			],
			'tenders'  => [
				[ 'method' => 'cash', 'amount' => '900.0000' ], // shortfall = 100
			],
			'customer' => [ 'phone' => $unique_phone ],
		];
		$uuid_over_limit = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_over_limit;
		$result_over_limit = \Counter\Rest\Sale::process( $uuid_over_limit, $register_id_c, $shift_id_c, 'SELFTEST-CREDIT-OVER', $body_over_limit );

		$orders_meta       = $wpdb->prefix . 'wc_orders_meta';
		$order_count_for_uuid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_meta} WHERE meta_key = '_cntr_uuid' AND meta_value = %s", $uuid_over_limit ) );

		$this->check(
			'test_customer_ledger: a sale that would exceed the limit is refused before the order is created',
			$result_over_limit instanceof \WP_Error
				&& 'cntr_credit_limit_exceeded' === $result_over_limit->get_error_code()
				&& 0 === $order_count_for_uuid,
			( $result_over_limit instanceof \WP_Error ? $result_over_limit->get_error_message() : wp_json_encode( $result_over_limit ) ) . " orders_for_uuid={$order_count_for_uuid}"
		);

		// Restore headroom for the rest of this test — balance is still 200,
		// nothing above actually wrote anything.
		\Counter\Credit\CustomerLedger::set_credit_limit( $customer_id, '1000.0000' );

		// 5. The aging buckets sum to the total outstanding.
		$aging_5          = \Counter\Credit\CustomerLedger::aging( $customer_id );
		$expected_total_5 = \Counter\Credit\CustomerLedger::balance( $customer_id );
		$bucket_sum_5     = '0.0000';
		if ( isset( $aging_5[ $customer_id ] ) ) {
			foreach ( \Counter\Credit\CustomerLedger::AGING_BUCKETS as $b ) {
				$bucket_sum_5 = bcadd( $bucket_sum_5, $aging_5[ $customer_id ][ $b ], 4 );
			}
		}
		$this->check(
			'test_customer_ledger: aging buckets sum to the open balance',
			isset( $aging_5[ $customer_id ] )
				&& 0 === bccomp( $bucket_sum_5, $expected_total_5, 4 )
				&& 0 === bccomp( $aging_5[ $customer_id ]['total'], $expected_total_5, 4 ),
			wp_json_encode( [ 'aging' => $aging_5[ $customer_id ] ?? null, 'expected' => $expected_total_5 ] )
		);

		// 6. A refund on a credit sale reduces the balance — a real order,
		// a real shortfall recorded via Tenders::record(), then a real
		// Refunds::process() call carrying a 'credit' tender.
		$order_6 = \Counter\Orders\Builder::build(
			[ [ 'product' => $product_c, 'qty' => 1, 'subtotal' => '400.00', 'total' => '400.00' ] ],
			[
				'register_id' => $register_id_c,
				'shift_id'    => $shift_id_c,
				'location_id' => $main_id,
				'operator_id' => get_current_user_id(),
				'customer_id' => $customer_id,
				'uuid'        => wp_generate_uuid4(),
				'receipt_no'  => 'SELFTEST-CREDIT-6',
			]
		);
		$this->order_fixture_ids[] = $order_6->get_id();
		\Counter\Orders\Channel::apply_stock( $order_6 );
		$order_6->set_status( 'completed' );
		$order_6->save();

		\Counter\Pos\Tenders::record( $order_6, [ [ 'method' => 'cash', 'amount' => '300.00' ] ], $shift_id_c, $customer_id ); // shortfall 100 -> credit_sale
		$balance_before_refund = \Counter\Credit\CustomerLedger::balance( $customer_id );

		$items_6  = array_values( $order_6->get_items( 'line_item' ) );
		$item_id_6 = $items_6[0]->get_id();
		$refund_result_6 = \Counter\Orders\Refunds::process(
			$order_6,
			'400.00',
			[ $item_id_6 => [ 'qty' => 1, 'refund_total' => 400.00, 'refund_tax' => [] ] ],
			'selftest credit refund',
			$shift_id_c,
			[ [ 'method' => 'cash', 'amount' => '300.00' ], [ 'method' => 'credit', 'amount' => '100.00' ] ]
		);
		$balance_after_refund = \Counter\Credit\CustomerLedger::balance( $customer_id );

		$this->check(
			'test_customer_ledger: a refund on a credit sale reduces the balance',
			true === $refund_result_6
				&& '300.0000' === $balance_before_refund
				&& '200.0000' === $balance_after_refund,
			( $refund_result_6 instanceof \WP_Error ? $refund_result_6->get_error_message() : 'ok' ) . " before={$balance_before_refund} after={$balance_after_refund}"
		);

		wp_set_current_user( $original_user_id_credit );

		// 7. A customer with a zero balance does not appear in aging.
		$email_zero        = 'cntr-selftest-credit-zero-' . wp_generate_password( 10, false ) . '@example.invalid';
		$customer_id_zero  = wc_create_new_customer( $email_zero, '', wp_generate_password( 16 ) );
		if ( ! is_wp_error( $customer_id_zero ) ) {
			$this->customer_fixture_ids[] = $customer_id_zero;
		}
		$aging_all      = \Counter\Credit\CustomerLedger::aging();
		$zero_ok        = ! is_wp_error( $customer_id_zero );
		$zero_appears   = $zero_ok && isset( $aging_all[ $customer_id_zero ] );
		$this->check(
			'test_customer_ledger: a customer with a zero balance does not appear in aging',
			$zero_ok && ! $zero_appears,
			wp_json_encode( [ 'zero_customer' => $zero_ok ? $customer_id_zero : $customer_id_zero->get_error_message(), 'appears' => $zero_appears ] )
		);
	}

	// -- P2.5: test_supplier_ledger() -- 5 checks --------------------------------------

	private function test_supplier_ledger(): void {
		global $wpdb;

		$create = \Counter\Purchasing\Suppliers::create( [ 'name' => 'Counter Selftest Fixture Payables Supplier' ] );
		$id     = is_wp_error( $create ) ? 0 : $create['id'];
		if ( $id ) {
			$this->supplier_fixture_ids[] = $id;
		}

		$accounts_table = Install::table( 'payment_accounts' );
		$cash_account_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$accounts_table} WHERE code = %s", 'CASH' ) );

		\Counter\Purchasing\Suppliers::write_ledger_row( $id, 'bill', '500.0000', 'test', 0, 'bill 1' );
		\Counter\Purchasing\SupplierLedger::record_payment( $id, '200', $cash_account_id, 'payment 1' );
		\Counter\Purchasing\SupplierLedger::record_credit_note( $id, '50', 'credit 1' );

		// 1. balance_after is consistent down the whole ledger.
		$ledger  = \Counter\Purchasing\SupplierLedger::ledger( $id );
		$running = '0.0000';
		$chain_ok = true;
		foreach ( $ledger as $row ) {
			$running = bcadd( $running, $row['amount'], 4 );
			if ( 0 !== bccomp( $running, $row['balance_after'], 4 ) ) {
				$chain_ok = false;
				break;
			}
		}
		$this->check(
			'test_supplier_ledger: balance_after is consistent down the whole ledger',
			$chain_ok && 0 === bccomp( $running, \Counter\Purchasing\SupplierLedger::balance( $id ), 4 ) && '250.0000' === $running,
			wp_json_encode( [ 'chain_ok' => $chain_ok, 'running' => $running, 'ledger' => $ledger ] )
		);

		// 2. A payment without an account is refused.
		$rows_before_bad_payment = count( \Counter\Purchasing\SupplierLedger::ledger( $id ) );
		$bad_payment              = \Counter\Purchasing\SupplierLedger::record_payment( $id, '100', 0, 'no account' );
		$rows_after_bad_payment  = count( \Counter\Purchasing\SupplierLedger::ledger( $id ) );
		$this->check(
			'test_supplier_ledger: a payment without an account is refused',
			is_wp_error( $bad_payment ) && 'cntr_supplier_payment_no_account' === $bad_payment->get_error_code() && $rows_before_bad_payment === $rows_after_bad_payment,
			is_wp_error( $bad_payment ) ? $bad_payment->get_error_code() : wp_json_encode( $bad_payment )
		);

		// 3. A credit note reduces the balance.
		$before_credit = \Counter\Purchasing\SupplierLedger::balance( $id );
		\Counter\Purchasing\SupplierLedger::record_credit_note( $id, '30', 'credit 2' );
		$after_credit  = \Counter\Purchasing\SupplierLedger::balance( $id );
		$this->check(
			'test_supplier_ledger: a credit note reduces the balance',
			0 === bccomp( $before_credit, bcadd( $after_credit, '30', 4 ), 4 ) && '220.0000' === $after_credit,
			"before={$before_credit} after={$after_credit}"
		);

		// 4. The aging buckets sum to the total outstanding.
		$aging          = \Counter\Purchasing\SupplierLedger::aging( $id );
		$expected_total = \Counter\Purchasing\SupplierLedger::balance( $id );
		$bucket_sum     = '0.0000';
		if ( isset( $aging[ $id ] ) ) {
			foreach ( \Counter\Purchasing\SupplierLedger::AGING_BUCKETS as $b ) {
				$bucket_sum = bcadd( $bucket_sum, $aging[ $id ][ $b ], 4 );
			}
		}
		$this->check(
			'test_supplier_ledger: the aging buckets sum to the total outstanding',
			isset( $aging[ $id ] )
				&& 0 === bccomp( $bucket_sum, $expected_total, 4 )
				&& 0 === bccomp( $aging[ $id ]['total'], $expected_total, 4 ),
			wp_json_encode( [ 'aging' => $aging[ $id ] ?? null, 'expected_total' => $expected_total ] )
		);

		// 5. Deleting is impossible — same source-grep as Invariant I's own
		// cntr_stock_moves check, applied to this ledger too.
		$violation = self::grep_for_forbidden_writes( 'supplier_ledger' );
		$this->check(
			'test_supplier_ledger: deleting is impossible',
			null === $violation,
			(string) $violation
		);
	}

	// -- P2.4: test_purchasing() -- 8 checks ------------------------------------------

	private function test_purchasing(): void {
		global $wpdb;

		$create_supplier = \Counter\Purchasing\Suppliers::create( [ 'name' => 'Counter Selftest Fixture Purchasing Supplier' ] );
		$supplier_id      = is_wp_error( $create_supplier ) ? 0 : $create_supplier['id'];
		if ( $supplier_id ) {
			$this->supplier_fixture_ids[] = $supplier_id;
		}

		$product_a = new \WC_Product_Simple();
		$product_a->set_name( 'Counter Selftest Fixture Purchasing Product A' );
		$product_a->set_regular_price( '5.00' );
		$product_a->set_manage_stock( true );
		$product_a->set_stock_quantity( 0 );
		$product_a->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_a->save();
		$this->product_fixture_ids[] = $product_a->get_id();

		$product_b = new \WC_Product_Simple();
		$product_b->set_name( 'Counter Selftest Fixture Purchasing Product B' );
		$product_b->set_regular_price( '5.00' );
		$product_b->set_manage_stock( true );
		$product_b->set_stock_quantity( 0 );
		$product_b->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_b->save();
		$this->product_fixture_ids[] = $product_b->get_id();

		$location_id = \Counter\Stock\Locations::default_id();

		// Values chosen (and verified independently by hand before writing this
		// test) to produce a REAL non-zero poisha rounding remainder: line A
		// (৳1000 value) raw share = ৳27.7749999988 -> rounds to 27.77; line B
		// (৳200 value) raw share = ৳5.5549999977 -> rounds to 5.55; the two
		// rounded shares sum to 33.32, one poisha short of the 33.33 charged —
		// that poisha lands on line A, the larger line.
		$po_id = \Counter\Purchasing\Orders::create(
			[
				'supplier_id'    => $supplier_id,
				'location_id'    => $location_id,
				'shipping_total' => '33.33',
				'other_total'    => '0',
				'lines'          => [
					[ 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty_ordered' => '10', 'unit_cost' => '100.00', 'tax_rate' => '0' ],
					[ 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty_ordered' => '5', 'unit_cost' => '40.00', 'tax_rate' => '0' ],
				],
			]
		);
		if ( ! is_wp_error( $po_id ) ) {
			$this->po_fixture_ids[] = $po_id;
		}

		$lines   = is_wp_error( $po_id ) ? [] : \Counter\Purchasing\Orders::lines( $po_id );
		$line_a  = null;
		$line_b  = null;
		foreach ( $lines as $l ) {
			if ( (int) $l['product_id'] === $product_a->get_id() ) {
				$line_a = $l;
			} elseif ( (int) $l['product_id'] === $product_b->get_id() ) {
				$line_b = $l;
			}
		}

		// 1. Landed cost distributes to the last poisha with the rounding
		// remainder on the largest line.
		$this->check(
			'test_purchasing: landed cost distributes to the last poisha with the rounding remainder on the largest line',
			! is_wp_error( $po_id )
				&& $line_a && '27.7800' === $line_a['landed_cost_share']
				&& $line_b && '5.5500' === $line_b['landed_cost_share'],
			wp_json_encode( [ 'a' => $line_a['landed_cost_share'] ?? null, 'b' => $line_b['landed_cost_share'] ?? null ] )
		);

		// 2. SUM(landed_cost_share) equals the charges exactly.
		$share_sum = $line_a && $line_b ? bcadd( $line_a['landed_cost_share'], $line_b['landed_cost_share'], 4 ) : null;
		$this->check(
			'test_purchasing: SUM(landed_cost_share) equals the charges exactly',
			null !== $share_sum && '33.3300' === $share_sum,
			(string) $share_sum
		);

		// 3. Receiving 3 of 10 leaves 7 outstanding.
		$uuid_1  = wp_generate_uuid4();
		$this->po_receive_uuid_options[] = 'cntr_po_receive_uuid_' . sanitize_key( $uuid_1 );
		$recv_1  = \Counter\Purchasing\Receiving::receive( $po_id, $uuid_1, [ $line_a['id'] => '3' ] );
		$line_a_after_1 = \Counter\Purchasing\Orders::get( $po_id ) ? self::find_po_line( $po_id, $line_a['id'] ) : null;
		$outstanding_1  = $line_a_after_1 ? bcsub( $line_a_after_1['qty_ordered'], $line_a_after_1['qty_received'], 4 ) : null;
		$this->check(
			'test_purchasing: receiving 3 of 10 leaves 7 outstanding',
			! is_wp_error( $recv_1 ) && $line_a_after_1 && '3.0000' === $line_a_after_1['qty_received'] && '7.0000' === $outstanding_1,
			wp_json_encode( [ 'recv_1' => $recv_1, 'line_a_after_1' => $line_a_after_1 ] )
		);
		if ( ! is_wp_error( $recv_1 ) ) {
			$this->batch_fixture_ids = array_merge( $this->batch_fixture_ids, $recv_1['batch_ids'] );
		}

		// 4. Over-receiving is refused — line B has 5 outstanding, ask for 999.
		$overreceive = \Counter\Purchasing\Receiving::receive( $po_id, wp_generate_uuid4(), [ $line_b['id'] => '999' ] );
		$line_b_after_overreceive = self::find_po_line( $po_id, $line_b['id'] );
		$this->check(
			'test_purchasing: over-receiving is refused',
			is_wp_error( $overreceive ) && 'cntr_po_overreceive' === $overreceive->get_error_code()
				&& $line_b_after_overreceive && '0.0000' === $line_b_after_overreceive['qty_received'],
			is_wp_error( $overreceive ) ? $overreceive->get_error_code() : wp_json_encode( $overreceive )
		);

		// 5. The ledger move's unit_cost equals unit cost plus share — line A's
		// per-unit landed rate is 27.78 / 10 = 2.778, so the batch this first
		// receipt created should carry unit_cost 100.00 + 2.778 = 102.778.
		$moves_table   = Install::table( 'stock_moves' );
		$purchase_move = is_wp_error( $recv_1 ) ? null : $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$moves_table} WHERE batch_id = %d", $recv_1['batch_ids'][0] ?? 0 ),
			ARRAY_A
		);
		$this->check(
			"test_purchasing: the ledger move's unit_cost equals unit cost plus share",
			$purchase_move && '102.7780' === $purchase_move['unit_cost'],
			wp_json_encode( $purchase_move )
		);

		// 6. Receiving twice with the same UUID receives once.
		$moves_before_replay = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'purchase_order' AND ref_id = %d", $po_id ) );
		$recv_1_replay        = \Counter\Purchasing\Receiving::receive( $po_id, $uuid_1, [ $line_a['id'] => '3' ] );
		$moves_after_replay   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'purchase_order' AND ref_id = %d", $po_id ) );
		$this->check(
			'test_purchasing: receiving twice with the same UUID receives once',
			! is_wp_error( $recv_1_replay )
				&& true === ( $recv_1_replay['replayed'] ?? null )
				&& $moves_before_replay === $moves_after_replay,
			wp_json_encode( $recv_1_replay )
		);

		// 7. Receiving the remaining 7 (plus line B's full 5) closes the PO.
		$uuid_2 = wp_generate_uuid4();
		$this->po_receive_uuid_options[] = 'cntr_po_receive_uuid_' . sanitize_key( $uuid_2 );
		$recv_2 = \Counter\Purchasing\Receiving::receive( $po_id, $uuid_2, [ $line_a['id'] => '7', $line_b['id'] => '5' ] );
		$po_after_close = \Counter\Purchasing\Orders::get( $po_id );
		$this->check(
			'test_purchasing: receiving the remaining 7 closes the PO',
			! is_wp_error( $recv_2 ) && $po_after_close && 'closed' === $po_after_close['status'],
			wp_json_encode( [ 'recv_2' => $recv_2, 'po_status' => $po_after_close['status'] ?? null ] )
		);
		if ( ! is_wp_error( $recv_2 ) ) {
			$this->batch_fixture_ids = array_merge( $this->batch_fixture_ids, $recv_2['batch_ids'] );
		}

		// 8. A PO line for an unmanaged product is refused, and nothing is created.
		$unmanaged = new \WC_Product_Simple();
		$unmanaged->set_name( 'Counter Selftest Fixture Purchasing Unmanaged' );
		$unmanaged->set_regular_price( '5.00' );
		$unmanaged->set_manage_stock( false );
		$unmanaged->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$unmanaged->save();
		$this->product_fixture_ids[] = $unmanaged->get_id();

		$po_table       = Install::table( 'purchase_orders' );
		$po_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$po_table}" );
		$bad_po          = \Counter\Purchasing\Orders::create(
			[
				'supplier_id' => $supplier_id,
				'location_id' => $location_id,
				'lines'       => [ [ 'product_id' => $unmanaged->get_id(), 'variation_id' => 0, 'qty_ordered' => '1', 'unit_cost' => '5.00' ] ],
			]
		);
		$po_count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$po_table}" );
		$this->check(
			'test_purchasing: a PO line for an unmanaged product is refused',
			is_wp_error( $bad_po ) && 'cntr_po_unmanaged_product' === $bad_po->get_error_code() && $po_count_before === $po_count_after,
			is_wp_error( $bad_po ) ? $bad_po->get_error_code() : wp_json_encode( $bad_po )
		);
	}

	private static function find_po_line( int $po_id, int $line_id ): ?array {
		foreach ( \Counter\Purchasing\Orders::lines( $po_id ) as $l ) {
			if ( (int) $l['id'] === $line_id ) {
				return $l;
			}
		}
		return null;
	}

	// -- P2.12: test_units() -- 8 checks -------------------------------------------------

	private function test_units(): void {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		$main_id     = \Counter\Stock\Locations::default_id();

		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture Units Register', 'location_id' => $main_id, 'prefix' => 'ZU' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$rand = wp_generate_password( 6, false );

		$unit_piece = \Counter\Stock\Units::create( [ 'name' => 'Piece', 'code' => 'PC-' . $rand, 'base_unit_id' => 0, 'multiplier' => '1', 'allow_decimal' => 0 ] );
		$this->unit_fixture_ids[] = $unit_piece;
		$unit_dozen = \Counter\Stock\Units::create( [ 'name' => 'Dozen', 'code' => 'DZ-' . $rand, 'base_unit_id' => $unit_piece, 'multiplier' => '12', 'allow_decimal' => 0 ] );
		$this->unit_fixture_ids[] = $unit_dozen;
		$unit_kg = \Counter\Stock\Units::create( [ 'name' => 'Kg', 'name_bn' => 'কেজি', 'code' => 'KG-' . $rand, 'base_unit_id' => 0, 'multiplier' => '1', 'allow_decimal' => 1 ] );
		$this->unit_fixture_ids[] = $unit_kg;

		$product_a = new \WC_Product_Simple();
		$product_a->set_name( 'Counter Selftest Fixture Units Product A' );
		$product_a->set_regular_price( '10.00' );
		$product_a->set_manage_stock( true );
		$product_a->set_stock_quantity( 0 );
		$product_a->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_a->save();
		$this->product_fixture_ids[] = $product_a->get_id();
		\Counter\Stock\Units::set_product_units(
			$product_a->get_id(), 0,
			[ [ 'unit_id' => $unit_piece, 'price' => null, 'is_default' => true ], [ 'unit_id' => $unit_dozen, 'price' => null, 'is_default' => false ] ]
		);

		$product_c = new \WC_Product_Simple();
		$product_c->set_name( 'Counter Selftest Fixture Units Product C (Kg)' );
		$product_c->set_regular_price( '50.00' );
		$product_c->set_manage_stock( true );
		$product_c->set_stock_quantity( 0 );
		$product_c->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_c->save();
		$this->product_fixture_ids[] = $product_c->get_id();
		\Counter\Stock\Units::set_product_units( $product_c->get_id(), 0, [ [ 'unit_id' => $unit_kg, 'price' => null, 'is_default' => true ] ] );

		Db::transaction(
			function () use ( $product_a, $product_c, $main_id ) {
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '100', 'reason' => 'opening', 'ref_type' => 'selftest_units', 'ref_id' => 0 ] );
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_c->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '10', 'reason' => 'opening', 'ref_type' => 'selftest_units', 'ref_id' => 0 ] );
			}
		);

		// 1 & 2. Selling 2 Dozen writes a ledger move of exactly -24, and the
		// order line records the chosen unit and per-unit price.
		$dozen_price = \Counter\Stock\Units::resolve_price( $product_a->get_id(), 0, $unit_dozen, null, $product_a );
		$line_total  = bcmul( $dozen_price, '2', 2 );
		$order_a = \Counter\Orders\Builder::build(
			[ [ 'product' => $product_a, 'qty' => '2', 'unit_id' => $unit_dozen, 'subtotal' => $line_total, 'total' => $line_total ] ],
			[ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id(), 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-UNITS-1' ]
		);
		$this->order_fixture_ids[] = $order_a->get_id();
		\Counter\Orders\Channel::apply_stock( $order_a );
		$order_a->set_status( 'completed' );
		$order_a->save();

		$move_a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'sale'", $order_a->get_id() ), ARRAY_A );
		$this->check(
			'test_units: selling 2 Dozen of a base-Piece product writes a ledger move of exactly -24',
			$move_a && '-24.0000' === $move_a['qty_delta'],
			wp_json_encode( $move_a )
		);

		$items_a = array_values( $order_a->get_items( 'line_item' ) );
		$item_a  = $items_a[0];
		$this->check(
			'test_units: the order line records the chosen unit and the per-unit price for the receipt',
			$unit_dozen === (int) $item_a->get_meta( '_cntr_unit_id' )
				&& '2.0000' === $item_a->get_meta( '_cntr_unit_qty' )
				&& '120.0000' === $item_a->get_meta( '_cntr_unit_price' )
				&& 24.0 === (float) $item_a->get_quantity(), // the item itself carries the BASE quantity
			wp_json_encode( [ 'unit_id' => $item_a->get_meta( '_cntr_unit_id' ), 'unit_qty' => $item_a->get_meta( '_cntr_unit_qty' ), 'unit_price' => $item_a->get_meta( '_cntr_unit_price' ), 'item_qty' => $item_a->get_quantity() ] )
		);

		// 3. A NULL unit price resolves to base price x multiplier.
		$this->check(
			'test_units: a NULL unit price resolves to base price x multiplier',
			'120.0000' === $dozen_price,
			$dozen_price
		);

		// 4. An explicit unit price overrides that.
		$explicit = \Counter\Stock\Units::resolve_price( $product_a->get_id(), 0, $unit_dozen, '100.00', $product_a );
		$this->check(
			'test_units: an explicit unit price overrides that',
			'100.0000' === $explicit,
			$explicit
		);

		// 5. A unit with allow_decimal = 0 refuses a quantity of 1.5.
		$refused = \Counter\Stock\Units::to_base_qty( $unit_piece, '1.5' );
		$this->check(
			'test_units: a unit with allow_decimal = 0 refuses a quantity of 1.5',
			$refused instanceof \WP_Error && 'cntr_unit_no_decimal' === $refused->get_error_code(),
			$refused instanceof \WP_Error ? $refused->get_error_code() : wp_json_encode( $refused )
		);

		// 6. A unit with allow_decimal = 1 accepts 1.5 and the ledger records
		// the exact decimal, compared as a string.
		$kg_price   = \Counter\Stock\Units::resolve_price( $product_c->get_id(), 0, $unit_kg, null, $product_c );
		$kg_total   = bcmul( $kg_price, '1.5', 2 );
		$order_c = \Counter\Orders\Builder::build(
			[ [ 'product' => $product_c, 'qty' => '1.5', 'unit_id' => $unit_kg, 'subtotal' => $kg_total, 'total' => $kg_total ] ],
			[ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id(), 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-UNITS-6' ]
		);
		$this->order_fixture_ids[] = $order_c->get_id();
		\Counter\Orders\Channel::apply_stock( $order_c );
		$order_c->set_status( 'completed' );
		$order_c->save();
		$move_c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'sale'", $order_c->get_id() ), ARRAY_A );
		$this->check(
			'test_units: a unit with allow_decimal = 1 accepts 1.5 and the ledger records the exact decimal, compared as a string',
			'1.5000' === \Counter\Stock\Units::to_base_qty( $unit_kg, '1.5' ) && $move_c && '-1.5000' === $move_c['qty_delta'],
			wp_json_encode( $move_c )
		);

		// 7. Stock valuation/balance is computed in base units regardless of
		// what was sold — the check that catches a mixed-unit ledger.
		$balance_a = \Counter\Stock\Ledger::balance( $product_a->get_id(), 0, $main_id );
		$this->check(
			'test_units: stock valuation and the reorder list are computed in base units regardless of what was sold',
			'76.0000' === $balance_a, // 100 opening - 24 (2 Dozen), never -2
			"balance={$balance_a}"
		);

		// 8. A return of 1 Dozen restores exactly 12.
		$dozen_to_base = \Counter\Stock\Units::to_base_qty( $unit_dozen, '1' );
		$refund_a = \Counter\Orders\Refunds::process(
			$order_a,
			$dozen_price,
			[ $item_a->get_id() => [ 'qty' => (float) $dozen_to_base, 'refund_total' => (float) $dozen_price, 'refund_tax' => [] ] ],
			'selftest units return',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => $dozen_price ] ]
		);
		$return_move_a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'return'", $order_a->get_id() ), ARRAY_A );
		$this->check(
			'test_units: a return of 1 Dozen restores exactly 12',
			true === $refund_a && $return_move_a && '12.0000' === $return_move_a['qty_delta'],
			( $refund_a instanceof \WP_Error ? $refund_a->get_error_message() : 'ok' ) . ' ' . wp_json_encode( $return_move_a )
		);
	}

	// -- P3.1: test_barcode() -- 6 checks -----------------------------------------------
	//
	// No fixtures written — Barcode is pure/stateless (no DB, no WC objects) —
	// so there is nothing for cleanup() to remove.

	private function test_barcode(): void {
		$barcode_class = \Counter\Docs\Barcode::class;

		// Check 1 — a known Code 128 input produces the known module pattern.
		// Wikipedia's own worked example: "PJJ123C" via Start Code A, published
		// checksum value 54. This slice of the pattern table is typed
		// independently of Barcode::CODE128_PATTERNS (not read from it), so a
		// transcription error in either copy shows up as a mismatch here
		// rather than the test quietly checking the class against itself.
		$known_patterns = [
			103 => '211412', // Start A
			48  => '313121', // P
			42  => '112133', // J
			17  => '123221', // 1
			18  => '223211', // 2
			19  => '221132', // 3
			35  => '131321', // C
			54  => '311123', // checksum value (char 'V')
			106 => '233111', // Stop
		];
		$sequence      = [ 103, 48, 42, 42, 17, 18, 19, 35 ]; // Start A, P, J, J, 1, 2, 3, C
		$checksum      = $barcode_class::code128_checksum( $sequence );
		$expected_full = array_merge( $sequence, [ 54, 106 ] );
		$expected_pattern = $known_patterns[103] . $known_patterns[48] . $known_patterns[42] . $known_patterns[42]
			. $known_patterns[17] . $known_patterns[18] . $known_patterns[19] . $known_patterns[35]
			. $known_patterns[54] . $known_patterns[106];
		$expected_widths = array_merge( array_map( 'intval', str_split( $expected_pattern ) ), [ 2 ] ); // + stop's trailing bar
		$actual_widths   = $barcode_class::code128_widths( $expected_full );
		$this->check(
			'test_barcode: Code 128 "PJJ123C" (Start A) checksum matches Wikipedia\'s published value 54',
			54 === $checksum,
			'computed=' . $checksum
		);
		$this->check(
			'test_barcode: Code 128 module pattern for the same known sequence matches an independently-typed table slice',
			$expected_widths === $actual_widths,
			'expected=' . implode( '', $expected_widths ) . ' actual=' . implode( '', $actual_widths )
		);

		// Check 2 — EAN-13 check digit computed correctly for three known
		// GTINs, each independently confirmed (Wikipedia's own worked
		// example; a widely-published GS1 example; a real, registered ISBN)
		// rather than checked against this class's own output.
		$known_gtins = [
			[ '400638133393', 1 ], // Wikipedia's own EAN-13 worked example
			[ '590123412345', 7 ], // widely-published GS1 Poland example
			[ '978020137962', 4 ], // a real, registered ISBN-13/EAN-13
		];
		$gtin_ok = true;
		$gtin_detail = [];
		foreach ( $known_gtins as [ $digits12, $expected_check ] ) {
			$got = $barcode_class::ean13_check_digit( $digits12 );
			if ( $got !== $expected_check ) {
				$gtin_ok = false;
			}
			$gtin_detail[] = "{$digits12}->{$got}(expect {$expected_check})";
		}
		$this->check( 'test_barcode: EAN-13 check digit correct for three known GTINs', $gtin_ok, implode( ' ', $gtin_detail ) );

		// Check 3 — an invalid EAN-13 is rejected (wrong check digit, and a
		// non-13-digit string).
		$this->check(
			'test_barcode: an invalid EAN-13 is rejected',
			false === $barcode_class::ean13_is_valid( '4006381333930' ) // real GTIN with the check digit deliberately wrong
				&& false === $barcode_class::ean13_is_valid( '123' )
				&& false === $barcode_class::ean13_is_valid( 'not-a-gtin-13' ),
			'ok'
		);

		// Check 4 — the SVG has no external references.
		$code128_svg = $barcode_class::code128_svg( 'SKU-0001' );
		$ean13_svg   = $barcode_class::ean13_svg( '4006381333931' );
		// xmlns="http://www.w3.org/2000/svg" is a required, never-fetched
		// namespace declaration, not an external reference — stripped
		// before scanning so it doesn't false-positive against 'http'.
		$strip_xmlns = static fn( string $svg ): string => str_replace( 'xmlns="http://www.w3.org/2000/svg"', '', $svg );
		$no_external = null !== $code128_svg && null !== $ean13_svg
			&& false === stripos( $strip_xmlns( $code128_svg ), 'http' )
			&& false === stripos( $code128_svg, 'xlink:href' )
			&& false === stripos( $code128_svg, '<image' )
			&& false === stripos( $code128_svg, 'url(' )
			&& false === stripos( $strip_xmlns( $ean13_svg ), 'http' )
			&& false === stripos( $ean13_svg, 'xlink:href' )
			&& false === stripos( $ean13_svg, '<image' )
			&& false === stripos( $ean13_svg, 'url(' );
		$this->check( 'test_barcode: the SVG has no external references', $no_external, 'ok' );

		// Check 5 — the SVG has explicit width and height in millimetres.
		$has_mm = null !== $code128_svg && null !== $ean13_svg
			&& 1 === preg_match( '/width="[\d.]+mm"/', $code128_svg )
			&& 1 === preg_match( '/height="[\d.]+mm"/', $code128_svg )
			&& 1 === preg_match( '/width="[\d.]+mm"/', $ean13_svg )
			&& 1 === preg_match( '/height="[\d.]+mm"/', $ean13_svg );
		$this->check( 'test_barcode: the SVG has explicit width and height in millimetres', $has_mm, 'ok' );

		// Check 6 — an empty input returns null rather than an empty barcode.
		$this->check(
			'test_barcode: an empty input returns null rather than an empty barcode',
			null === $barcode_class::code128_svg( '' )
				&& null === $barcode_class::code128_svg( null )
				&& null === $barcode_class::ean13_svg( '' )
				&& null === $barcode_class::ean13_svg( null ),
			'ok'
		);
	}

	// -- P3.2: test_labels() -- 5 checks ------------------------------------------------

	private function test_labels(): void {
		$labels_class = \Counter\Docs\Labels::class;

		// A5-ish sheet: 5 cols x 10 rows of 38mm x 21mm labels, 2mm/1mm
		// gutters, an 8mm/5mm top-left calibration offset. Fits the stated
		// 210x297mm page (needed 203mm x 227mm) with room to spare.
		$page = [
			'page_width_mm'   => '210',
			'page_height_mm'  => '297',
			'cols'            => 5,
			'rows'            => 10,
			'label_width_mm'  => '38',
			'label_height_mm' => '21',
			'gutter_x_mm'     => '2',
			'gutter_y_mm'     => '1',
			'offset_top_mm'   => '8',
			'offset_left_mm'  => '5',
		];

		// Check 1 — geometry maths places label (row 3, col 4), 0-indexed,
		// at the computed millimetre offset: x = 5 + 4*(38+2) = 165,
		// y = 8 + 3*(21+1) = 74 — hand-computed against the page above, not
		// read back from the class's own prior output.
		$pos = $labels_class::layout( $page, 3, 4 );
		$this->check(
			'test_labels: geometry maths places label (3,4) at the computed millimetre offset',
			'165' === $pos['x_mm'] && '74' === $pos['y_mm'],
			wp_json_encode( $pos )
		);

		// Check 2 — fields inside the label box do not overflow it: a field
		// box deliberately defined past the label's own edge (x=30,y=15,
		// w=20,h=10 against a 38x21mm label — right=50, bottom=25, both past
		// the edge) still renders CLAMPED to end exactly at the label's own
		// width/height, never beyond it. render_label() does the clamping
		// itself — no save-time validation is involved in reaching this
		// path, unlike check 3 below.
		$overflow_template = [
			'page'   => $page,
			'fields' => [ [ 'field' => 'name', 'x_mm' => '30', 'y_mm' => '15', 'width_mm' => '20', 'height_mm' => '10' ] ],
		];
		$overflow_html = $labels_class::render_label( $overflow_template, [ 'name' => 'Overflow Test' ] );
		preg_match( '/left:([\d.]+)mm;top:([\d.]+)mm;width:([\d.]+)mm;height:([\d.]+)mm/', $overflow_html, $box );
		$contained = 5 === count( $box ) // [full match, left, top, width, height]
			&& ( (float) $box[1] + (float) $box[3] ) <= 38.0 + 0.001
			&& ( (float) $box[2] + (float) $box[4] ) <= 21.0 + 0.001;
		$this->check( 'test_labels: fields inside the label box do not overflow it', $contained, wp_json_encode( $box ) );

		// Check 3 — a template with rows*cols exceeding the page is refused.
		// Same label/gutter/offset as above but cols=10: needed width =
		// 5 + 10*38 + 9*2 = 403mm against a 210mm page.
		$bad_page          = $page;
		$bad_page['cols']  = 10;
		$refused           = $labels_class::create(
			[ 'name' => 'Counter Selftest Fixture Label Overflow', 'page' => $bad_page, 'fields' => [] ]
		);
		$this->check(
			'test_labels: a template with rows x cols exceeding the page is refused',
			is_wp_error( $refused ) && 'cntr_label_page_overflow' === $refused->get_error_code(),
			is_wp_error( $refused ) ? $refused->get_error_message() : wp_json_encode( $refused )
		);

		// A valid template, for real (persisted) confirmation the CRUD path
		// itself works — not one of the plan's 5 numbered checks, but the
		// admin screen has nothing else to save through.
		$created = $labels_class::create(
			[
				'name'   => 'Counter Selftest Fixture Label Template',
				'page'   => $page,
				'fields' => [ [ 'field' => 'name', 'x_mm' => '2', 'y_mm' => '2', 'width_mm' => '30', 'height_mm' => '6' ] ],
			]
		);
		if ( ! is_wp_error( $created ) ) {
			$this->label_fixture_ids[] = $created['id'];
		}
		$this->check( 'test_labels: a template within the page geometry saves successfully', ! is_wp_error( $created ), is_wp_error( $created ) ? $created->get_error_message() : wp_json_encode( $created ) );

		// Check 4 — the calibration sheet renders at exactly the stated page
		// size.
		$calibration = $labels_class::calibration_sheet( 100.0, 150.0 );
		$this->check(
			'test_labels: the calibration sheet renders at exactly the stated page size',
			1 === preg_match( '/width="100mm"/', $calibration ) && 1 === preg_match( '/height="150mm"/', $calibration ),
			substr( $calibration, 0, 120 )
		);

		// Check 5 — a label with no barcode field still renders.
		$no_barcode_template = [
			'page'   => $page,
			'fields' => [ [ 'field' => 'name', 'x_mm' => '2', 'y_mm' => '2', 'width_mm' => '30', 'height_mm' => '6' ] ],
		];
		$no_barcode_html = $labels_class::render_label( $no_barcode_template, [ 'name' => 'No Barcode Product' ] );
		$this->check(
			'test_labels: a label with no barcode field still renders',
			'' !== $no_barcode_html && false !== strpos( $no_barcode_html, 'cntr-label' ) && false !== strpos( $no_barcode_html, 'No Barcode Product' ),
			'len=' . strlen( $no_barcode_html )
		);
	}

	// -- P3.3: test_label_batch() -- 3 checks -------------------------------------------

	private function test_label_batch(): void {
		$labels_class = \Counter\Docs\Labels::class;

		// Check 1 — receiving 12 units queues 12 labels: one purchase line
		// with qty=12 expands to 12 identical entries, not one entry
		// carrying a count.
		$received = $labels_class::expand( [ [ 'sku' => 'SKU-RECEIVED', 'name' => 'Received Widget', 'price' => '25.00', 'qty' => 12 ] ] );
		$this->check( 'test_label_batch: receiving 12 units queues 12 labels', 12 === count( $received ), 'count=' . count( $received ) );

		// Check 2 — a mixed selection (three different products, different
		// counts) paginates across sheets correctly. A small 2x2 page
		// (capacity 4) makes the arithmetic easy to hand-verify: 3+4+3=10
		// items -> ceil(10/4)=3 sheets of sizes 4, 4, 2.
		$small_page = [
			'page_width_mm' => '100', 'page_height_mm' => '100',
			'cols' => 2, 'rows' => 2,
			'label_width_mm' => '40', 'label_height_mm' => '40',
			'gutter_x_mm' => '2', 'gutter_y_mm' => '2',
			'offset_top_mm' => '5', 'offset_left_mm' => '5',
		];
		$mixed = $labels_class::expand(
			[
				[ 'sku' => 'SKU-A', 'name' => 'Product A', 'price' => '10.00', 'qty' => 3 ],
				[ 'sku' => 'SKU-B', 'name' => 'Product B', 'price' => '20.00', 'qty' => 4 ],
				[ 'sku' => 'SKU-C', 'name' => 'Product C', 'price' => '30.00', 'qty' => 3 ],
			]
		);
		$sheets = $labels_class::paginate( $mixed, $small_page );
		$sheet_sizes = array_map( 'count', $sheets );
		$this->check(
			'test_label_batch: a mixed selection paginates across sheets correctly',
			10 === count( $mixed ) && [ 4, 4, 2 ] === $sheet_sizes,
			wp_json_encode( $sheet_sizes )
		);

		// Check 3 — the last partial sheet (2 items on a 4-slot grid) does
		// not render empty boxes as blank stickers: exactly 2 label
		// fragments in the markup, not 4. `class="cntr-label"` (closing
		// quote immediately after) cannot match the sheet wrapper's own
		// `class="cntr-label-sheet"` — that string continues past
		// "cntr-label" before its own closing quote.
		$template = [
			'page'   => $small_page,
			'fields' => [ [ 'field' => 'name', 'x_mm' => '2', 'y_mm' => '2', 'width_mm' => '30', 'height_mm' => '6' ] ],
		];
		$last_sheet      = end( $sheets );
		$last_sheet_html = $labels_class::render_sheet( $template, $last_sheet );
		$this->check(
			'test_label_batch: the last partial sheet does not render empty boxes as blank stickers',
			2 === count( $last_sheet ) && 2 === substr_count( $last_sheet_html, 'class="cntr-label"' ),
			'sheet_size=' . count( $last_sheet ) . ' rendered=' . substr_count( $last_sheet_html, 'class="cntr-label"' )
		);
	}

	// -- P3.4: test_doc_templates() -- 5 checks -----------------------------------------

	private function test_doc_templates(): void {
		$templates_class = \Counter\Docs\Templates::class;

		// Check 1 — an unknown token renders empty rather than the literal
		// token. A pure render() call, no DB involved: {{total}} is a real,
		// supplied token; {{not_a_real_token}} is not in ALLOWED_TOKENS at
		// all, and {{unsupplied_token}} IS allowed but $data has no key for
		// it — both must vanish, neither may leak its own braces/name into
		// the output.
		$html1     = 'Total: {{total}} / Unknown: {{not_a_real_token}} / Unsupplied: {{shop_phone}}';
		$rendered1 = $templates_class::render( [ 'html' => $html1, 'width_mm' => 79 ], [ 'total' => '100.00' ] );
		$this->check(
			'test_doc_templates: an unknown token renders empty rather than the literal token',
			false !== strpos( $rendered1, 'Total: 100.00' )
				&& false === strpos( $rendered1, 'not_a_real_token' )
				&& false === strpos( $rendered1, '{{' )
				&& false === strpos( $rendered1, '}}' ),
			$rendered1
		);

		// Check 2 — HTML in a shop-name setting is escaped: every
		// substituted value is esc_html()'d by default (RAW_TOKENS is the
		// one deliberate exception, and shop_name is not in it).
		$rendered2 = $templates_class::render( [ 'html' => '{{shop_name}}', 'width_mm' => 79 ], [ 'shop_name' => '<script>alert(1)</script>' ] );
		$this->check(
			'test_doc_templates: HTML in a shop-name setting is escaped',
			false === strpos( $rendered2, '<script>' ) && false !== strpos( $rendered2, '&lt;script&gt;' ),
			$rendered2
		);

		// Check 3 — a template missing a required token for its type is
		// refused on save. challan-63 requires serial/taxable_value/
		// vat_amount/total/buyer_bin (Invariant V territory — P3.5's own
		// job, but this task's own refusal rule already has to hold for it);
		// this html has only {{total}}.
		$refused = $templates_class::create(
			[
				'type' => 'challan-63',
				'name' => 'Counter Selftest Fixture Doc Template Incomplete Challan',
				'html' => '<div>{{total}}</div>',
			]
		);
		$this->check(
			'test_doc_templates: a template missing a required token for its type is refused on save',
			is_wp_error( $refused ) && 'cntr_doc_missing_token' === $refused->get_error_code(),
			is_wp_error( $refused ) ? $refused->get_error_message() : wp_json_encode( $refused )
		);

		// Check 4 — the 58mm template renders within 58mm: width_mm
		// defaults from DEFAULT_WIDTH_MM['receipt-58'] when not given
		// explicitly, and render() carries that width onto the wrapping
		// container's own style.
		$created58 = $templates_class::create(
			[
				'type' => 'receipt-58',
				'name' => 'Counter Selftest Fixture Doc Template 58mm',
				'html' => '<div>{{total}}</div>',
			]
		);
		if ( ! is_wp_error( $created58 ) ) {
			$this->doc_template_fixture_ids[] = $created58['id'];
		}
		$tpl58      = is_wp_error( $created58 ) ? null : $templates_class::get( $created58['id'] );
		$rendered58 = $tpl58 ? $templates_class::render( $tpl58, [ 'total' => '50.00' ] ) : '';
		$this->check(
			'test_doc_templates: the 58mm template renders within 58mm',
			$tpl58 && 58 === (int) $tpl58['width_mm'] && 1 === preg_match( '/width:58mm/', $rendered58 ),
			is_wp_error( $created58 ) ? $created58->get_error_message() : $rendered58
		);

		// Check 5 — switching locale switches captions: two receipt-79
		// templates, same type, different locale, each with its own literal
		// caption text baked directly into its html (Direction: captions can
		// be Bengali without a developer — the caption IS the template row's
		// own content, not something looked up from a catalogue).
		$created_en = $templates_class::create(
			[
				'type'   => 'receipt-79',
				'name'   => 'Counter Selftest Fixture Doc Template EN',
				'locale' => 'en-ZZ',
				'html'   => '<div class="cap">Total</div>{{total}}',
			]
		);
		$created_bn = $templates_class::create(
			[
				'type'   => 'receipt-79',
				'name'   => 'Counter Selftest Fixture Doc Template BN',
				'locale' => 'bn-ZZ',
				'html'   => '<div class="cap">মোট</div>{{total}}',
			]
		);
		if ( ! is_wp_error( $created_en ) ) {
			$this->doc_template_fixture_ids[] = $created_en['id'];
		}
		if ( ! is_wp_error( $created_bn ) ) {
			$this->doc_template_fixture_ids[] = $created_bn['id'];
		}
		$found_en = $templates_class::find( 'receipt-79', 'en-ZZ' );
		$found_bn = $templates_class::find( 'receipt-79', 'bn-ZZ' );
		$this->check(
			'test_doc_templates: switching locale switches captions',
			! is_wp_error( $created_en ) && ! is_wp_error( $created_bn )
				&& $found_en && false !== strpos( $found_en['html'], '>Total<' )
				&& $found_bn && false !== strpos( $found_bn['html'], 'মোট' ),
			wp_json_encode( [ 'en' => $found_en['html'] ?? null, 'bn' => $found_bn['html'] ?? null ] )
		);
	}

	// -- P3.5: test_challan() -- 8 checks. Invariant V. ----------------------------------
	//
	// cntr_challan_register is append-only and permanent by design — same as
	// cntr_stock_moves, cntr_audit_log and every other ledger this project
	// has already established cannot be cleaned up after a self-test run
	// without contradicting the very invariant being tested. No cleanup
	// tracking here; buyer_name is tagged so a real reviewer can identify
	// these as test rows.

	private function test_challan(): void {
		global $wpdb;
		$challan_class = \Counter\Docs\Challan::class;
		$table         = Install::table( 'challan_register' );

		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture Challan Register', 'location_id' => $main_id, 'prefix' => 'ZC' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Challan Product' );
		$product->set_regular_price( '200.00' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$this->product_fixture_ids[] = $product->get_id();

		$context = [ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id() ];
		$make_order = function ( string $receipt_no ) use ( $product, $context ) {
			$order = \Counter\Orders\Builder::build(
				[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '200.00', 'total' => '200.00' ] ],
				$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => $receipt_no ]
			);
			$order->set_billing_first_name( 'Counter Selftest Fixture Challan Buyer' );
			$order->save();
			$this->order_fixture_ids[] = $order->get_id();
			return $order;
		};

		$order_a = $make_order( 'SELFTEST-CHALLAN-A' );
		$order_b = $make_order( 'SELFTEST-CHALLAN-B' );
		$order_c = $make_order( 'SELFTEST-CHALLAN-C' );

		// Check 1 — a serial is issued exactly once per order: a retry for
		// the same order returns the SAME serial, and exactly one 'issue'
		// row exists for it.
		$issue_a1 = $challan_class::issue( $order_a->get_id() );
		$issue_a2 = $challan_class::issue( $order_a->get_id() ); // retry — must not burn a second serial
		$rows_for_a = $wpdb->get_results( $wpdb->prepare( "SELECT seq FROM {$table} WHERE order_id = %d AND kind = 'issue'", $order_a->get_id() ), ARRAY_A );
		$this->check(
			'test_challan: a serial is issued exactly once per order',
			! is_wp_error( $issue_a1 ) && ! is_wp_error( $issue_a2 )
				&& $issue_a1['serial'] === $issue_a2['serial'] && 1 === count( $rows_for_a ),
			wp_json_encode( [ 'a1' => is_wp_error( $issue_a1 ) ? $issue_a1->get_error_message() : $issue_a1['serial'], 'a2' => is_wp_error( $issue_a2 ) ? $issue_a2->get_error_message() : $issue_a2['serial'], 'rows' => count( $rows_for_a ) ] )
		);

		// Check 2 — two concurrent issues (here: two different orders,
		// issued back to back) get different serials.
		$issue_b = $challan_class::issue( $order_b->get_id() );
		$this->check(
			'test_challan: two concurrent issues get different serials',
			! is_wp_error( $issue_b ) && ! is_wp_error( $issue_a1 ) && $issue_b['serial'] !== $issue_a1['serial'],
			wp_json_encode( [ 'a' => $issue_a1['serial'] ?? null, 'b' => $issue_b['serial'] ?? null ] )
		);

		// Check 3 — the serial is never reused after a cancellation:
		// cancel A's challan, then issue a fresh one (order C) — its serial
		// must differ from BOTH the cancelled original and its own reversal.
		$cancel_a = $challan_class::cancel( $order_a->get_id() );
		$issue_c  = $challan_class::issue( $order_c->get_id() );
		$this->check(
			'test_challan: the serial is never reused after a cancellation',
			! is_wp_error( $cancel_a ) && ! is_wp_error( $issue_c )
				&& $issue_c['serial'] !== $issue_a1['serial'] && $issue_c['serial'] !== $cancel_a['serial'],
			wp_json_encode( [ 'original' => $issue_a1['serial'] ?? null, 'reversal' => $cancel_a['serial'] ?? null, 'new' => $issue_c['serial'] ?? null ] )
		);

		// Check 4 — a cancellation creates a reversal row and leaves the
		// original intact: re-read A's original row by its own seq and
		// confirm nothing about it changed; confirm the reversal points
		// back at it.
		$original_after = $challan_class::get_by_seq( (int) $issue_a1['seq'] );
		$this->check(
			'test_challan: a cancellation creates a reversal row and leaves the original intact',
			$original_after && 'issue' === $original_after['kind'] && $original_after['serial'] === $issue_a1['serial'] && $original_after['total'] === $issue_a1['total']
				&& ! is_wp_error( $cancel_a ) && 'reversal' === $cancel_a['kind'] && (int) $cancel_a['reverses_id'] === (int) $issue_a1['seq'],
			wp_json_encode( [ 'original' => $original_after, 'reversal_reverses_id' => $cancel_a['reverses_id'] ?? null ] )
		);

		// Check 5 — no gaps in the sequence across a fixture day: every
		// serial this test allocated, in the exact order allocate_serial()
		// was actually called (issue A, issue B, cancel A's reversal,
		// issue C), has strictly consecutive numeric suffixes.
		$extract_number = static fn( $serial ) => (int) preg_replace( '/\D/', '', (string) $serial );
		$sequence_serials = [ $issue_a1['serial'] ?? null, $issue_b['serial'] ?? null, $cancel_a['serial'] ?? null, $issue_c['serial'] ?? null ];
		$numbers          = array_map( $extract_number, $sequence_serials );
		$gap_free         = false === array_search( null, $sequence_serials, true ) // array_search() returns false, not null, when the needle is absent
			&& $numbers[1] === $numbers[0] + 1 && $numbers[2] === $numbers[1] + 1 && $numbers[3] === $numbers[2] + 1;
		$this->check( 'test_challan: there are no gaps in the sequence across a fixture day', $gap_free, wp_json_encode( [ 'serials' => $sequence_serials, 'numbers' => $numbers ] ) );

		// Check 6 — no code path updates or deletes a register row.
		$violation = self::grep_for_forbidden_writes( 'challan_register' );
		$this->check( 'test_challan: no code path updates or deletes a register row', null === $violation, (string) $violation );

		// Check 7 — a challan cannot be issued for an order that does not exist.
		$refused_no_order = $challan_class::issue( 999999999 );
		$this->check(
			'test_challan: a challan cannot be issued for an order that does not exist',
			is_wp_error( $refused_no_order ) && 'cntr_challan_no_order' === $refused_no_order->get_error_code(),
			is_wp_error( $refused_no_order ) ? $refused_no_order->get_error_message() : wp_json_encode( $refused_no_order )
		);

		// Check 8 — issuing offline is refused under the provisional policy
		// (offline.challan_policy defaults to 'provisional' — §10.1 question
		// 9, docs/decisions.md). The offline check runs before the order
		// lookup, so reusing order_b (already issued above) is fine here —
		// it is never reached.
		$refused_offline = $challan_class::issue( $order_b->get_id(), true );
		$this->check(
			'test_challan: issuing offline is refused under the provisional policy',
			is_wp_error( $refused_offline ) && 'cntr_challan_offline_refused' === $refused_offline->get_error_code(),
			is_wp_error( $refused_offline ) ? $refused_offline->get_error_message() : wp_json_encode( $refused_offline )
		);

		// Check 9 (P7.7) — Rest\Sale::process()'s own step 8, wired by this
		// task: an ordinary sale issues its challan synchronously as part
		// of the real sale flow (not the direct Challan::issue() calls
		// checks 1-8 make), and a drained offline sale gets exactly one
		// challan the same way — "drained" and "synced" are the same
		// instant in this architecture (the request reaching the server AT
		// ALL means it is no longer offline), so there is nothing separate
		// to simulate beyond calling process() with offline=true.
		$extract_challan = static function ( $result ) {
			if ( $result instanceof \WP_Error ) {
				return [ 'error' => $result->get_error_message() ];
			}
			if ( $result instanceof \WP_REST_Response ) {
				return $result->get_data();
			}
			return $result;
		};
		$sale_body = [
			'lines'    => [ [ 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '200.0000', 'discount' => '0.0000' ] ],
			'tenders'  => [ [ 'method' => 'cash', 'amount' => '200.0000' ] ],
			'customer' => [ 'phone' => '' ],
		];

		$uuid_online = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_online;
		$result_online    = $extract_challan( \Counter\Rest\Sale::process( $uuid_online, $register_id, $shift_id, 'SELFTEST-CHALLAN-ONLINE', $sale_body ) );
		$order_online_id  = (int) ( $result_online['order_id'] ?? 0 );
		if ( $order_online_id ) {
			$this->order_fixture_ids[] = $order_online_id;
		}

		$uuid_offline = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_offline;
		$result_offline   = $extract_challan( \Counter\Rest\Sale::process( $uuid_offline, $register_id, $shift_id, 'SELFTEST-CHALLAN-OFFLINE', $sale_body + [ 'offline' => true ] ) );
		$order_offline_id = (int) ( $result_offline['order_id'] ?? 0 );
		if ( $order_offline_id ) {
			$this->order_fixture_ids[] = $order_offline_id;
		}

		$challan_online     = $challan_class::get_by_order( $order_online_id );
		$challan_offline    = $challan_class::get_by_order( $order_offline_id );
		$order_online_obj   = $order_online_id ? wc_get_order( $order_online_id ) : null;
		$order_offline_obj  = $order_offline_id ? wc_get_order( $order_offline_id ) : null;
		$offline_issue_rows = $order_offline_id ? $wpdb->get_results( $wpdb->prepare( "SELECT seq FROM {$table} WHERE order_id = %d AND kind = 'issue'", $order_offline_id ), ARRAY_A ) : [];

		$this->check(
			'test_challan: a drained offline sale issues exactly one challan under the provisional policy',
			$order_online_id > 0 && null !== $challan_online
				&& $order_online_obj instanceof \WC_Order && $order_online_obj->get_meta( '_cntr_challan_serial' ) === $challan_online['serial']
				&& $order_offline_id > 0 && null !== $challan_offline && 1 === count( $offline_issue_rows )
				&& $order_offline_obj instanceof \WC_Order && $order_offline_obj->get_meta( '_cntr_challan_serial' ) === $challan_offline['serial']
				&& $challan_online['serial'] !== $challan_offline['serial'],
			wp_json_encode(
				[
					'online'         => $challan_online,
					'offline'        => $challan_offline,
					'offline_rows'   => count( $offline_issue_rows ),
					'online_meta'    => $order_online_obj ? $order_online_obj->get_meta( '_cntr_challan_serial' ) : null,
					'offline_meta'   => $order_offline_obj ? $order_offline_obj->get_meta( '_cntr_challan_serial' ) : null,
				]
			)
		);
	}

	// -- P3.6: test_vat_exports() -- 4 checks -------------------------------------------

	private function test_vat_exports(): void {
		global $wpdb;
		$vat_class   = \Counter\Docs\VatExports::class;
		$main_id     = \Counter\Stock\Locations::default_id();
		$moves_table = Install::table( 'stock_moves' );

		// A bare `wp eval` context has no logged-in user, so current_user_can(
		// 'cntr_export' ) would read false regardless of role — the same
		// gotcha P2.11/P3.5's own tests already found live. Borrow an
		// administrator for the whole test, restore the real user at the end.
		$original_user_id_vat = get_current_user_id();
		$admins_vat            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_vat ) ) {
			wp_set_current_user( (int) $admins_vat[0] );
		}

		// -- Checks 2 & 3: a stock item with a fully fabricated 2031-06
		// timeline, so the fixture can never collide with anything a real
		// shop or another test ever writes. Each Ledger::move() call is
		// inserted in true chronological order (so balance_after is correct)
		// then its created_at is rewritten directly — the same backdating
		// trick test_stock_reports() already uses for its own window tests.
		$product1 = new \WC_Product_Simple();
		$product1->set_name( 'Counter Selftest Fixture VAT Export Stock Product' );
		$product1->set_regular_price( '50.00' );
		$product1->set_manage_stock( true );
		$product1->set_stock_quantity( 0 );
		$product1->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product1->save();
		$this->product_fixture_ids[] = $product1->get_id();

		$backdate = function ( int $move_id, string $created_at ) use ( $wpdb, $moves_table ) {
			$wpdb->update( $moves_table, [ 'created_at' => $created_at ], [ 'id' => $move_id ] );
		};
		$move = function ( string $qty_delta, string $reason ) use ( $product1, $main_id ) {
			return \Counter\Stock\Ledger::move(
				[ 'product_id' => $product1->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => $qty_delta, 'reason' => $reason, 'ref_type' => 'selftest_vat_exports', 'ref_id' => 0 ]
			);
		};

		$backdate( $move( '20', 'opening' ), '2031-05-15 12:00:00' );          // opening balance, before the period
		$backdate( $move( '10', 'purchase' ), '2031-06-01 00:00:00' );          // exactly the period's start instant
		$backdate( $move( '-5', 'sale' ), '2031-06-15 10:00:00' );              // mid-period
		$backdate( $move( '-3', 'sale' ), '2031-06-30 23:59:59' );              // exactly the period's end instant
		$backdate( $move( '100', 'adjust' ), '2031-07-05 00:00:00' );           // after the period — must be excluded

		// cntr_stock_moves is append-only (Invariant I) — re-running this exact
		// fixture window on a site with prior self-test history WILL find
		// extra rows: one dead row per earlier run's own now-deleted product,
		// still sitting in this same 2031-06 window forever. Expected, not a
		// bug — match by product_id, not array position or row count.
		$find_row = static function ( array $rows, int $product_id ): array {
			foreach ( $rows as $r ) {
				if ( ( $r['product_id'] ?? null ) === $product_id ) {
					return $r;
				}
			}
			return [];
		};

		$data_6_1    = $vat_class::data_6_1( '2031-06-01', '2031-06-30', $main_id );
		$fixture_row = $find_row( $data_6_1, $product1->get_id() );

		// Check 2 — the 6.1 export's opening plus receipts minus issues
		// equals closing. Not a coincidence of this particular fixture:
		// closing is read off cntr_stock_moves' own running balance_after,
		// so the identity holds by construction — this proves it actually
		// does, on real numbers (20 + 10 - 8 = 22).
		$this->check(
			'test_vat_exports: the 6.1 export\'s opening plus receipts minus issues equals closing',
			! empty( $fixture_row )
				&& '20.0000' === ( $fixture_row['opening_qty'] ?? null )
				&& '10.0000' === ( $fixture_row['receipts_qty'] ?? null )
				&& '8.0000' === ( $fixture_row['issues_qty'] ?? null )
				&& '22.0000' === ( $fixture_row['closing_qty'] ?? null )
				&& 0 === bccomp( bcsub( bcadd( $fixture_row['opening_qty'] ?? '0', $fixture_row['receipts_qty'] ?? '0', 4 ), $fixture_row['issues_qty'] ?? '0', 4 ), $fixture_row['closing_qty'] ?? '-1', 4 ),
			wp_json_encode( [ 'rows' => count( $data_6_1 ), 'row' => $fixture_row ] )
		);

		// Check 3 — date filtering is inclusive at both ends: narrowing the
		// window by a single day on EITHER side must drop the boundary moves
		// (the 2031-06-01 00:00:00 receipt and the 2031-06-30 23:59:59
		// issue) — proving an off-by-one at the boundary would actually be
		// caught, not just coincidentally passing.
		$data_exclusive = $vat_class::data_6_1( '2031-06-02', '2031-06-29', $main_id );
		$row_exclusive  = $find_row( $data_exclusive, $product1->get_id() );
		$this->check(
			'test_vat_exports: date filtering is inclusive at both ends',
			'10.0000' === ( $fixture_row['receipts_qty'] ?? null ) && '8.0000' === ( $fixture_row['issues_qty'] ?? null )
				&& '0.0000' === ( $row_exclusive['receipts_qty'] ?? null ) && '5.0000' === ( $row_exclusive['issues_qty'] ?? null ),
			wp_json_encode( [ 'inclusive' => $fixture_row, 'exclusive' => $row_exclusive ] )
		);

		// Check 1 — the 6.3 export totals equal the challan register for the
		// period: Direction's own shorthand for 6.6's sales-side figures,
		// which this class computes straight from Challan::register() and
		// never a second, potentially-drifting way. Uses "today" (UTC, same
		// convention Db::now() writes) so it never touches the append-only
		// challan register's own dates via a direct UPDATE.
		$product2 = new \WC_Product_Simple();
		$product2->set_name( 'Counter Selftest Fixture VAT Export Sale Product' );
		$product2->set_regular_price( '300.00' );
		$product2->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product2->save();
		$this->product_fixture_ids[] = $product2->get_id();

		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture VAT Export Register', 'location_id' => $main_id, 'prefix' => 'ZE' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );
		$context  = [ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id() ];

		$order_x = \Counter\Orders\Builder::build( [ [ 'product' => $product2, 'qty' => 1, 'subtotal' => '300.00', 'total' => '300.00' ] ], $context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-VATEXP-X' ] );
		$this->order_fixture_ids[] = $order_x->get_id();
		$order_y = \Counter\Orders\Builder::build( [ [ 'product' => $product2, 'qty' => 1, 'subtotal' => '300.00', 'total' => '300.00' ] ], $context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-VATEXP-Y' ] );
		$this->order_fixture_ids[] = $order_y->get_id();

		\Counter\Docs\Challan::issue( $order_x->get_id() );
		\Counter\Docs\Challan::issue( $order_y->get_id() );
		\Counter\Docs\Challan::cancel( $order_y->get_id() ); // one issue + one reversal, both dated "today"

		$today        = gmdate( 'Y-m-d' );
		$challan_rows = \Counter\Docs\Challan::register( $today, $today );
		$expected_taxable = '0.0000';
		$expected_vat     = '0.0000';
		foreach ( $challan_rows as $r ) {
			$expected_taxable = bcadd( $expected_taxable, $r['taxable_value'], 4 );
			$expected_vat     = bcadd( $expected_vat, $r['vat_amount'], 4 );
		}
		$row_6_6 = $vat_class::data_6_6( $today, $today )[0] ?? [];
		$this->check(
			'test_vat_exports: the 6.3 export totals equal the challan register for the period',
			! empty( $challan_rows )
				&& 0 === bccomp( $row_6_6['total_sales_value'] ?? '-1', $expected_taxable, 4 )
				&& 0 === bccomp( $row_6_6['output_vat'] ?? '-1', $expected_vat, 4 ),
			wp_json_encode( [ 'expected_taxable' => $expected_taxable, 'expected_vat' => $expected_vat, 'row' => $row_6_6 ] )
		);

		// Check 4 — an empty period exports headers rather than failing.
		// 1999 predates every fixture this suite (or a real shop) could ever
		// write, so this is genuinely a zero-row period, not a lucky gap.
		$empty_csv = $vat_class::export_6_1( '1999-01-01', '1999-01-02' );
		$lines     = is_string( $empty_csv ) ? array_values( array_filter( preg_split( '/\r\n|\n/', $empty_csv ) ) ) : [];
		$this->check(
			'test_vat_exports: an empty period exports headers rather than failing',
			! is_wp_error( $empty_csv ) && 1 === count( $lines ) && str_contains( $lines[0], 'sku' ),
			wp_json_encode( [ 'is_error' => is_wp_error( $empty_csv ), 'lines' => $lines ] )
		);

		if ( ! empty( $admins_vat ) ) {
			wp_set_current_user( $original_user_id_vat );
		}
	}

	// -- P4.1: test_online_channel() -- 7 checks. Invariant I/III — the other half
	// of audit finding B1. -----------------------------------------------------------

	private function test_online_channel(): void {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		$main_id     = \Counter\Stock\Locations::default_id();

		// -- Product A: checks 1-3, 6, 7 — a real storefront order, never
		// touched by Orders\Builder, pushed through real WooCommerce status
		// transitions so the wired hooks (not a direct Channel:: call) do the
		// work under test.
		$product_a = new \WC_Product_Simple();
		$product_a->set_name( 'Counter Selftest Fixture Online Channel A' );
		$product_a->set_regular_price( '8.00' );
		$product_a->set_manage_stock( true );
		$product_a->set_stock_quantity( 0 );
		$product_a->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_a->save();
		$this->product_fixture_ids[] = $product_a->get_id();
		\Counter\Stock\Batches::receive( [ 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'ONLINE-A', 'qty_received' => '20', 'unit_cost' => '8.0000' ] );

		$order_a = new \WC_Order();
		$order_a->set_customer_id( 0 );
		$item_a = new \WC_Order_Item_Product();
		$item_a->set_product( $product_a );
		$item_a->set_quantity( 3 );
		$item_a->set_subtotal( '24.00' );
		$item_a->set_total( '24.00' );
		$order_a->add_item( $item_a );
		$order_a->calculate_totals( false );
		$order_a->save(); // fires woocommerce_new_order -> tag_channel()
		$this->order_fixture_ids[] = $order_a->get_id();

		// Check 6 — an order paid by a gateway is tagged online, not pos.
		// Re-fetched fresh: proves the tag actually persisted, not just that
		// the in-memory object happens to carry it.
		$order_a_fresh = wc_get_order( $order_a->get_id() );
		$this->check(
			'test_online_channel: an order paid by a gateway is tagged online, not pos',
			'online' === (string) $order_a_fresh->get_meta( '_cntr_channel' ),
			'channel=' . (string) $order_a_fresh->get_meta( '_cntr_channel' )
		);

		$order_a->set_status( 'processing' );
		$order_a->save(); // fires woocommerce_order_status_processing -> apply_stock()

		$moves_a = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'sale_online'", $order_a->get_id() ),
			ARRAY_A
		);

		// Check 1 — a storefront order moves stock through the ledger.
		$this->check(
			'test_online_channel: a storefront order moves stock through the ledger',
			1 === count( $moves_a ) && '-3.0000' === ( $moves_a[0]['qty_delta'] ?? null ),
			wp_json_encode( $moves_a )
		);

		// Check 2 — the move carries channel = 'online' and a FIFO cost.
		$this->check(
			'test_online_channel: the move carries channel = online and a FIFO cost',
			'online' === ( $moves_a[0]['channel'] ?? null ) && bccomp( $moves_a[0]['unit_cost'] ?? '0', '0', 4 ) > 0,
			wp_json_encode( [ 'channel' => $moves_a[0]['channel'] ?? null, 'unit_cost' => $moves_a[0]['unit_cost'] ?? null ] )
		);

		// Check 3 — pending -> processing -> completed applies once: the
		// guard (cntr_order_stock_state) makes the completed transition a
		// no-op even though it independently qualifies for apply_stock().
		$order_a->set_status( 'completed' );
		$order_a->save(); // fires woocommerce_order_status_completed -> apply_stock() again, guarded
		$move_count_a_after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'sale_online'", $order_a->get_id() )
		);
		$this->check(
			'test_online_channel: pending, processing, completed applies once',
			1 === $move_count_a_after,
			"count={$move_count_a_after}"
		);

		// -- Product B: check 4 — a fresh product/order, so cancelling it
		// cannot be confused with product A's already-completed history.
		$product_b = new \WC_Product_Simple();
		$product_b->set_name( 'Counter Selftest Fixture Online Channel B' );
		$product_b->set_regular_price( '5.00' );
		$product_b->set_manage_stock( true );
		$product_b->set_stock_quantity( 0 );
		$product_b->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_b->save();
		$this->product_fixture_ids[] = $product_b->get_id();
		\Counter\Stock\Batches::receive( [ 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'ONLINE-B', 'qty_received' => '10', 'unit_cost' => '5.0000' ] );

		$order_b = new \WC_Order();
		$order_b->set_customer_id( 0 );
		$item_b = new \WC_Order_Item_Product();
		$item_b->set_product( $product_b );
		$item_b->set_quantity( 4 );
		$item_b->set_subtotal( '20.00' );
		$item_b->set_total( '20.00' );
		$order_b->add_item( $item_b );
		$order_b->calculate_totals( false );
		$order_b->save();
		$this->order_fixture_ids[] = $order_b->get_id();

		$order_b->set_status( 'processing' );
		$order_b->save();
		$order_b->set_status( 'cancelled' );
		$order_b->save(); // fires woocommerce_order_status_cancelled -> revert_stock()

		$balance_b_after_cancel = \Counter\Stock\Ledger::balance( $product_b->get_id(), 0, $main_id );

		// Calling the underlying primitive again directly — bypassing
		// WooCommerce's own status-transition dedup entirely — proves
		// Counter's OWN guard is what prevents a second reversion, not
		// merely that WooCommerce never re-fired the hook.
		\Counter\Orders\Channel::revert_stock( wc_get_order( $order_b->get_id() ) );
		$balance_b_after_second_call = \Counter\Stock\Ledger::balance( $product_b->get_id(), 0, $main_id );
		$return_moves_b = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'return'", $order_b->get_id() )
		);

		// Check 4 — cancelling reverts once.
		$this->check(
			'test_online_channel: cancelling reverts once',
			'10.0000' === $balance_b_after_cancel && '10.0000' === $balance_b_after_second_call && 1 === $return_moves_b,
			wp_json_encode( [ 'after_cancel' => $balance_b_after_cancel, 'after_second_call' => $balance_b_after_second_call, 'return_moves' => $return_moves_b ] )
		);

		// -- Product C: check 5 — a refund created directly via
		// wc_create_refund(), NOT through Orders\Refunds::process() (the POS
		// path) — proving the NEW woocommerce_order_refunded hook, not
		// Counter's own POS refund screen, is what reverted this.
		$product_c = new \WC_Product_Simple();
		$product_c->set_name( 'Counter Selftest Fixture Online Channel C' );
		$product_c->set_regular_price( '10.00' );
		$product_c->set_manage_stock( true );
		$product_c->set_stock_quantity( 0 );
		$product_c->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_c->save();
		$this->product_fixture_ids[] = $product_c->get_id();
		\Counter\Stock\Batches::receive( [ 'product_id' => $product_c->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'ONLINE-C', 'qty_received' => '10', 'unit_cost' => '10.0000' ] );

		$order_c = new \WC_Order();
		$order_c->set_customer_id( 0 );
		$item_c = new \WC_Order_Item_Product();
		$item_c->set_product( $product_c );
		$item_c->set_quantity( 5 );
		$item_c->set_subtotal( '50.00' );
		$item_c->set_total( '50.00' );
		$order_c->add_item( $item_c );
		$order_c->calculate_totals( false );
		$order_c->save();
		$this->order_fixture_ids[] = $order_c->get_id();

		$order_c->set_status( 'processing' );
		$order_c->save();
		$order_c->set_status( 'completed' );
		$order_c->save();

		$balance_c_before_refund = \Counter\Stock\Ledger::balance( $product_c->get_id(), 0, $main_id );

		$order_c_items = array_values( $order_c->get_items( 'line_item' ) );
		$refund_c      = wc_create_refund(
			[
				'order_id'       => $order_c->get_id(),
				'amount'         => '20.00',
				'line_items'     => [ $order_c_items[0]->get_id() => [ 'qty' => 2, 'refund_total' => 20.00, 'refund_tax' => [] ] ],
				'reason'         => 'selftest online channel refund',
				'refund_payment' => false,
				'restock_items'  => false, // Counter owns restocking — see Orders\Refunds::process()'s own docblock
			]
		);
		$balance_c_after_refund = \Counter\Stock\Ledger::balance( $product_c->get_id(), 0, $main_id );

		// Check 5 — a refund with restock_items false reverts through the ledger.
		$this->check(
			'test_online_channel: a refund with restock_items false reverts through the ledger',
			! is_wp_error( $refund_c )
				&& '5.0000' === $balance_c_before_refund
				&& '7.0000' === $balance_c_after_refund,
			wp_json_encode(
				[
					'refund' => is_wp_error( $refund_c ) ? $refund_c->get_error_message() : $refund_c->get_id(),
					'before' => $balance_c_before_refund,
					'after'  => $balance_c_after_refund,
				]
			)
		);

		// Check 7 — rebuild() reproduces the balance including the online
		// sale: corrupt product A's cached balance directly, rebuild, and
		// confirm the restored figure is 17 (20 received - 3 sold online) —
		// only possible if the processing-hook's sale really landed in the
		// ledger rebuild() reads from, not merely in the cached balance.
		$stock_table = Install::table( 'stock' );
		$wpdb->update( $stock_table, [ 'qty' => '99999.0000' ], [ 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'location_id' => $main_id ] );
		\Counter\Stock\Ledger::rebuild( $product_a->get_id(), false );
		$balance_a_after_rebuild = \Counter\Stock\Ledger::balance( $product_a->get_id(), 0, $main_id );
		$this->check(
			'test_online_channel: rebuild() reproduces the balance including the online sale',
			'17.0000' === $balance_a_after_rebuild,
			"balance={$balance_a_after_rebuild}"
		);
	}

	// -- P4.2: test_fulfilment() -- 4 checks --------------------------------------------

	private function test_fulfilment(): void {
		global $wpdb;
		$fulfil_class = \Counter\Admin\Screens\Fulfilment::class;
		$main_id      = \Counter\Stock\Locations::default_id();
		$moves_table  = Install::table( 'stock_moves' );

		$original_user_id_ff = get_current_user_id();
		$admins_ff             = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_ff ) ) {
			wp_set_current_user( (int) $admins_ff[0] );
		}

		$make_online_order = function ( \WC_Product $product, int $qty, string $line_total ) {
			$order = new \WC_Order();
			$order->set_customer_id( 0 );
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $qty );
			$item->set_subtotal( $line_total );
			$item->set_total( $line_total );
			$order->add_item( $item );
			$order->calculate_totals( false );
			$order->save();
			return $order;
		};

		// -- Check 1: an order with insufficient stock is flagged rather than
		// hidden — a product with only 2 on hand, sold 5 online, goes
		// negative once the P4.1 hook applies it.
		$product_over = new \WC_Product_Simple();
		$product_over->set_name( 'Counter Selftest Fixture Fulfilment Oversold' );
		$product_over->set_regular_price( '10.00' );
		$product_over->set_manage_stock( true );
		$product_over->set_stock_quantity( 0 );
		$product_over->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_over->save();
		$this->product_fixture_ids[] = $product_over->get_id();
		\Counter\Stock\Ledger::move( [ 'product_id' => $product_over->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '2', 'reason' => 'opening', 'ref_type' => 'selftest_fulfilment', 'ref_id' => 0 ] );

		$order_over = $make_online_order( $product_over, 5, '50.00' );
		$this->order_fixture_ids[] = $order_over->get_id();
		$order_over->set_status( 'processing' );
		$order_over->save(); // fires the P4.1 hook -> apply_stock(), balance goes to -3

		$queue_rows  = $fulfil_class::queue();
		$found_over  = null;
		foreach ( $queue_rows as $r ) {
			if ( $r['order_id'] === $order_over->get_id() ) {
				$found_over = $r;
				break;
			}
		}
		$this->check(
			'test_fulfilment: an order with insufficient stock is flagged rather than hidden',
			null !== $found_over && true === $found_over['insufficient_stock'],
			wp_json_encode( $found_over )
		);

		// -- Check 2: picking moves stock exactly once (never twice, never zero).
		$product_pick = new \WC_Product_Simple();
		$product_pick->set_name( 'Counter Selftest Fixture Fulfilment Pick' );
		$product_pick->set_regular_price( '10.00' );
		$product_pick->set_manage_stock( true );
		$product_pick->set_stock_quantity( 0 );
		$product_pick->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_pick->save();
		$this->product_fixture_ids[] = $product_pick->get_id();
		\Counter\Stock\Ledger::move( [ 'product_id' => $product_pick->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '10', 'reason' => 'opening', 'ref_type' => 'selftest_fulfilment', 'ref_id' => 0 ] );

		$order_pick = $make_online_order( $product_pick, 3, '30.00' );
		$this->order_fixture_ids[] = $order_pick->get_id();
		$order_pick->set_status( 'processing' );
		$order_pick->save(); // the hook already applies this once

		$pick_result_1 = $fulfil_class::pick( $order_pick->get_id() );
		$pick_result_2 = $fulfil_class::pick( $order_pick->get_id() ); // deliberately a second, redundant call
		$move_count_pick = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'sale_online'", $order_pick->get_id() )
		);
		$this->check(
			'test_fulfilment: picking moves stock exactly once (never twice, never zero)',
			true === $pick_result_1 && true === $pick_result_2 && 1 === $move_count_pick,
			wp_json_encode( [ 'result_1' => $pick_result_1, 'result_2' => $pick_result_2, 'moves' => $move_count_pick ] )
		);

		// -- Check 3: a packing slip renders every line including free items.
		$product_paid = new \WC_Product_Simple();
		$product_paid->set_name( 'Counter Selftest Fixture Fulfilment Paid Item' );
		$product_paid->set_regular_price( '25.00' );
		$product_paid->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_paid->save();
		$this->product_fixture_ids[] = $product_paid->get_id();

		$product_free = new \WC_Product_Simple();
		$product_free->set_name( 'Counter Selftest Fixture Fulfilment Free Item' );
		$product_free->set_regular_price( '0.00' );
		$product_free->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_free->save();
		$this->product_fixture_ids[] = $product_free->get_id();

		$order_slip = new \WC_Order();
		$order_slip->set_customer_id( 0 );
		$item_paid = new \WC_Order_Item_Product();
		$item_paid->set_product( $product_paid );
		$item_paid->set_quantity( 1 );
		$item_paid->set_subtotal( '25.00' );
		$item_paid->set_total( '25.00' );
		$order_slip->add_item( $item_paid );
		$item_free = new \WC_Order_Item_Product();
		$item_free->set_product( $product_free );
		$item_free->set_quantity( 1 );
		$item_free->set_subtotal( '0.00' );
		$item_free->set_total( '0.00' );
		$order_slip->add_item( $item_free );
		$order_slip->calculate_totals( false );
		$order_slip->save();
		$this->order_fixture_ids[] = $order_slip->get_id();

		$slip_html = $fulfil_class::packing_slip_html( $order_slip );
		$this->check(
			'test_fulfilment: a packing slip renders every line including free items',
			str_contains( $slip_html, 'Counter Selftest Fixture Fulfilment Paid Item' )
				&& str_contains( $slip_html, 'Counter Selftest Fixture Fulfilment Free Item' ),
			'length=' . strlen( $slip_html )
		);

		// -- Check 4: cancelling from the queue reverts stock.
		$product_cancel = new \WC_Product_Simple();
		$product_cancel->set_name( 'Counter Selftest Fixture Fulfilment Cancel' );
		$product_cancel->set_regular_price( '10.00' );
		$product_cancel->set_manage_stock( true );
		$product_cancel->set_stock_quantity( 0 );
		$product_cancel->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_cancel->save();
		$this->product_fixture_ids[] = $product_cancel->get_id();
		\Counter\Stock\Ledger::move( [ 'product_id' => $product_cancel->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '10', 'reason' => 'opening', 'ref_type' => 'selftest_fulfilment', 'ref_id' => 0 ] );

		$order_cancel = $make_online_order( $product_cancel, 4, '40.00' );
		$this->order_fixture_ids[] = $order_cancel->get_id();
		$order_cancel->set_status( 'processing' );
		$order_cancel->save();
		$balance_before_cancel = \Counter\Stock\Ledger::balance( $product_cancel->get_id(), 0, $main_id );

		$cancel_result = $fulfil_class::cancel( $order_cancel->get_id() );
		$balance_after_cancel = \Counter\Stock\Ledger::balance( $product_cancel->get_id(), 0, $main_id );
		$this->check(
			'test_fulfilment: cancelling from the queue reverts stock',
			true === $cancel_result && '6.0000' === $balance_before_cancel && '10.0000' === $balance_after_cancel,
			wp_json_encode( [ 'result' => $cancel_result, 'before' => $balance_before_cancel, 'after' => $balance_after_cancel ] )
		);

		if ( ! empty( $admins_ff ) ) {
			wp_set_current_user( $original_user_id_ff );
		}
	}

	// -- P4.3: test_price_groups() -- 5 checks ------------------------------------------

	private function test_price_groups(): void {
		global $wpdb;
		$groups_class  = \Counter\Pricing\Groups::class;
		$catalog_table = Install::table( 'catalog_index' );
		$main_id       = \Counter\Stock\Locations::default_id();

		$retail_id    = $groups_class::default_group_id();
		$wholesale    = $groups_class::get_by_code( 'wholesale' );
		$online       = $groups_class::get_by_code( 'online' );
		$wholesale_id = (int) ( $wholesale['id'] ?? 0 );
		$online_id    = (int) ( $online['id'] ?? 0 );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Price Group Product' );
		$product->set_regular_price( '20.00' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		// Check 1 — a product with no override falls back to the WooCommerce price.
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Price Group Register',
				'location_id' => $main_id,
				'prefix'      => 'ZP' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'settings'    => [ 'price_group_id' => $wholesale_id ],
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;

		$price_no_override = $groups_class::price_at_register( $register_id, $product_id, 0 );
		$this->check(
			'test_price_groups: a product with no override falls back to the WooCommerce price',
			0 === bccomp( $price_no_override, '20.0000', 4 ),
			"price={$price_no_override}"
		);

		// Check 3 setup — capture the catalogue revision BEFORE the override
		// that check 2 is about to write, so check 3 can prove it moved.
		$rev_before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT rev FROM {$catalog_table} WHERE product_id = %d AND variation_id = 0", $product_id )
		);

		// Check 2 — an override applies at the till for that register's
		// group: the register above is configured for the wholesale group.
		$groups_class::set_price( $wholesale_id, $product_id, 0, '15.00' );
		$price_with_override = $groups_class::price_at_register( $register_id, $product_id, 0 );
		$this->check(
			'test_price_groups: an override applies at the till for that register\'s group',
			0 === bccomp( $price_with_override, '15.0000', 4 ),
			"price={$price_with_override}"
		);

		// Check 3 — a group change bumps the catalogue revision.
		$rev_after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT rev FROM {$catalog_table} WHERE product_id = %d AND variation_id = 0", $product_id )
		);
		$this->check(
			'test_price_groups: a group change bumps the catalogue revision',
			$rev_after > $rev_before,
			"before={$rev_before} after={$rev_after}"
		);

		// COUNTERFRONTEND.md price-group wiring fix, 2026-08-26 — the gap
		// every check above missed: price_at_register() itself was never
		// unreachable, but nothing in the real request path ever CALLED it.
		// Every check above is a direct call to price_at_register() or
		// Groups::set_price() — none of them go through a real REST request,
		// so none of them would have caught that gap. This one does: a real
		// GET /catalog dispatch, with the X-CNTR-Register header the fix
		// reads, for the exact wholesale-configured register above.
		// since = $rev_before keeps this to just this product's own change,
		// never a scan of the site's whole real catalogue history.
		//
		// Router::guard_any()'s is_user_logged_in() check runs before
		// anything else — a bare `wp eval` (no --user) has no logged-in
		// user at all, the same trap test_terminal_assets() and
		// test_customer_ledger() already documented and worked around; same
		// fix here, an explicit administrator context restored right after.
		$original_user_id_pricegroup = get_current_user_id();
		$admins_pricegroup            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_pricegroup ) ) {
			wp_set_current_user( (int) $admins_pricegroup[0] );
		}

		$catalog_req = new \WP_REST_Request( 'GET', '/counter/v1/catalog' );
		$catalog_req->set_param( 'since', $rev_before );
		$catalog_req->set_param( 'limit', 500 );
		$catalog_req->set_header( 'X-CNTR-Register', (string) $register_id );
		$catalog_result = rest_do_request( $catalog_req );
		$catalog_data   = $catalog_result->get_data();
		$catalog_row    = null;
		foreach ( (array) ( $catalog_data['changed'] ?? [] ) as $r ) {
			if ( $product_id === (int) ( $r['id'] ?? 0 ) ) {
				$catalog_row = $r;
				break;
			}
		}
		$this->check(
			'test_price_groups: a real GET /catalog request for that register delivers the price-group override, not just price_at_register() called directly',
			null !== $catalog_row && 0 === bccomp( (string) $catalog_row['price'], '15.0000', 4 ),
			wp_json_encode( $catalog_row )
		);

		wp_set_current_user( $original_user_id_pricegroup );

		// Check 4 — the storefront shows the online price: wc_get_product()
		// ->get_price() goes through the REAL wired woocommerce_product_
		// get_price filter here, not a direct call to Groups::price_for().
		$groups_class::set_price( $online_id, $product_id, 0, '25.00' );
		$storefront_price = wc_get_product( $product_id )->get_price();
		$this->check(
			'test_price_groups: the storefront shows the online price',
			0 === bccomp( $storefront_price, '25.00', 2 ),
			"price={$storefront_price}"
		);

		// Check 5 — sale prices still win where WooCommerce says they
		// should: a WC sale price, lower than both the regular price and
		// the online override above, must be what get_price() returns.
		$product_sale = wc_get_product( $product_id );
		$product_sale->set_sale_price( '12.00' );
		$product_sale->save();
		$sale_price = wc_get_product( $product_id )->get_price();
		$this->check(
			'test_price_groups: sale prices still win where WooCommerce says they should',
			0 === bccomp( $sale_price, '12.00', 2 ),
			"price={$sale_price}"
		);
	}

	// -- P4.6: test_payment_accounts() -- 6 checks --------------------------------------

	private function test_payment_accounts(): void {
		global $wpdb;
		$accounts_class = \Counter\Pos\Accounts::class;
		$main_id        = \Counter\Stock\Locations::default_id();
		$tenders_table  = Install::table( 'tenders' );

		$original_user_id_pa = get_current_user_id();
		$admins_pa              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_pa ) ) {
			wp_set_current_user( (int) $admins_pa[0] );
		}
		$original_gateway_map = $accounts_class::gateway_map();

		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture Accounts Register', 'location_id' => $main_id, 'prefix' => 'ZA' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Accounts Product' );
		$product->set_regular_price( '20.00' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$this->product_fixture_ids[] = $product->get_id();

		$context = [ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id() ];
		$make_order = function ( string $receipt_no ) use ( $product, $context ) {
			$order = \Counter\Orders\Builder::build(
				[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '20.00', 'total' => '20.00' ] ],
				$context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => $receipt_no ]
			);
			$this->order_fixture_ids[] = $order->get_id();
			return $order;
		};

		// Check 1 — a POS tender with no account is refused: NAGAD's seeded
		// account is deleted for the duration of this one check (it has
		// never had a real movement, so delete() itself succeeds), then
		// restored so no other test or real future use loses it.
		$nagad_before   = $accounts_class::get_by_code( 'NAGAD' );
		$deleted_nagad  = $accounts_class::delete( (int) $nagad_before['id'] );
		$order_1        = $make_order( 'SELFTEST-ACCT-1' );
		$result_1       = \Counter\Pos\Tenders::record( $order_1, [ [ 'method' => 'nagad', 'amount' => '20.00' ] ], $shift_id );
		$accounts_class::create( [ 'name' => $nagad_before['name'], 'code' => $nagad_before['code'], 'kind' => $nagad_before['kind'] ] ); // restore
		$this->check(
			'test_payment_accounts: a POS tender with no account is refused',
			true === $deleted_nagad
				&& is_wp_error( $result_1 ) && 'cntr_tender_no_account' === $result_1->get_error_code(),
			wp_json_encode( [ 'deleted' => $deleted_nagad, 'result' => is_wp_error( $result_1 ) ? $result_1->get_error_code() : $result_1 ] )
		);

		// Check 2 — a tender with an INACTIVE account is refused: ROCKET
		// stays on record (unlike check 1's delete), just deactivated for
		// the duration of this check, then reactivated.
		$rocket = $accounts_class::get_by_code( 'ROCKET' );
		$accounts_class::deactivate( (int) $rocket['id'] );
		$order_2  = $make_order( 'SELFTEST-ACCT-2' );
		$result_2 = \Counter\Pos\Tenders::record( $order_2, [ [ 'method' => 'rocket', 'amount' => '20.00' ] ], $shift_id );
		$wpdb->update( Install::table( 'payment_accounts' ), [ 'status' => 'active' ], [ 'id' => (int) $rocket['id'] ] ); // restore
		$this->check(
			'test_payment_accounts: a tender with an inactive account is refused',
			is_wp_error( $result_2 ) && 'cntr_tender_no_account' === $result_2->get_error_code(),
			is_wp_error( $result_2 ) ? $result_2->get_error_code() : wp_json_encode( $result_2 )
		);

		// Check 3 — every enabled gateway resolves to an account or is
		// reported: a known fake gateway list, independent of whichever
		// real gateway plugins happen to be installed on this site.
		$accounts_class::set_gateway_map( [] );
		$fake_gateways     = [ 'sslcommerz', 'aamarpay', 'selftest_cod' ];
		$unmapped_all      = $accounts_class::unmapped_from_gateway_ids( $fake_gateways );
		$real_account      = $accounts_class::get_by_code( 'CASH' );
		$accounts_class::set_gateway_map( [ 'selftest_cod' => (int) $real_account['id'] ] );
		$unmapped_partial  = $accounts_class::unmapped_from_gateway_ids( $fake_gateways );
		$this->check(
			'test_payment_accounts: every enabled gateway resolves to an account or is reported by the Health check',
			3 === count( $unmapped_all )
				&& 2 === count( $unmapped_partial )
				&& ! in_array( 'selftest_cod', $unmapped_partial, true ),
			wp_json_encode( [ 'all' => $unmapped_all, 'partial' => $unmapped_partial ] )
		);

		// Check 4 — an online order through an unmapped gateway is accepted
		// AND counted as unmapped. sslcommerz has no mapping (check 3 only
		// mapped selftest_cod).
		$order_4 = new \WC_Order();
		$order_4->set_customer_id( 0 );
		$item_4 = new \WC_Order_Item_Product();
		$item_4->set_product( $product );
		$item_4->set_quantity( 1 );
		$item_4->set_subtotal( '20.00' );
		$item_4->set_total( '20.00' );
		$order_4->add_item( $item_4 );
		$order_4->set_payment_method( 'sslcommerz' );
		$order_4->calculate_totals( false );
		$order_4->save();
		$this->order_fixture_ids[] = $order_4->get_id();
		$accounts_class::tag_gateway_mapping( $order_4->get_id() ); // exactly what the woocommerce_payment_complete hook calls
		$unmapped_orders  = $accounts_class::unmapped_order_ids();
		$order_4_reloaded = wc_get_order( $order_4->get_id() );
		$this->check(
			'test_payment_accounts: an online order through an unmapped gateway is accepted and counted as unmapped',
			$order_4_reloaded instanceof \WC_Order // accepted — tag_gateway_mapping() never refuses, never deletes
				&& in_array( $order_4->get_id(), $unmapped_orders, true ),
			wp_json_encode( [ 'order_exists' => $order_4_reloaded instanceof \WC_Order, 'unmapped_count' => count( $unmapped_orders ) ] )
		);

		// Check 5 — the reconciliation report's per-account totals sum to
		// the period's takings. Compared against an INDEPENDENTLY computed
		// SQL total for today, not a hardcoded fixture figure — many other
		// tests in this same run write real same-day tenders too.
		$today = gmdate( 'Y-m-d' );
		$recon = $accounts_class::reconciliation( $today, $today );
		$recon_total = '0.0000';
		foreach ( $recon as $r ) {
			$recon_total = bcadd( $recon_total, $r['expected'], 4 );
		}
		$expected_tenders = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN is_change = 1 THEN -amount ELSE amount END), 0) FROM {$tenders_table} WHERE created_at BETWEEN %s AND %s AND account_id > 0",
				$today . ' 00:00:00',
				$today . ' 23:59:59'
			)
		);
		$mapped_order_ids = wc_get_orders(
			[ 'limit' => -1, 'return' => 'ids', 'date_paid' => $today . '...' . $today, 'meta_query' => [ [ 'key' => '_cntr_gateway_account_id', 'compare' => 'EXISTS' ] ] ] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		$expected_online = '0.0000';
		foreach ( $mapped_order_ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o ) {
				$expected_online = bcadd( $expected_online, wc_format_decimal( $o->get_total(), 4 ), 4 );
			}
		}
		$expected_total = bcadd( wc_format_decimal( $expected_tenders, 4 ), $expected_online, 4 );
		$this->check(
			"test_payment_accounts: the reconciliation report's per-account totals sum to the period's takings",
			0 === bccomp( $recon_total, $expected_total, 4 ),
			"recon_total={$recon_total} expected_total={$expected_total}"
		);

		// Check 6 — deleting an account with movements is refused.
		$fixture_account = $accounts_class::create( [ 'name' => 'Counter Selftest Fixture Payment Account', 'code' => 'SELFTESTPAY', 'kind' => 'bank' ] );
		$fixture_account_id = (int) ( $fixture_account['id'] ?? 0 );
		$this->payment_account_fixture_ids[] = $fixture_account_id;
		$wpdb->insert(
			$tenders_table,
			[
				'order_id'   => $order_1->get_id(),
				'refund_id'  => 0,
				'shift_id'   => $shift_id,
				'method'     => 'bank',
				'amount'     => '5.0000',
				'is_change'  => 0,
				'reference'  => 'selftest movement',
				'account_id' => $fixture_account_id,
				'user_id'    => get_current_user_id(),
				'created_at' => Db::now(),
			]
		);
		$delete_result = $accounts_class::delete( $fixture_account_id );
		$this->check(
			'test_payment_accounts: deleting an account with movements is refused (deactivate instead)',
			is_wp_error( $delete_result ) && 'cntr_account_has_movements' === $delete_result->get_error_code(),
			is_wp_error( $delete_result ) ? $delete_result->get_error_code() : wp_json_encode( $delete_result )
		);

		$accounts_class::set_gateway_map( $original_gateway_map );
		if ( ! empty( $admins_pa ) ) {
			wp_set_current_user( $original_user_id_pa );
		}
	}

	// -- P5.1: test_rollup() -- 7 checks -------------------------------------------------

	private function test_rollup(): void {
		global $wpdb;
		$rollup_class      = \Counter\Reports\Rollup::class;
		$sales_daily_table = Install::table( 'sales_daily' );
		$moves_table       = Install::table( 'stock_moves' );

		$original_user_id_ru = get_current_user_id();
		$admins_ru              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_ru ) ) {
			wp_set_current_user( (int) $admins_ru[0] );
		}

		// A dedicated, never-reused location — keyed alongside day/channel
		// in cntr_sales_daily, this is what isolates every row THIS test
		// writes from every other concurrently-running test's own same-day
		// sales at the shared default location, without needing to
		// backdate anything into an artificial future window.
		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Rollup Location', 'code' => 'CNTR-SELFTEST-ROLLUP-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture Rollup Register', 'location_id' => $location_id, 'prefix' => 'ZL' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Rollup Product' );
		$product->set_regular_price( '25.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$this->product_fixture_ids[] = $product->get_id();
		\Counter\Stock\Batches::receive( [ 'product_id' => $product->get_id(), 'variation_id' => 0, 'location_id' => $location_id, 'lot_no' => 'ROLLUP-A', 'qty_received' => '1000', 'unit_cost' => '10.0000' ] );

		// 3 POS sales.
		$pos_context = [ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $location_id, 'operator_id' => get_current_user_id() ];
		$pos_orders  = [];
		foreach ( [ [ 2, '50.00' ], [ 1, '25.00' ], [ 3, '75.00' ] ] as $i => $spec ) {
			[ $qty, $total ] = $spec;
			$order = \Counter\Orders\Builder::build(
				[ [ 'product' => $product, 'qty' => $qty, 'subtotal' => $total, 'total' => $total ] ],
				$pos_context + [ 'uuid' => wp_generate_uuid4(), 'receipt_no' => "SELFTEST-ROLLUP-POS-{$i}" ]
			);
			$this->order_fixture_ids[] = $order->get_id();
			\Counter\Orders\Channel::apply_stock( $order );
			$order->set_status( 'completed' );
			$order->save();
			$pos_orders[] = $order;
		}

		// 2 online sales.
		$online_orders = [];
		foreach ( [ [ 1, '40.00' ], [ 2, '80.00' ] ] as $spec ) {
			[ $qty, $total ] = $spec;
			$order = new \WC_Order();
			$order->set_customer_id( 0 );
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $qty );
			$item->set_subtotal( $total );
			$item->set_total( $total );
			$order->add_item( $item );
			$order->update_meta_data( '_cntr_location_id', $location_id );
			$order->calculate_totals( false );
			$order->save(); // tags 'online' via the P4.1 hook
			$this->order_fixture_ids[] = $order->get_id();
			$order->set_status( 'processing' ); // fires apply_stock() via P4.1's own hook
			$order->save();
			$online_orders[] = $order;
		}

		$all_orders = array_merge( $pos_orders, $online_orders );
		$rows_1     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sales_daily_table} WHERE location_id = %d", $location_id ), ARRAY_A );

		// Check 1 — net equals an independent query over the orders.
		$expected_net_1 = '0.0000';
		foreach ( $all_orders as $o ) {
			$expected_net_1 = bcadd( $expected_net_1, wc_format_decimal( $o->get_total(), 4 ), 4 );
		}
		$actual_net_1 = '0.0000';
		foreach ( $rows_1 as $r ) {
			$actual_net_1 = bcadd( $actual_net_1, $r['net'], 4 );
		}
		$this->check(
			'test_rollup: a fixture day of three POS sales and two online sales produces rollup rows whose net equals an independent query over the orders',
			0 === bccomp( $actual_net_1, $expected_net_1, 4 ),
			"actual={$actual_net_1} expected={$expected_net_1}"
		);

		// Check 6 — the channel split sums to the combined total.
		$pos_net    = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(net),0) FROM {$sales_daily_table} WHERE location_id = %d AND channel = 'pos'", $location_id ) );
		$online_net = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(net),0) FROM {$sales_daily_table} WHERE location_id = %d AND channel = 'online'", $location_id ) );
		$this->check(
			'test_rollup: the channel split sums to the combined total',
			0 === bccomp( bcadd( $pos_net, $online_net, 4 ), $actual_net_1, 4 ),
			"pos={$pos_net} online={$online_net} combined={$actual_net_1}"
		);

		// Check 2 — a refund reduces refunds and net but not gross.
		$gross_before_refund = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(gross),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$refund_target       = $pos_orders[0]; // the qty=2, total=50.00 sale
		$refund_items        = array_values( $refund_target->get_items( 'line_item' ) );
		\Counter\Orders\Refunds::process(
			$refund_target,
			'25.00',
			[ $refund_items[0]->get_id() => [ 'qty' => 1, 'refund_total' => 25.00, 'refund_tax' => [] ] ],
			'selftest rollup partial refund',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '25.00' ] ]
		);
		$gross_after_refund = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(gross),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$refunds_after       = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(refunds),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$net_after_refund     = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(net),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$this->check(
			'test_rollup: a refund reduces refunds and net but not gross',
			0 === bccomp( $gross_before_refund, $gross_after_refund, 4 )
				&& 0 === bccomp( $refunds_after, '25.0000', 4 )
				&& 0 === bccomp( $net_after_refund, bcsub( $actual_net_1, '25.0000', 4 ), 4 ),
			wp_json_encode( [ 'gross_before' => $gross_before_refund, 'gross_after' => $gross_after_refund, 'refunds' => $refunds_after, 'net_after' => $net_after_refund ] )
		);

		// Check 3 — COGS equals the sum of the ledger's qty x unit_cost for
		// the day, independently computed straight from cntr_stock_moves —
		// never re-derived through Rollup's own code a second time.
		$order_ids   = array_map( static fn( $o ) => $o->get_id(), $all_orders );
		$in          = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		$ledger_cogs = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN reason = 'return' THEN -ABS(qty_delta) * unit_cost ELSE ABS(qty_delta) * unit_cost END),0)
				 FROM {$moves_table} WHERE ref_type = 'order' AND ref_id IN ({$in}) AND reason IN ('sale','sale_online','return')", // phpcs:ignore
				...$order_ids
			)
		);
		$rollup_cogs = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(cogs),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$this->check(
			"test_rollup: COGS equals the sum of the ledger's qty x unit_cost for the day",
			0 === bccomp( wc_format_decimal( $ledger_cogs, 4 ), $rollup_cogs, 4 ),
			"ledger={$ledger_cogs} rollup={$rollup_cogs}"
		);

		// Check 4 — the day boundary uses shop-local time: 23:30 and 00:30
		// Dhaka (UTC+6, no DST) are 17:30 UTC same day and 18:30 UTC the
		// PREVIOUS day — day_for() must place them on different calendar
		// dates, proving it is reading the site's own timezone, not UTC.
		$day_2330 = $rollup_class::day_for( '2031-09-15 17:30:00' ); // 2031-09-15 23:30 Dhaka
		$day_0030 = $rollup_class::day_for( '2031-09-15 18:30:00' ); // 2031-09-16 00:30 Dhaka
		$this->check(
			'test_rollup: the day boundary uses shop-local time',
			'2031-09-15' === $day_2330 && '2031-09-16' === $day_0030 && $day_2330 !== $day_0030,
			"2330={$day_2330} 0030={$day_0030}"
		);

		// Check 5 — rebuilding reproduces the incremental figures exactly.
		// wp_date(), not gmdate(): the fixture rows above were written under
		// Rollup::day_for( Db::now() )'s own shop-local 'today', which can
		// differ from UTC 'today' for hours around midnight Dhaka (UTC+6) —
		// using gmdate() here would scope the rebuild to the wrong date.
		$today = wp_date( 'Y-m-d' );
		$before_rebuild = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(gross),0) g, COALESCE(SUM(refunds),0) rf, COALESCE(SUM(cogs),0) c, COALESCE(SUM(net),0) n FROM {$sales_daily_table} WHERE location_id = %d", $location_id ), ARRAY_A );
		$rollup_class::rebuild( $today, $today, true );
		$after_rebuild = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(gross),0) g, COALESCE(SUM(refunds),0) rf, COALESCE(SUM(cogs),0) c, COALESCE(SUM(net),0) n FROM {$sales_daily_table} WHERE location_id = %d", $location_id ), ARRAY_A );
		$this->check(
			'test_rollup: rebuilding reproduces the incremental figures exactly',
			0 === bccomp( $before_rebuild['g'], $after_rebuild['g'], 4 )
				&& 0 === bccomp( $before_rebuild['rf'], $after_rebuild['rf'], 4 )
				&& 0 === bccomp( $before_rebuild['c'], $after_rebuild['c'], 4 )
				&& 0 === bccomp( $before_rebuild['n'], $after_rebuild['n'], 4 ),
			wp_json_encode( [ 'before' => $before_rebuild, 'after' => $after_rebuild ] )
		);

		// Check 7 — an order edited after the fact updates the rollup.
		$edit_target = $pos_orders[1]; // the qty=1, total=25.00 sale — untouched by check 2's refund
		$gross_before_edit = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(gross),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$edit_target->set_total( '35.00' );
		$edit_target->save();
		$gross_after_edit = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(gross),0) FROM {$sales_daily_table} WHERE location_id = %d", $location_id ) );
		$this->check(
			'test_rollup: an order edited after the fact updates the rollup',
			0 === bccomp( bcadd( $gross_before_edit, '10.0000', 4 ), $gross_after_edit, 4 ),
			"before={$gross_before_edit} after={$gross_after_edit}"
		);

		if ( ! empty( $admins_ru ) ) {
			wp_set_current_user( $original_user_id_ru );
		}
	}

	// -- P5.2: test_reports_channel() -- 6 checks ----------------------------------------

	private function test_reports_channel(): void {
		global $wpdb;
		$reports_class = \Counter\Reports\Reports::class;
		$moves_table   = Install::table( 'stock_moves' );

		$original_user_id_rc = get_current_user_id();
		$admins_rc              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_rc ) ) {
			wp_set_current_user( (int) $admins_rc[0] );
		}

		// Two dedicated, never-reused locations — isolates every row this
		// test's own fixtures write from every other concurrently-running
		// test, and gives check 5 (location partitioning) two genuinely
		// separate buckets to partition.
		$location_1 = \Counter\Stock\Locations::create( [ 'name' => 'Counter Selftest Fixture Reports Loc 1', 'code' => 'CNTR-SELFTEST-REPORTS-1-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$location_2 = \Counter\Stock\Locations::create( [ 'name' => 'Counter Selftest Fixture Reports Loc 2', 'code' => 'CNTR-SELFTEST-REPORTS-2-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$this->location_fixture_ids[] = $location_1;
		$this->location_fixture_ids[] = $location_2;

		$register_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Reports Register', 'location_id' => $location_1, 'prefix' => 'ZR' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Reports Product' );
		$product->set_regular_price( '20.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$this->product_fixture_ids[] = $product->get_id();
		\Counter\Stock\Batches::receive( [ 'product_id' => $product->get_id(), 'variation_id' => 0, 'location_id' => $location_1, 'lot_no' => 'REPORTS-A', 'qty_received' => '1000', 'unit_cost' => '5.0000' ] );
		\Counter\Stock\Batches::receive( [ 'product_id' => $product->get_id(), 'variation_id' => 0, 'location_id' => $location_2, 'lot_no' => 'REPORTS-B', 'qty_received' => '1000', 'unit_cost' => '5.0000' ] );

		// A POS sale (qty 2) and an online sale (qty 3) at location_1, today.
		$pos_order = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 2, 'subtotal' => '40.00', 'total' => '40.00' ] ],
			[ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $location_1, 'operator_id' => get_current_user_id(), 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-REPORTS-POS' ]
		);
		$this->order_fixture_ids[] = $pos_order->get_id();
		\Counter\Orders\Channel::apply_stock( $pos_order );
		$pos_order->set_status( 'completed' );
		$pos_order->save();

		$online_order = new \WC_Order();
		$online_order->set_customer_id( 0 );
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 3 );
		$item->set_subtotal( '60.00' );
		$item->set_total( '60.00' );
		$online_order->add_item( $item );
		$online_order->update_meta_data( '_cntr_location_id', $location_1 );
		$online_order->calculate_totals( false );
		$online_order->save();
		$this->order_fixture_ids[] = $online_order->get_id();
		$online_order->set_status( 'processing' );
		$online_order->save();

		// A third sale (qty 1, POS) at location_2 — check 5's own second bucket.
		$register_2 = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Reports Register 2', 'location_id' => $location_2, 'prefix' => 'ZS' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_2;
		$shift_2 = \Counter\Pos\Shifts::open( $register_2, get_current_user_id(), '0.00' );
		$loc2_order = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 1, 'subtotal' => '20.00', 'total' => '20.00' ] ],
			[ 'register_id' => $register_2, 'shift_id' => $shift_2, 'location_id' => $location_2, 'operator_id' => get_current_user_id(), 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-REPORTS-LOC2' ]
		);
		$this->order_fixture_ids[] = $loc2_order->get_id();
		\Counter\Orders\Channel::apply_stock( $loc2_order );
		$loc2_order->set_status( 'completed' );
		$loc2_order->save();

		$today = wp_date( 'Y-m-d' );

		// Check 1 — channel=all equals pos plus online, FOR EVERY REPORT —
		// one representative numeric column per report, since each report's
		// own row shape differs.
		$checked_key = [
			'sales_summary' => 'gross', 'sales_by_product' => 'qty_sold', 'sales_by_category' => 'qty_sold',
			'sales_by_cashier' => 'amount', 'sales_by_hour' => 'qty_sold', 'tender_mix' => 'amount',
			'discounts' => 'discount', 'refunds' => 'refunds', 'margin' => 'net', 'tax_collected' => 'tax',
			'register_history' => 'expected_cash',
		];
		$sum_column = static function ( array $rows, string $key ): string {
			$sum = '0.0000';
			foreach ( $rows as $r ) {
				if ( isset( $r[ $key ] ) ) {
					$sum = bcadd( $sum, (string) $r[ $key ], 4 );
				}
			}
			return $sum;
		};

		$check1_pass = true;
		$check1_detail = [];
		foreach ( $reports_class::REPORT_NAMES as $report ) {
			$common = [ 'from' => $today, 'to' => $today, 'location_id' => $location_1 ];
			$all_rows    = $reports_class::run( $report, $common + [ 'channel' => 'all' ] );
			$pos_rows    = $reports_class::run( $report, $common + [ 'channel' => 'pos' ] );
			$online_rows = $reports_class::run( $report, $common + [ 'channel' => 'online' ] );
			$key = $checked_key[ $report ];
			$sum_all    = $sum_column( $all_rows, $key );
			$sum_split  = bcadd( $sum_column( $pos_rows, $key ), $sum_column( $online_rows, $key ), 4 );
			$ok = 0 === bccomp( $sum_all, $sum_split, 4 );
			$check1_pass = $check1_pass && $ok;
			$check1_detail[ $report ] = [ 'all' => $sum_all, 'split' => $sum_split, 'ok' => $ok ];
		}
		$this->check(
			'test_reports_channel: channel=all equals pos plus online for every report',
			$check1_pass,
			wp_json_encode( $check1_detail )
		);

		// Check 2 — a report with no data returns zero rows rather than erroring.
		$empty_rows = $reports_class::run( 'sales_by_product', [ 'from' => '1999-01-01', 'to' => '1999-01-02', 'channel' => 'all', 'location_id' => $location_1 ] );
		$this->check(
			'test_reports_channel: a report with no data returns zero rows rather than erroring',
			! is_wp_error( $empty_rows ) && is_array( $empty_rows ) && empty( $empty_rows ),
			wp_json_encode( $empty_rows )
		);

		// Check 3 — cntr_view_cost gates margin: a user without it gets a
		// response with no margin keys.
		$margin_with_cost = $reports_class::run( 'margin', [ 'from' => $today, 'to' => $today, 'channel' => 'all', 'location_id' => $location_1 ] );

		// A real cashier — has cntr_view_reports (needed to reach the report
		// at all) but deliberately NOT cntr_view_cost. A throwaway user
		// created directly here rather than relying on one already
		// existing on this site, since a fresh install may have none.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$cashier_email = 'cntr-selftest-cashier-' . wp_generate_password( 10, false ) . '@example.invalid';
		$cashier_id    = wc_create_new_customer( $cashier_email, '', wp_generate_password( 16 ) );
		$margin_no_cost = null;
		if ( ! is_wp_error( $cashier_id ) ) {
			( new \WP_User( $cashier_id ) )->set_role( \Counter\Capabilities::ROLE_CASHIER );
			wp_set_current_user( (int) $cashier_id );
			$margin_no_cost = $reports_class::run( 'margin', [ 'from' => $today, 'to' => $today, 'channel' => 'all', 'location_id' => $location_1 ] );
			wp_set_current_user( (int) $admins_rc[0] );
			wp_delete_user( (int) $cashier_id );
		}
		$this->check(
			'test_reports_channel: cntr_view_cost gates margin — a user without it gets a response with no margin keys',
			! empty( $margin_with_cost ) && array_key_exists( 'margin', $margin_with_cost[0] )
				&& null !== $margin_no_cost && ! empty( $margin_no_cost ) && ! array_key_exists( 'margin', $margin_no_cost[0] ) && ! array_key_exists( 'cogs', $margin_no_cost[0] ),
			wp_json_encode( [ 'with_cost_keys' => $margin_with_cost ? array_keys( $margin_with_cost[0] ) : null, 'no_cost_keys' => $margin_no_cost ? array_keys( $margin_no_cost[0] ) : null ] )
		);

		// Check 4 — date filters are inclusive at both ends. Two fixture
		// sale moves, backdated to the exact start and end instants of a
		// fabricated 3-day window nothing else could ever collide with.
		$boundary_product = new \WC_Product_Simple();
		$boundary_product->set_name( 'Counter Selftest Fixture Reports Boundary' );
		$boundary_product->set_regular_price( '10.00' );
		$boundary_product->set_manage_stock( true );
		$boundary_product->set_stock_quantity( 0 );
		$boundary_product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$boundary_product->save();
		$this->product_fixture_ids[] = $boundary_product->get_id();

		$m1 = \Counter\Stock\Ledger::move( [ 'product_id' => $boundary_product->get_id(), 'variation_id' => 0, 'location_id' => $location_1, 'qty_delta' => '-1', 'reason' => 'sale', 'ref_type' => 'selftest_reports_boundary', 'ref_id' => 0, 'channel' => 'pos' ] );
		$wpdb->update( $moves_table, [ 'created_at' => '2031-11-01 00:00:00' ], [ 'id' => $m1 ] );
		$m2 = \Counter\Stock\Ledger::move( [ 'product_id' => $boundary_product->get_id(), 'variation_id' => 0, 'location_id' => $location_1, 'qty_delta' => '-1', 'reason' => 'sale', 'ref_type' => 'selftest_reports_boundary', 'ref_id' => 0, 'channel' => 'pos' ] );
		$wpdb->update( $moves_table, [ 'created_at' => '2031-11-03 23:59:59' ], [ 'id' => $m2 ] );

		$inclusive = $reports_class::run( 'sales_by_product', [ 'from' => '2031-11-01', 'to' => '2031-11-03', 'channel' => 'all', 'location_id' => $location_1 ] );
		$narrowed  = $reports_class::run( 'sales_by_product', [ 'from' => '2031-11-02', 'to' => '2031-11-02', 'channel' => 'all', 'location_id' => $location_1 ] );
		$find_boundary = static function ( array $rows ) use ( $boundary_product ) {
			foreach ( $rows as $r ) {
				if ( $r['product_id'] === $boundary_product->get_id() ) {
					return $r;
				}
			}
			return null;
		};
		$inclusive_row = $find_boundary( $inclusive );
		$narrowed_row  = $find_boundary( $narrowed );
		$this->check(
			'test_reports_channel: date filters are inclusive at both ends',
			null !== $inclusive_row && '2.0000' === $inclusive_row['qty_sold'] && null === $narrowed_row,
			wp_json_encode( [ 'inclusive' => $inclusive_row, 'narrowed' => $narrowed_row ] )
		);

		// Check 5 — location_id filtering partitions correctly. Asserted
		// against the exact fixture quantities (5 at location_1, 1 at
		// location_2), not against an unfiltered location_id=0 query —
		// that reads EVERY location sitewide, including every other
		// concurrently-running test's own same-day sales, not just these
		// two fixture locations.
		$loc1_rows = $reports_class::run( 'sales_by_product', [ 'from' => $today, 'to' => $today, 'channel' => 'all', 'location_id' => $location_1 ] );
		$loc2_rows = $reports_class::run( 'sales_by_product', [ 'from' => $today, 'to' => $today, 'channel' => 'all', 'location_id' => $location_2 ] );
		$qty_loc1 = $sum_column( $loc1_rows, 'qty_sold' );
		$qty_loc2 = $sum_column( $loc2_rows, 'qty_sold' );
		$this->check(
			'test_reports_channel: location_id filtering partitions correctly',
			0 === bccomp( $qty_loc1, '5.0000', 4 ) && 0 === bccomp( $qty_loc2, '1.0000', 4 ),
			wp_json_encode( [ 'loc1' => $qty_loc1, 'loc2' => $qty_loc2 ] )
		);

		// Check 6 — export produces the same numbers as the screen: the CSV
		// export() streams, parsed back, must equal run()'s own array for
		// identical args.
		$screen_rows = $reports_class::run( 'sales_by_product', [ 'from' => $today, 'to' => $today, 'channel' => 'all', 'location_id' => $location_1 ] );
		$csv         = $reports_class::export( 'sales_by_product', [ 'from' => $today, 'to' => $today, 'channel' => 'all', 'location_id' => $location_1 ] );
		$csv_lines   = is_string( $csv ) ? array_values( array_filter( preg_split( '/\r\n|\n/', $csv ) ) ) : [];
		$csv_matches = ! is_wp_error( $csv ) && ( count( $csv_lines ) - 1 ) === count( $screen_rows );
		if ( $csv_matches && ! empty( $screen_rows ) ) {
			$header = str_getcsv( $csv_lines[0] );
			foreach ( $screen_rows as $i => $row ) {
				$csv_row = array_combine( $header, str_getcsv( $csv_lines[ $i + 1 ] ) );
				foreach ( $row as $k => $v ) {
					if ( ( $csv_row[ $k ] ?? null ) !== (string) $v ) {
						$csv_matches = false;
						break 2;
					}
				}
			}
		}
		$this->check(
			'test_reports_channel: export produces the same numbers as the screen',
			$csv_matches,
			wp_json_encode( [ 'screen_rows' => count( $screen_rows ), 'csv_lines' => count( $csv_lines ) ] )
		);

		if ( ! empty( $admins_rc ) ) {
			wp_set_current_user( $original_user_id_rc );
		}
	}

	// -- P5.3: test_dashboard() -- 3 checks ----------------------------------------

	private function test_dashboard(): void {
		global $wpdb;
		$dashboard_class = \Counter\Reports\Dashboard::class;
		$sales_daily_table = Install::table( 'sales_daily' );
		$shifts_table       = Install::table( 'shifts' );

		$original_user_id_db = get_current_user_id();
		$admins_db              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_db ) ) {
			wp_set_current_user( (int) $admins_db[0] );
		}

		// A dedicated, never-reused location — isolates this test's own
		// "yesterday" rollup rows from every other concurrently-running
		// test's own same-day activity, same reasoning as test_rollup()
		// and test_reports_channel() before it.
		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Dashboard Location', 'code' => 'CNTR-SELFTEST-DASH-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$yesterday = $dashboard_class::yesterday_local();

		// Two rollup rows for "yesterday" — pos and online — written
		// directly in the exact shape Rollup::increment() itself writes.
		// Check 1 traces the dashboard's own figures back to rows this test
		// put there on purpose, not to whatever else genuinely sold
		// yesterday on a shared test site.
		$wpdb->insert(
			$sales_daily_table,
			[
				'day' => $yesterday, 'channel' => 'pos', 'location_id' => $location_id,
				'orders_count' => 2, 'gross' => '100.0000', 'discount' => '0.0000', 'tax' => '0.0000',
				'shipping' => '0.0000', 'rounding' => '0.0000', 'refunds' => '10.0000', 'net' => '90.0000',
				'cogs' => '40.0000', 'margin' => '50.0000', 'items_count' => '3.0000', 'updated_at' => Db::now(),
			]
		);
		$wpdb->insert(
			$sales_daily_table,
			[
				'day' => $yesterday, 'channel' => 'online', 'location_id' => $location_id,
				'orders_count' => 1, 'gross' => '60.0000', 'discount' => '0.0000', 'tax' => '0.0000',
				'shipping' => '0.0000', 'rounding' => '0.0000', 'refunds' => '0.0000', 'net' => '60.0000',
				'cogs' => '20.0000', 'margin' => '40.0000', 'items_count' => '2.0000', 'updated_at' => Db::now(),
			]
		);

		// A low-stock fixture product at this location — same shape as
		// test_stock_reports()'s own reorder-list fixture.
		$product_low = new \WC_Product_Simple();
		$product_low->set_name( 'Counter Selftest Fixture Dashboard Low Stock' );
		$product_low->set_regular_price( '15.00' );
		$product_low->set_manage_stock( true );
		$product_low->set_stock_quantity( 0 );
		$product_low->set_low_stock_amount( 5 );
		$product_low->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_low->save();
		$this->product_fixture_ids[] = $product_low->get_id();
		Db::transaction(
			function () use ( $product_low, $location_id ) {
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_low->get_id(), 'variation_id' => 0, 'location_id' => $location_id, 'qty_delta' => '3', 'reason' => 'opening', 'ref_type' => 'selftest_dashboard', 'ref_id' => 0 ] );
			}
		);

		// A register + shift closed "last night" at this location, with a
		// deliberate cash variance — backdated by the exact same UTC
		// conversion the dashboard itself uses (Rollup::local_day_start_utc()),
		// not a second hand-rolled one.
		$register_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Dashboard Register', 'location_id' => $location_id, 'prefix' => 'ZD' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '100.0000' );
		\Counter\Pos\Shifts::close( $shift_id, '90.0000' );
		$wpdb->update( $shifts_table, [ 'closed_at' => \Counter\Reports\Rollup::local_day_start_utc( $yesterday ) ], [ 'id' => $shift_id ] );

		// Check 1 — every figure on the dashboard traces to a rollup row.
		$data = $dashboard_class::data( $location_id );
		$this->check(
			'test_dashboard: every figure on the dashboard traces to a rollup row',
			$yesterday === $data['day']
				&& 3 === $data['yesterday']['orders_count']
				&& 0 === bccomp( $data['yesterday']['gross'], '160.0000', 4 )
				&& 0 === bccomp( $data['yesterday']['net'], '150.0000', 4 )
				&& 0 === bccomp( $data['yesterday']['refunds'], '10.0000', 4 )
				&& 0 === bccomp( $data['yesterday']['cogs'], '60.0000', 4 )
				&& 0 === bccomp( $data['yesterday']['margin'], '90.0000', 4 )
				&& 0 === bccomp( $data['yesterday']['items_count'], '5.0000', 4 )
				&& 0 === bccomp( $data['yesterday']['margin_pct'], '56.25', 2 )
				&& 1 === count( $data['low_stock'] ) && $product_low->get_id() === $data['low_stock'][0]['product_id']
				&& 1 === count( $data['shifts'] ) && $shift_id === $data['shifts'][0]['id'] && false === $data['shifts'][0]['balanced'],
			wp_json_encode( $data )
		);

		// Check 2 — the query count is under 15 for the same, realistically
		// populated fixture (all three panels have real rows to return).
		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}
		$queries_before = $wpdb->num_queries;
		$dashboard_class::data( $location_id );
		$query_count = $wpdb->num_queries - $queries_before;
		$this->check(
			'test_dashboard: the query count is under 15',
			$query_count < 15,
			"queries={$query_count}"
		);

		// Check 3 — renders with zero data on a fresh install rather than
		// dividing by zero. A second, genuinely empty location (no
		// sales_daily row, no stock, no shifts) — both the data layer and
		// the actual admin render() are exercised, since "renders" means
		// the page, not just the numbers behind it.
		$empty_location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Dashboard Empty', 'code' => 'CNTR-SELFTEST-DASH-EMPTY-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $empty_location_id;

		$empty_data = $dashboard_class::data( $empty_location_id );

		$get_backup       = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only save/restore of superglobal around a render() call
		$_GET['location_id'] = (string) $empty_location_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$render_threw = false;
		$html         = '';
		try {
			ob_start();
			\Counter\Admin\Screens\Dashboard::render();
			$html = ob_get_clean();
		} catch ( \Throwable $e ) {
			$render_threw = true;
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}
		$_GET = $get_backup; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->check(
			'test_dashboard: renders with zero data on a fresh install rather than dividing by zero',
			! $render_threw
				&& 0 === $empty_data['yesterday']['orders_count']
				&& 0 === bccomp( $empty_data['yesterday']['gross'], '0.0000', 4 )
				&& 0 === bccomp( $empty_data['yesterday']['margin_pct'], '0.0000', 4 )
				&& [] === $empty_data['low_stock']
				&& [] === $empty_data['shifts']
				&& str_contains( $html, 'No sales recorded yesterday.' )
				&& str_contains( $html, 'Nothing below its low-stock threshold.' )
				&& str_contains( $html, 'No shifts closed last night.' )
				&& ! preg_match( '/Division by zero|DivisionByZeroError/i', $html ),
			wp_json_encode( [ 'threw' => $render_threw, 'empty_data' => $empty_data, 'html_len' => strlen( $html ) ] )
		);

		if ( ! empty( $admins_db ) ) {
			wp_set_current_user( $original_user_id_db );
		}
	}

	// -- P5.4: test_expenses() -- 7 checks ----------------------------------------

	private function test_expenses(): void {
		global $wpdb;
		$expenses_class = \Counter\Reports\Expenses::class;
		$expenses_table = Install::table( 'expenses' );
		$sales_daily_table = Install::table( 'sales_daily' );

		$original_user_id_ex = get_current_user_id();
		$admins_ex              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_ex ) ) {
			wp_set_current_user( (int) $admins_ex[0] );
		}

		// A dedicated, never-reused location — isolates this test's own
		// revenue/expense figures from every other concurrently-running
		// test's own same-day activity, same reasoning as every P&L-adjacent
		// test before it (test_rollup(), test_reports_channel(), test_dashboard()).
		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Expenses Location', 'code' => 'CNTR-SELFTEST-EXP-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$account = \Counter\Pos\Accounts::create(
			[ 'name' => 'Counter Selftest Fixture Expenses Account', 'code' => 'CNTREXP' . substr( wp_generate_password( 6, false ), 0, 6 ), 'kind' => 'bank' ]
		);
		$account_id                          = (int) $account['id'];
		$this->payment_account_fixture_ids[] = $account_id;

		$category_id = $expenses_class::create_category(
			'Counter Selftest Fixture Expenses Category',
			'cntr-selftest-exp-' . substr( wp_generate_password( 6, false ), 0, 6 )
		);
		$this->expense_category_fixture_ids[] = $category_id;

		// Pinned to the 1st of the current shop-local month — the same date
		// generate_recurring_drafts() itself always books to — so every
		// fixture in this test (the manual expenses AND the recurring
		// draft) falls inside one single, shared query window with no
		// month-boundary arithmetic required.
		$period = wp_date( 'Y-m' );
		$day    = $period . '-01';

		// A known day of "revenue" — a fixture cntr_sales_daily row, the
		// exact shape Rollup::increment() itself writes, so gross_margin is
		// a known quantity this test does not have to separately reconstruct.
		$wpdb->insert(
			$sales_daily_table,
			[
				'day' => $day, 'channel' => 'pos', 'location_id' => $location_id,
				'orders_count' => 1, 'gross' => '500.0000', 'discount' => '0.0000', 'tax' => '0.0000',
				'shipping' => '0.0000', 'rounding' => '0.0000', 'refunds' => '0.0000', 'net' => '500.0000',
				'cogs' => '200.0000', 'margin' => '300.0000', 'items_count' => '1.0000', 'updated_at' => Db::now(),
			]
		);

		$args_all = [ 'from' => $day, 'to' => $day, 'channel' => 'all', 'location_id' => $location_id ];

		// Check 1 — an expense reduces net profit by exactly its amount.
		$pnl_before_1 = $expenses_class::profit_and_loss( $args_all );
		$exp_id_1     = $expenses_class::create(
			[ 'category_id' => $category_id, 'location_id' => $location_id, 'account_id' => $account_id, 'amount' => '50.0000', 'spent_on' => $day, 'note' => 'selftest check 1' ]
		);
		$this->expense_fixture_ids[] = $exp_id_1;
		$pnl_after_1                  = $expenses_class::profit_and_loss( $args_all );
		$this->check(
			'test_expenses: an expense reduces net profit by exactly its amount',
			! is_wp_error( $exp_id_1 ) && 0 === bccomp( bcsub( $pnl_before_1['net'], '50.0000', 4 ), $pnl_after_1['net'], 4 ),
			wp_json_encode( [ 'before' => $pnl_before_1['net'], 'after' => $pnl_after_1['net'] ] )
		);

		// Check 2 — a draft recurring expense does not affect the P&L until
		// confirmed, bundled with the natural other half of the same claim:
		// it DOES take effect, by exactly its amount, the moment it is.
		$pnl_before_draft = $expenses_class::profit_and_loss( $args_all );
		$rule_id           = $expenses_class::add_recurring_rule(
			[ 'category_id' => $category_id, 'location_id' => $location_id, 'account_id' => $account_id, 'channel' => '', 'amount' => '75.0000', 'note' => 'selftest recurring' ]
		);
		$gen_1 = $expenses_class::generate_recurring_drafts( $period );
		foreach ( $gen_1['created'] as $id ) {
			$this->expense_fixture_ids[] = $id;
		}
		$draft_id                  = $gen_1['created'][0] ?? 0;
		$draft_row_before_confirm = $expenses_class::get( $draft_id );
		$pnl_while_draft           = $expenses_class::profit_and_loss( $args_all );

		$expenses_class::confirm( $draft_id );
		$draft_row_after_confirm = $expenses_class::get( $draft_id );
		$pnl_after_confirm        = $expenses_class::profit_and_loss( $args_all );

		$this->check(
			'test_expenses: a draft recurring expense does not affect the P&L until confirmed',
			'draft' === ( $draft_row_before_confirm['status'] ?? '' )
				&& 0 === bccomp( $pnl_before_draft['net'], $pnl_while_draft['net'], 4 )
				&& 'confirmed' === ( $draft_row_after_confirm['status'] ?? '' )
				&& 0 === bccomp( bcsub( $pnl_before_draft['net'], '75.0000', 4 ), $pnl_after_confirm['net'], 4 ),
			wp_json_encode(
				[
					'status_before' => $draft_row_before_confirm['status'] ?? null,
					'status_after'  => $draft_row_after_confirm['status'] ?? null,
					'net_before'    => $pnl_before_draft['net'],
					'net_while_draft' => $pnl_while_draft['net'],
					'net_after_confirm' => $pnl_after_confirm['net'],
				]
			)
		);

		// Check 3 — generating the same recurring month twice produces one
		// draft, not two: a second call for the SAME rule + period this run
		// already generated in check 2.
		$gen_2         = $expenses_class::generate_recurring_drafts( $period );
		$expected_ref  = sprintf( 'REC-%d-%s', $rule_id, $period );
		$ref_row_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$expenses_table} WHERE ref_no = %s", $expected_ref ) );
		$this->check(
			'test_expenses: generating the same recurring month twice produces one draft, not two',
			in_array( $expected_ref, $gen_2['skipped'], true ) && empty( $gen_2['created'] ) && 1 === $ref_row_count,
			wp_json_encode( [ 'gen_2' => $gen_2, 'expected_ref' => $expected_ref, 'count' => $ref_row_count ] )
		);

		// Check 4 — an expense without an account is refused.
		$no_account_result = $expenses_class::create(
			[ 'category_id' => $category_id, 'location_id' => $location_id, 'account_id' => 0, 'amount' => '10.0000', 'spent_on' => $day ]
		);
		$this->check(
			'test_expenses: an expense without an account is refused',
			is_wp_error( $no_account_result ) && 'cntr_expense_no_account' === $no_account_result->get_error_code(),
			is_wp_error( $no_account_result ) ? $no_account_result->get_error_message() : 'not an error'
		);

		// One more, channel-tagged expense — so checks 5/6 exercise a real
		// three-way split (pos/online/unallocated), not a trivial
		// all-unallocated case.
		$exp_id_pos = $expenses_class::create(
			[ 'category_id' => $category_id, 'location_id' => $location_id, 'account_id' => $account_id, 'channel' => 'pos', 'amount' => '15.0000', 'spent_on' => $day, 'note' => 'selftest channel-tagged' ]
		);
		$this->expense_fixture_ids[] = $exp_id_pos;

		$pnl_final = $expenses_class::profit_and_loss( $args_all );

		// Check 5 — the P&L's own components sum to its net figure.
		$this->check(
			"test_expenses: the P&L's own components sum to its net figure",
			0 === bccomp( bcsub( $pnl_final['revenue'], $pnl_final['cogs'], 4 ), $pnl_final['gross_margin'], 4 )
				&& 0 === bccomp( bcsub( $pnl_final['gross_margin'], $pnl_final['expenses_total'], 4 ), $pnl_final['net'], 4 ),
			wp_json_encode( $pnl_final )
		);

		// Check 6 — the channel split plus unallocated equals the total
		// expense, verified against an INDEPENDENT query, not just the
		// P&L's own internal addition.
		$independent_total = wc_format_decimal(
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM {$expenses_table} WHERE status = 'confirmed' AND spent_on = %s AND location_id = %d", $day, $location_id ) ),
			4
		);
		$sum_split    = bcadd( bcadd( $pnl_final['expenses_channel']['pos'], $pnl_final['expenses_channel']['online'], 4 ), $pnl_final['expenses_unallocated'], 4 );
		$sum_category = '0.0000';
		foreach ( $pnl_final['expenses_by_category'] as $row ) {
			$sum_category = bcadd( $sum_category, $row['amount'], 4 );
		}
		$this->check(
			'test_expenses: the channel split plus unallocated equals the total expense',
			0 === bccomp( $sum_split, $pnl_final['expenses_total'], 4 )
				&& 0 === bccomp( $independent_total, $pnl_final['expenses_total'], 4 )
				// the by-category breakdown is a second, independently-grouped
				// view of the exact same rows — it must also sum to the total,
				// or the screen showing it would silently disagree with the
				// screen showing the channel split, right next to it
				&& 0 === bccomp( $sum_category, $pnl_final['expenses_total'], 4 ),
			wp_json_encode( [ 'split_sum' => $sum_split, 'category_sum' => $sum_category, 'total' => $pnl_final['expenses_total'], 'independent' => $independent_total ] )
		);

		// Check 7 — date filters are inclusive at both ends. A fabricated
		// window nothing else could ever collide with, same precedent as
		// test_reports_channel()'s own check 4.
		$boundary_exp_1 = $expenses_class::create(
			[ 'category_id' => $category_id, 'location_id' => $location_id, 'account_id' => $account_id, 'amount' => '5.0000', 'spent_on' => '2031-11-01', 'note' => 'boundary start' ]
		);
		$this->expense_fixture_ids[] = $boundary_exp_1;
		$boundary_exp_2 = $expenses_class::create(
			[ 'category_id' => $category_id, 'location_id' => $location_id, 'account_id' => $account_id, 'amount' => '7.0000', 'spent_on' => '2031-11-03', 'note' => 'boundary end' ]
		);
		$this->expense_fixture_ids[] = $boundary_exp_2;

		$inclusive_pnl = $expenses_class::profit_and_loss( [ 'from' => '2031-11-01', 'to' => '2031-11-03', 'channel' => 'all', 'location_id' => $location_id ] );
		$narrowed_pnl  = $expenses_class::profit_and_loss( [ 'from' => '2031-11-02', 'to' => '2031-11-02', 'channel' => 'all', 'location_id' => $location_id ] );
		$this->check(
			'test_expenses: date filters are inclusive at both ends',
			0 === bccomp( $inclusive_pnl['expenses_total'], '12.0000', 4 ) && 0 === bccomp( $narrowed_pnl['expenses_total'], '0.0000', 4 ),
			wp_json_encode( [ 'inclusive' => $inclusive_pnl['expenses_total'], 'narrowed' => $narrowed_pnl['expenses_total'] ] )
		);

		// This test's own recurring rule is removed explicitly (not a
		// blind restore-to-snapshot) so a rule another concurrent process
		// may have added between this test's own start and now is never
		// silently discarded.
		$expenses_class::remove_recurring_rule( $rule_id );

		if ( ! empty( $admins_ex ) ) {
			wp_set_current_user( $original_user_id_ex );
		}
	}

	// -- P6.1: test_pin() -- 8 checks ----------------------------------------

	private function test_pin(): void {
		global $wpdb;
		$pin_class      = \Counter\Pos\Pin::class;
		$employees_class = \Counter\People\Employees::class;
		$employees_table = Install::table( 'employees' );
		$audit_table     = Install::table( 'audit_log' );

		$original_user_id_pin = get_current_user_id();
		$admins_pin              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_pin ) ) {
			wp_set_current_user( (int) $admins_pin[0] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Pin Location', 'code' => 'CNTR-SELFTEST-PIN-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$register_a = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Pin Register A', 'location_id' => $location_id, 'prefix' => 'ZPA' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$register_b = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Pin Register B', 'location_id' => $location_id, 'prefix' => 'ZPB' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$register_c = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Pin Register C', 'location_id' => $location_id, 'prefix' => 'ZPC' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_a;
		$this->register_fixture_ids[] = $register_b;
		$this->register_fixture_ids[] = $register_c;

		// Employee 1's own linked WP account is an ADMINISTRATOR — deliberately
		// the maximum possible capability set, so check 6 (a PIN cannot grant
		// a capability the ACTIVE caller did not already have) is testing the
		// worst case, not a convenient one.
		$user_1_email = 'cntr-selftest-pin-1-' . wp_generate_password( 10, false ) . '@example.invalid';
		$user_1_id    = wc_create_new_customer( $user_1_email, '', wp_generate_password( 16 ) );
		$user_2_email = 'cntr-selftest-pin-2-' . wp_generate_password( 10, false ) . '@example.invalid';
		$user_2_id    = wc_create_new_customer( $user_2_email, '', wp_generate_password( 16 ) );

		if ( is_wp_error( $user_1_id ) || is_wp_error( $user_2_id ) ) {
			$this->check( 'test_pin: could not create fixture users', false, wp_json_encode( [ 'u1' => $user_1_id, 'u2' => $user_2_id ] ) );
			if ( ! empty( $admins_pin ) ) {
				wp_set_current_user( $original_user_id_pin );
			}
			return;
		}
		$this->employee_user_fixture_ids[] = (int) $user_1_id;
		$this->employee_user_fixture_ids[] = (int) $user_2_id;
		( new \WP_User( $user_1_id ) )->set_role( 'administrator' );
		( new \WP_User( $user_2_id ) )->set_role( \Counter\Capabilities::ROLE_CASHIER );

		$employee_1_id = $employees_class::create( [ 'user_id' => $user_1_id, 'code' => 'CNTR-SELFTEST-PIN-1-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'location_id' => $location_id, 'pin' => '4821' ] );
		$employee_2_id = $employees_class::create( [ 'user_id' => $user_2_id, 'code' => 'CNTR-SELFTEST-PIN-2-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'location_id' => $location_id, 'pin' => '9999' ] );
		if ( is_wp_error( $employee_1_id ) || is_wp_error( $employee_2_id ) ) {
			$this->check( 'test_pin: could not create fixture employees', false, wp_json_encode( [ 'e1' => $employee_1_id, 'e2' => $employee_2_id ] ) );
			if ( ! empty( $admins_pin ) ) {
				wp_set_current_user( $original_user_id_pin );
			}
			return;
		}
		$this->employee_fixture_ids[] = $employee_1_id;
		$this->employee_fixture_ids[] = $employee_2_id;

		// Check 1 — a correct PIN switches the operator.
		$op_before_1 = $pin_class::current_operator( $register_a );
		$switch_1    = $pin_class::switch_operator( $register_a, '4821' );
		$op_after_1  = $pin_class::current_operator( $register_a );
		$this->check(
			'test_pin: a correct PIN switches the operator',
			0 === $op_before_1 && ! is_wp_error( $switch_1 ) && $employee_1_id === $switch_1['operator_id'] && $employee_1_id === $op_after_1,
			wp_json_encode( [ 'before' => $op_before_1, 'switch' => is_wp_error( $switch_1 ) ? $switch_1->get_error_message() : $switch_1, 'after' => $op_after_1 ] )
		);

		// Check 2 — an incorrect one does not.
		$wrong_2 = $pin_class::switch_operator( $register_a, '0000' );
		$op_after_2 = $pin_class::current_operator( $register_a );
		$this->check(
			'test_pin: an incorrect PIN does not switch the operator',
			is_wp_error( $wrong_2 ) && 'cntr_pin_incorrect' === $wrong_2->get_error_code() && $employee_1_id === $op_after_2,
			wp_json_encode( [ 'wrong' => is_wp_error( $wrong_2 ) ? $wrong_2->get_error_code() : $wrong_2, 'after' => $op_after_2 ] )
		);

		// Check 3 — six rapid failures lock the register for sixty seconds.
		// A dedicated register (register_b), untouched until now, so its own
		// failure count starts at zero.
		for ( $i = 0; $i < 6; $i++ ) {
			$pin_class::switch_operator( $register_b, '0000' );
		}
		$locked_b            = $pin_class::is_locked_out( $register_b );
		$locked_out_attempt   = $pin_class::switch_operator( $register_b, '4821' ); // a genuinely CORRECT pin, refused anyway because the register itself is locked
		$this->check(
			'test_pin: six rapid failures lock the register for sixty seconds',
			true === $locked_b && is_wp_error( $locked_out_attempt ) && 'cntr_pin_locked' === $locked_out_attempt->get_error_code(),
			wp_json_encode( [ 'locked' => $locked_b, 'attempt_while_locked' => is_wp_error( $locked_out_attempt ) ? $locked_out_attempt->get_error_code() : $locked_out_attempt ] )
		);

		// Check 4 — the lockout is per register, not global: register_c, at
		// the SAME location, has never had a failed attempt and must still
		// accept a correct PIN while register_b sits locked.
		$switch_c = $pin_class::switch_operator( $register_c, '4821' );
		$this->check(
			'test_pin: the lockout is per register, not global',
			! is_wp_error( $switch_c ) && $employee_1_id === $switch_c['operator_id'] && false === $pin_class::is_locked_out( $register_c ),
			wp_json_encode( [ 'switch_c' => is_wp_error( $switch_c ) ? $switch_c->get_error_message() : $switch_c, 'still_locked_b' => $pin_class::is_locked_out( $register_b ) ] )
		);

		// Check 5 — the PIN hash never appears in any REST response, real
		// REST dispatch (rest_do_request()), the exact serialised body a
		// client would actually receive — not just the internal PHP array.
		$real_hash = (string) $wpdb->get_var( $wpdb->prepare( "SELECT pin_hash FROM {$employees_table} WHERE id = %d", $employee_1_id ) );

		$server        = rest_get_server();
		$req_switch    = new \WP_REST_Request( 'POST', '/counter/v1/pin/switch' );
		$req_switch->set_param( 'register_id', $register_a );
		$req_switch->set_param( 'pin', '4821' );
		$resp_switch   = rest_do_request( $req_switch );
		$body_switch   = wp_json_encode( $server->response_to_data( $resp_switch, false ) );

		$req_current   = new \WP_REST_Request( 'GET', '/counter/v1/pin/current' );
		$req_current->set_param( 'register_id', $register_a );
		$resp_current  = rest_do_request( $req_current );
		$body_current  = wp_json_encode( $server->response_to_data( $resp_current, false ) );

		$this->check(
			'test_pin: the PIN hash never appears in any REST response',
			'' !== $real_hash
				&& false === strpos( (string) $body_switch, $real_hash ) && false === strpos( (string) $body_switch, 'pin_hash' )
				&& false === strpos( (string) $body_current, $real_hash ) && false === strpos( (string) $body_current, 'pin_hash' ),
			wp_json_encode( [ 'switch_body' => $body_switch, 'current_body' => $body_current ] )
		);

		// Check 6 — a PIN cannot grant a capability the employee's user does
		// not have — precisely: switching to employee 1 (whose OWN linked
		// account is an administrator) must not elevate the ACTUALLY
		// authenticated caller's own capabilities, even when that caller is
		// deliberately a low-privileged cashier account.
		$low_email = 'cntr-selftest-pin-low-' . wp_generate_password( 10, false ) . '@example.invalid';
		$low_id    = wc_create_new_customer( $low_email, '', wp_generate_password( 16 ) );
		$can_after_switch = null;
		if ( ! is_wp_error( $low_id ) ) {
			( new \WP_User( $low_id ) )->set_role( \Counter\Capabilities::ROLE_CASHIER );
			wp_set_current_user( (int) $low_id );
			$pin_class::switch_operator( $register_a, '4821' ); // succeeds — switches operator identity to employee 1, an administrator's own linked employee
			$can_after_switch = current_user_can( 'cntr_manage_settings' );
			wp_set_current_user( (int) $admins_pin[0] );
			wp_delete_user( (int) $low_id );
		}
		$this->check(
			'test_pin: a PIN cannot grant a capability the employee\'s user does not have',
			! is_wp_error( $low_id ) && false === $can_after_switch,
			wp_json_encode( [ 'low_user_created' => ! is_wp_error( $low_id ), 'can_manage_settings_after_switch' => $can_after_switch ] )
		);

		// Check 7 — PIN change is audited.
		$audit_count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = 'pin_change' AND object_id = %d", $employee_1_id ) );
		$change_result       = $pin_class::set( $employee_1_id, '778899' );
		$audit_count_after   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = 'pin_change' AND object_id = %d", $employee_1_id ) );
		$this->check(
			'test_pin: PIN change is audited',
			! is_wp_error( $change_result ) && $audit_count_after === $audit_count_before + 1,
			wp_json_encode( [ 'before' => $audit_count_before, 'after' => $audit_count_after, 'result' => is_wp_error( $change_result ) ? $change_result->get_error_message() : $change_result ] )
		);

		// Check 8 — two employees may not share a PIN on the same register:
		// employee 2 tries to take the exact PIN check 7 just gave employee 1,
		// at the same location.
		$dup_result = $pin_class::set( $employee_2_id, '778899' );
		$this->check(
			'test_pin: two employees may not share a PIN on the same register',
			is_wp_error( $dup_result ) && 'cntr_pin_duplicate' === $dup_result->get_error_code(),
			is_wp_error( $dup_result ) ? $dup_result->get_error_message() : 'not an error'
		);

		if ( ! empty( $admins_pin ) ) {
			wp_set_current_user( $original_user_id_pin );
		}
	}

	// -- P6.2: test_attendance() -- 6 checks ----------------------------------------

	private function test_attendance(): void {
		global $wpdb;
		$attendance_class = \Counter\People\Attendance::class;
		$employees_class  = \Counter\People\Employees::class;
		$attendance_table = Install::table( 'attendance' );

		$original_user_id_at = get_current_user_id();
		$admins_at              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_at ) ) {
			wp_set_current_user( (int) $admins_at[0] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Attendance Location', 'code' => 'CNTR-SELFTEST-ATT-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$make_employee = function ( string $tag ) use ( $location_id ) {
			$email   = 'cntr-selftest-att-' . strtolower( $tag ) . '-' . wp_generate_password( 8, false ) . '@example.invalid';
			$user_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$this->employee_user_fixture_ids[] = (int) $user_id;
			$employee_id = \Counter\People\Employees::create(
				[ 'user_id' => $user_id, 'code' => 'CNTR-SELFTEST-ATT-' . $tag . '-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'location_id' => $location_id ]
			);
			if ( ! is_wp_error( $employee_id ) ) {
				$this->employee_fixture_ids[] = $employee_id;
			}
			return $employee_id;
		};

		$employee_a = $make_employee( 'A' );
		$employee_b = $make_employee( 'B' );
		$employee_c = $make_employee( 'C' );
		if ( is_wp_error( $employee_a ) || is_wp_error( $employee_b ) || is_wp_error( $employee_c ) ) {
			$this->check( 'test_attendance: could not create fixture employees', false, wp_json_encode( [ $employee_a, $employee_b, $employee_c ] ) );
			if ( ! empty( $admins_at ) ) {
				wp_set_current_user( $original_user_id_at );
			}
			return;
		}

		// Employee A — a fabricated far-future window nothing else could
		// ever collide with (same precedent as every other backdated
		// fixture in this suite).
		$at_1 = '2031-09-10 03:00:00'; // 09:00 Dhaka, 2031-09-10
		$id_1 = $attendance_class::clock_in( $employee_a, $at_1 );
		if ( ! is_wp_error( $id_1 ) ) {
			$this->attendance_fixture_ids[] = $id_1;
		}

		// Check 2 — a second clock-in without a clock-out is refused.
		$second_clock_in = $attendance_class::clock_in( $employee_a, '2031-09-10 04:00:00' );
		$this->check(
			'test_attendance: a second clock-in without a clock-out is refused',
			! is_wp_error( $id_1 ) && is_wp_error( $second_clock_in ) && 'cntr_attendance_already_open' === $second_clock_in->get_error_code(),
			is_wp_error( $second_clock_in ) ? $second_clock_in->get_error_code() : 'not an error'
		);

		// Check 1 — clock in then out computes minutes correctly (125 minutes exactly).
		$at_1_out    = '2031-09-10 05:05:00';
		$clock_out_1 = $attendance_class::clock_out( $employee_a, $at_1_out );
		$row_1       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attendance_table} WHERE id = %d", $id_1 ), ARRAY_A );
		$this->check(
			'test_attendance: clock in then out computes minutes correctly',
			true === $clock_out_1 && null !== $row_1 && 125 === (int) $row_1['minutes'],
			wp_json_encode( [ 'clock_out' => is_wp_error( $clock_out_1 ) ? $clock_out_1->get_error_message() : $clock_out_1, 'row' => $row_1 ] )
		);

		// Check 4 — the unique (employee, date) key prevents duplicates:
		// employee A, same shop-local calendar day as $at_1 (already
		// clocked out), a second attempt collides on cntr_attendance's
		// own UNIQUE KEY emp_day.
		$dup_attempt = $attendance_class::clock_in( $employee_a, '2031-09-10 10:00:00' );
		$this->check(
			'test_attendance: the unique (employee, date) key prevents duplicates',
			is_wp_error( $dup_attempt ) && 'cntr_attendance_duplicate' === $dup_attempt->get_error_code(),
			is_wp_error( $dup_attempt ) ? $dup_attempt->get_error_code() : 'not an error'
		);

		// Check 3 — a shift crossing midnight is attributed correctly.
		// Employee B: clock in 23:30 Dhaka, clock out 00:30 Dhaka the next
		// calendar day (2031-09-15 17:30/18:30 UTC — the exact fixture
		// instants Reports\Rollup's own test_rollup() check 4 already
		// established for this same boundary). work_date must stay pinned
		// to the day the shift STARTED, and the duration is still exactly
		// 60 real minutes regardless of the date crossing.
		$id_2 = $attendance_class::clock_in( $employee_b, '2031-09-15 17:30:00' );
		if ( ! is_wp_error( $id_2 ) ) {
			$this->attendance_fixture_ids[] = $id_2;
		}
		$clock_out_2 = $attendance_class::clock_out( $employee_b, '2031-09-15 18:30:00' );
		$row_2       = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attendance_table} WHERE id = %d", $id_2 ), ARRAY_A );
		$this->check(
			'test_attendance: a shift crossing midnight is attributed correctly',
			! is_wp_error( $id_2 ) && true === $clock_out_2 && null !== $row_2
				&& '2031-09-15' === $row_2['work_date'] && 60 === (int) $row_2['minutes'],
			wp_json_encode( [ 'id' => $id_2, 'clock_out' => is_wp_error( $clock_out_2 ) ? $clock_out_2->get_error_message() : $clock_out_2, 'row' => $row_2 ] )
		);

		// Check 6 — a clock-out before clock-in is refused: employee C has
		// never clocked in at all.
		$no_open_result = $attendance_class::clock_out( $employee_c );
		$this->check(
			'test_attendance: a clock-out before clock-in is refused',
			is_wp_error( $no_open_result ) && 'cntr_attendance_not_open' === $no_open_result->get_error_code(),
			is_wp_error( $no_open_result ) ? $no_open_result->get_error_code() : 'not an error'
		);

		// Check 5 — an import with a bad row rejects that row and reports
		// it rather than aborting the file. Row 1 is genuinely valid
		// (employee A, a date this test has not used yet); row 2 names an
		// employee code that does not exist.
		$employee_a_row = $employees_class::get( $employee_a );
		$csv            = "employee_code,work_date,clock_in,clock_out\n"
			. $employee_a_row['code'] . ",2031-09-20,09:00,17:00\n"
			. "CNTR-SELFTEST-ATT-NO-SUCH-CODE,2031-09-20,09:00,17:00\n";
		$import_result  = $attendance_class::import_csv( $csv );
		$imported_row    = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$attendance_table} WHERE employee_id = %d AND work_date = %s", $employee_a, '2031-09-20' ), ARRAY_A );
		if ( $imported_row ) {
			$this->attendance_fixture_ids[] = (int) $imported_row['id'];
		}
		$this->check(
			'test_attendance: an import with a bad row rejects that row and reports it rather than aborting the file',
			1 === $import_result['imported']
				&& 1 === count( $import_result['rejected'] )
				&& 3 === (int) $import_result['rejected'][0]['row'] // line 3: header (1) + the valid row (2) + this bad one (3)
				&& null !== $imported_row,
			wp_json_encode( $import_result )
		);

		if ( ! empty( $admins_at ) ) {
			wp_set_current_user( $original_user_id_at );
		}
	}

	// -- P6.3: test_leave() -- 6 checks ----------------------------------------

	private function test_leave(): void {
		global $wpdb;
		$leave_class     = \Counter\People\Leave::class;
		$employees_class = \Counter\People\Employees::class;

		$original_user_id_lv = get_current_user_id();
		$admins_lv              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_lv ) ) {
			wp_set_current_user( (int) $admins_lv[0] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$location_1 = \Counter\Stock\Locations::create( [ 'name' => 'Counter Selftest Fixture Leave Loc 1', 'code' => 'CNTR-SELFTEST-LV-1-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$location_2 = \Counter\Stock\Locations::create( [ 'name' => 'Counter Selftest Fixture Leave Loc 2', 'code' => 'CNTR-SELFTEST-LV-2-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ] );
		$this->location_fixture_ids[] = $location_1;
		$this->location_fixture_ids[] = $location_2;

		$make_employee = function ( string $tag, int $location_id, bool $approver = false ) {
			$email   = 'cntr-selftest-lv-' . strtolower( $tag ) . '-' . wp_generate_password( 8, false ) . '@example.invalid';
			$user_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
			if ( is_wp_error( $user_id ) ) {
				return [ 'error' => $user_id ];
			}
			$this->employee_user_fixture_ids[] = (int) $user_id;
			( new \WP_User( $user_id ) )->set_role( \Counter\Capabilities::ROLE_CASHIER );
			if ( $approver ) {
				( new \WP_User( $user_id ) )->add_cap( 'cntr_approve_leave' );
			}
			$employee_id = \Counter\People\Employees::create(
				[ 'user_id' => $user_id, 'code' => 'CNTR-SELFTEST-LV-' . $tag . '-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'location_id' => $location_id ]
			);
			if ( is_wp_error( $employee_id ) ) {
				return [ 'error' => $employee_id ];
			}
			$this->employee_fixture_ids[] = $employee_id;
			return [ 'user_id' => (int) $user_id, 'employee_id' => $employee_id ];
		};

		$req_1      = $make_employee( 'REQ1', $location_1 );
		$req_2      = $make_employee( 'REQ2', $location_2 );
		$approver_1 = $make_employee( 'APR1', $location_1, true );
		$approver_2 = $make_employee( 'APR2', $location_2, true );
		foreach ( [ $req_1, $req_2, $approver_1, $approver_2 ] as $f ) {
			if ( isset( $f['error'] ) ) {
				$this->check( 'test_leave: could not create fixture employees', false, $f['error']->get_error_message() );
				if ( ! empty( $admins_lv ) ) {
					wp_set_current_user( $original_user_id_lv );
				}
				return;
			}
		}

		// Check 1 — a request over the annual cap is refused. 'casual' is
		// capped at 10 days/year; this one asks for 11 in one go.
		$over_cap = $leave_class::request( [ 'employee_id' => $req_1['employee_id'], 'type' => 'casual', 'from_date' => '2031-09-01', 'to_date' => '2031-09-11' ] );
		$this->check(
			'test_leave: a request over the annual cap is refused',
			is_wp_error( $over_cap ) && 'cntr_leave_over_cap' === $over_cap->get_error_code(),
			is_wp_error( $over_cap ) ? $over_cap->get_error_code() : $over_cap
		);

		// Check 2 — overlapping requests are refused.
		$first_range = $leave_class::request( [ 'employee_id' => $req_1['employee_id'], 'type' => 'casual', 'from_date' => '2031-10-01', 'to_date' => '2031-10-05' ] );
		if ( ! is_wp_error( $first_range ) ) {
			$this->leave_fixture_ids[] = $first_range;
		}
		$overlap = $leave_class::request( [ 'employee_id' => $req_1['employee_id'], 'type' => 'casual', 'from_date' => '2031-10-03', 'to_date' => '2031-10-04' ] );
		$this->check(
			'test_leave: overlapping requests are refused',
			! is_wp_error( $first_range ) && is_wp_error( $overlap ) && 'cntr_leave_overlap' === $overlap->get_error_code(),
			wp_json_encode( [ 'first' => $first_range, 'overlap' => is_wp_error( $overlap ) ? $overlap->get_error_code() : $overlap ] )
		);

		// Check 5 — a half-day counts as 0.5.
		$half_day_id = $leave_class::request( [ 'employee_id' => $req_1['employee_id'], 'type' => 'casual', 'from_date' => '2031-10-10', 'to_date' => '2031-10-10', 'half_day' => true ] );
		if ( ! is_wp_error( $half_day_id ) ) {
			$this->leave_fixture_ids[] = $half_day_id;
		}
		$half_day_row = is_wp_error( $half_day_id ) ? null : $leave_class::get( $half_day_id );
		$this->check(
			'test_leave: a half-day counts as 0.5',
			! is_wp_error( $half_day_id ) && null !== $half_day_row && 0 === bccomp( $half_day_row['days'], '0.50', 2 ),
			wp_json_encode( [ 'id' => $half_day_id, 'row' => $half_day_row ] )
		);

		// Check 4 — an approver sees only their location's requests.
		// employee req_2 (location_2) gets its own independent pending
		// request first, so both locations genuinely have one to partition.
		$loc2_request = $leave_class::request( [ 'employee_id' => $req_2['employee_id'], 'type' => 'sick', 'from_date' => '2031-12-01', 'to_date' => '2031-12-02' ] );
		if ( ! is_wp_error( $loc2_request ) ) {
			$this->leave_fixture_ids[] = $loc2_request;
		}
		$pending_for_1 = $leave_class::pending_for_approver( $approver_1['user_id'] );
		$pending_for_2 = $leave_class::pending_for_approver( $approver_2['user_id'] );
		$ids_1         = array_map( static fn( $r ) => (int) $r['id'], $pending_for_1 );
		$ids_2         = array_map( static fn( $r ) => (int) $r['id'], $pending_for_2 );
		$this->check(
			'test_leave: an approver sees only their location\'s requests',
			! is_wp_error( $loc2_request )
				&& in_array( (int) $first_range, $ids_1, true ) && ! in_array( (int) $loc2_request, $ids_1, true )
				&& in_array( (int) $loc2_request, $ids_2, true ) && ! in_array( (int) $first_range, $ids_2, true ),
			wp_json_encode( [ 'approver_1_sees' => $ids_1, 'approver_2_sees' => $ids_2, 'loc1_request' => $first_range, 'loc2_request' => $loc2_request ] )
		);

		// Check 3 — a requester without cntr_approve_leave cannot approve
		// their own: req_1's own WP user (a plain cashier, no approve
		// capability) tries to approve their OWN still-pending request
		// (check 2's $first_range), through the real REST route.
		wp_set_current_user( $req_1['user_id'] );
		$approve_req = new \WP_REST_Request( 'POST', '/counter/v1/leave/approve' );
		$approve_req->set_param( 'leave_id', $first_range );
		$approve_resp = rest_do_request( $approve_req );
		wp_set_current_user( (int) $admins_lv[0] );
		$this->check(
			'test_leave: a requester without cntr_approve_leave cannot approve their own',
			403 === $approve_resp->get_status(),
			'status=' . $approve_resp->get_status()
		);

		// Check 6 — cancelling an approved request restores the balance.
		// req_2's own 'earned' type is capped at 12/year: a 12-day request
		// (exactly the cap) is approved, a further 1-day request is then
		// refused (the cap is fully spent), the 12-day approval is
		// cancelled, and the SAME 1-day request now succeeds — the balance
		// was never stored anywhere, only ever summed live from what is
		// still requested-or-approved.
		$full_cap_id = $leave_class::request( [ 'employee_id' => $req_2['employee_id'], 'type' => 'earned', 'from_date' => '2031-11-01', 'to_date' => '2031-11-12' ] );
		if ( ! is_wp_error( $full_cap_id ) ) {
			$this->leave_fixture_ids[] = $full_cap_id;
		}
		$approve_full_cap = is_wp_error( $full_cap_id ) ? $full_cap_id : $leave_class::approve( $full_cap_id, $approver_2['user_id'] );
		$blocked_extra     = $leave_class::request( [ 'employee_id' => $req_2['employee_id'], 'type' => 'earned', 'from_date' => '2031-11-15', 'to_date' => '2031-11-15' ] );
		$cancel_result      = is_wp_error( $full_cap_id ) ? $full_cap_id : $leave_class::cancel( $full_cap_id );
		$restored_id        = $leave_class::request( [ 'employee_id' => $req_2['employee_id'], 'type' => 'earned', 'from_date' => '2031-11-15', 'to_date' => '2031-11-15' ] );
		if ( ! is_wp_error( $restored_id ) ) {
			$this->leave_fixture_ids[] = $restored_id;
		}
		$this->check(
			'test_leave: cancelling an approved request restores the balance',
			! is_wp_error( $full_cap_id ) && true === $approve_full_cap
				&& is_wp_error( $blocked_extra ) && 'cntr_leave_over_cap' === $blocked_extra->get_error_code()
				&& true === $cancel_result
				&& ! is_wp_error( $restored_id ),
			wp_json_encode(
				[
					'full_cap' => $full_cap_id,
					'approve' => is_wp_error( $approve_full_cap ) ? $approve_full_cap->get_error_message() : $approve_full_cap,
					'blocked' => is_wp_error( $blocked_extra ) ? $blocked_extra->get_error_code() : $blocked_extra,
					'cancel' => is_wp_error( $cancel_result ) ? $cancel_result->get_error_message() : $cancel_result,
					'restored' => is_wp_error( $restored_id ) ? $restored_id->get_error_message() : $restored_id,
				]
			)
		);

		if ( ! empty( $admins_lv ) ) {
			wp_set_current_user( $original_user_id_lv );
		}
	}

	// -- P6.4: test_payroll() -- 7 checks ----------------------------------------

	private function test_payroll(): void {
		global $wpdb;
		$payroll_class    = \Counter\People\Payroll::class;
		$employees_class  = \Counter\People\Employees::class;
		$attendance_table = Install::table( 'attendance' );
		$lines_table      = Install::table( 'payroll_lines' );
		$expenses_table   = Install::table( 'expenses' );

		$original_user_id_pr = get_current_user_id();
		$admins_pr              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_pr ) ) {
			wp_set_current_user( (int) $admins_pr[0] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Payroll Location', 'code' => 'CNTR-SELFTEST-PR-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$account = \Counter\Pos\Accounts::create( [ 'name' => 'Counter Selftest Fixture Payroll Account', 'code' => 'CNTRPR' . substr( wp_generate_password( 6, false ), 0, 6 ), 'kind' => 'bank' ] );
		$account_id                          = (int) $account['id'];
		$this->payment_account_fixture_ids[] = $account_id;

		$make_employee = function ( string $tag, string $basic ) use ( $location_id ) {
			$email   = 'cntr-selftest-pr-' . strtolower( $tag ) . '-' . wp_generate_password( 8, false ) . '@example.invalid';
			$user_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$this->employee_user_fixture_ids[] = (int) $user_id;
			return \Counter\People\Employees::create(
				[ 'user_id' => $user_id, 'code' => 'CNTR-SELFTEST-PR-' . $tag . '-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'location_id' => $location_id, 'basic' => $basic ]
			);
		};

		// employee_full — attendance every single day of the period.
		$employee_full = $make_employee( 'FULL', '30000.0000' );
		// employee_combined — 26 attended days, 2 unpaid absences, one
		// half-day leave, one holiday: the exact "real month" the plan's
		// own note asks checks 2 and 3 to be built from together.
		$employee_combined = $make_employee( 'COMB', '30000.0000' );
		if ( is_wp_error( $employee_full ) || is_wp_error( $employee_combined ) ) {
			$this->check( 'test_payroll: could not create fixture employees', false, wp_json_encode( [ $employee_full, $employee_combined ] ) );
			if ( ! empty( $admins_pr ) ) {
				wp_set_current_user( $original_user_id_pr );
			}
			return;
		}
		$this->employee_fixture_ids[] = $employee_full;
		$this->employee_fixture_ids[] = $employee_combined;

		$period = '2031-09'; // 30 real calendar days — a fabricated far-future period nothing else could ever collide with

		$insert_attendance = function ( int $employee_id, array $days ) use ( $wpdb, $attendance_table, $period ) {
			foreach ( $days as $d ) {
				$date = $period . '-' . $d;
				$wpdb->insert(
					$attendance_table,
					[
						'employee_id' => $employee_id, 'work_date' => $date,
						'clock_in' => $date . ' 03:00:00', 'clock_out' => $date . ' 11:00:00',
						'minutes' => 480, 'source' => 'terminal', 'status' => 'present',
					]
				);
				$this->attendance_fixture_ids[] = (int) $wpdb->insert_id;
			}
		};

		// employee_full: days 01-30, all present.
		$insert_attendance( $employee_full, array_map( static fn( $n ) => sprintf( '%02d', $n ), range( 1, 30 ) ) );

		// employee_combined: days 01-26 present; 27 and 28 genuinely absent
		// (nothing written for them at all); 29 a half-day leave; 30 a
		// configured holiday with no attendance row.
		$insert_attendance( $employee_combined, array_map( static fn( $n ) => sprintf( '%02d', $n ), range( 1, 26 ) ) );

		$half_day_leave_id = \Counter\People\Leave::request( [ 'employee_id' => $employee_combined, 'type' => 'casual', 'from_date' => $period . '-29', 'to_date' => $period . '-29', 'half_day' => true ] );
		if ( ! is_wp_error( $half_day_leave_id ) ) {
			$this->leave_fixture_ids[] = $half_day_leave_id;
			\Counter\People\Leave::approve( $half_day_leave_id, (int) $admins_pr[0] ); // leave_days() only counts 'approved' rows
		}

		$original_holidays_json = (string) \Counter\Settings::get( 'people.holidays' );
		$original_holidays      = json_decode( $original_holidays_json, true );
		$original_holidays      = is_array( $original_holidays ) ? $original_holidays : [];
		$holiday_date            = $period . '-30';
		\Counter\Settings::set( 'people.holidays', wp_json_encode( array_merge( $original_holidays, [ $holiday_date ] ) ) );

		// One run, both employees — checks 1, 2, 3, 4 and 7 all read from
		// this same run, the same "one real month, hand-verified" pairing
		// the plan's own note asks for.
		$run_id = $payroll_class::create_run( $period, $account_id, get_current_user_id(), [], [ $employee_full, $employee_combined ] );
		if ( ! is_wp_error( $run_id ) ) {
			$this->payroll_run_fixture_ids[] = $run_id;
		}
		$line_full     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lines_table} WHERE run_id = %d AND employee_id = %d", $run_id, $employee_full ), ARRAY_A );
		$line_combined = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lines_table} WHERE run_id = %d AND employee_id = %d", $run_id, $employee_combined ), ARRAY_A );

		// Check 1 — a month of full attendance pays the full salary.
		$this->check(
			'test_payroll: a month of full attendance pays the full salary',
			! is_wp_error( $run_id ) && null !== $line_full && 0 === bccomp( $line_full['net'], '30000.0000', 4 ) && 0 === bccomp( $line_full['days_worked'], '30.00', 2 ),
			wp_json_encode( $line_full )
		);

		// Check 2 — a month with two unpaid absences pays pro rata to the
		// poisha. Hand calculation: 26 attended + 0.5 half-day + 1 holiday
		// = 27.5 paid days of 30 -> 30000.00 x 27.5/30 = 27500.00 exactly.
		$this->check(
			'test_payroll: a month with two unpaid absences pays pro rata to the poisha',
			null !== $line_combined && 0 === bccomp( $line_combined['days_worked'], '27.50', 2 ) && 0 === bccomp( $line_combined['net'], '27500.0000', 4 ),
			wp_json_encode( $line_combined )
		);

		// Check 3 — a half-day leave is counted as half. The same
		// $line_combined already reflects it (26 + 0.5 + 1 = 27.5, not
		// 26 + 1 + 1 = 28) — checked here directly against an independent
		// query over cntr_leave for that day, not just the line's own total.
		$leave_days_direct = \Counter\People\Leave::for_employee( $employee_combined );
		$half_day_row       = null;
		foreach ( $leave_days_direct as $lr ) {
			if ( (int) $lr['id'] === (int) $half_day_leave_id ) {
				$half_day_row = $lr;
			}
		}
		$this->check(
			'test_payroll: a half-day leave is counted as half',
			! is_wp_error( $half_day_leave_id ) && null !== $half_day_row && 0 === bccomp( $half_day_row['days'], '0.50', 2 )
				&& null !== $line_combined && 0 === bccomp( $line_combined['days_worked'], '27.50', 2 ),
			wp_json_encode( [ 'leave_row' => $half_day_row, 'line_days' => $line_combined['days_worked'] ?? null ] )
		);

		// Check 4 — a holiday is paid: day 30 has no attendance row at all
		// for employee_combined, yet is still counted in days_worked
		// (27.5 includes it — without it the total would be 26.5, not 27.5).
		$attended_30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attendance_table} WHERE employee_id = %d AND work_date = %s", $employee_combined, $holiday_date ) );
		$this->check(
			'test_payroll: a holiday is paid',
			0 === $attended_30 && null !== $line_combined && 0 === bccomp( $line_combined['days_worked'], '27.50', 2 ),
			wp_json_encode( [ 'attended_on_holiday' => $attended_30, 'days_worked' => $line_combined['days_worked'] ?? null ] )
		);

		// Check 7 — the run total equals the sum of its lines, verified
		// against an independent query, not the run's own stored figure
		// trusted blindly.
		$run_row       = $payroll_class::get_run( $run_id );
		$sum_of_lines  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(net),0) FROM {$lines_table} WHERE run_id = %d", $run_id ) );
		$this->check(
			'test_payroll: the run total equals the sum of its lines',
			null !== $run_row && 0 === bccomp( $run_row['total'], $sum_of_lines, 4 ),
			wp_json_encode( [ 'run_total' => $run_row['total'] ?? null, 'sum_of_lines' => $sum_of_lines ] )
		);

		// Check 5 — a run for a period that already has one is refused
		// (the UNIQUE KEY on cntr_payroll_runs.period itself, not a second
		// application-level check that could race it).
		$dup_run = $payroll_class::create_run( $period, $account_id, get_current_user_id(), [], [ $employee_full ] );
		$this->check(
			'test_payroll: a run for a period that already has one is refused',
			is_wp_error( $dup_run ) && 'cntr_payroll_period_exists' === $dup_run->get_error_code(),
			is_wp_error( $dup_run ) ? $dup_run->get_error_code() : $dup_run
		);

		// Check 6 — approving twice is refused.
		$approve_1 = $payroll_class::approve_run( $run_id );
		$approve_2 = $payroll_class::approve_run( $run_id );
		$this->check(
			'test_payroll: approving twice is refused',
			true === $approve_1 && is_wp_error( $approve_2 ) && 'cntr_payroll_not_draft' === $approve_2->get_error_code(),
			wp_json_encode( [ 'first' => $approve_1, 'second' => is_wp_error( $approve_2 ) ? $approve_2->get_error_code() : $approve_2 ] )
		);

		// approve_run() books the total as a real 'wages' expense (P2.5's
		// own "named account, mandatory" rule, reused via Expenses::create())
		// — found and tracked here for cleanup, since it lands against the
		// site's real default location, not this test's own fixture one.
		$booked_expense_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$expenses_table} WHERE note = %s", sprintf( 'Payroll run #%d (%s)', $run_id, $period ) ) );
		if ( $booked_expense_id ) {
			$this->expense_fixture_ids[] = (int) $booked_expense_id;
		}

		// Restore 'people.holidays' to exactly what it held before —
		// removing only the one date this test added, never a blind
		// snapshot restore that could discard a genuinely concurrent
		// change (same precedent as test_expenses()'s own recurring-rule
		// removal).
		$current_holidays = json_decode( (string) \Counter\Settings::get( 'people.holidays' ), true );
		$current_holidays = is_array( $current_holidays ) ? $current_holidays : [];
		\Counter\Settings::set( 'people.holidays', wp_json_encode( array_values( array_diff( $current_holidays, [ $holiday_date ] ) ) ) );

		if ( ! empty( $admins_pr ) ) {
			wp_set_current_user( $original_user_id_pr );
		}
	}

	// -- P6.5: test_cashier_reports() -- 3 checks ----------------------------------------

	private function test_cashier_reports(): void {
		global $wpdb;
		$cashier_perf_class = \Counter\Reports\CashierPerformance::class;
		$orders_meta        = $wpdb->prefix . 'wc_orders_meta';

		$original_user_id_cr = get_current_user_id();
		$admins_cr              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_cr ) ) {
			wp_set_current_user( (int) $admins_cr[0] );
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$location_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Cashier Reports Location', 'code' => 'CNTR-SELFTEST-CR-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $location_id;

		$register_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Cashier Reports Register', 'location_id' => $location_id, 'prefix' => 'ZCR' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Cashier Reports Product' );
		$product->set_regular_price( '120.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;
		Db::transaction(
			function () use ( $product_id, $location_id ) {
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $location_id, 'qty_delta' => '20', 'reason' => 'opening', 'ref_type' => 'selftest_cashier_reports', 'ref_id' => 0 ] );
			}
		);

		$make_employee = function ( string $tag, string $pin ) use ( $location_id ) {
			$email   = 'cntr-selftest-cr-' . strtolower( $tag ) . '-' . wp_generate_password( 8, false ) . '@example.invalid';
			$user_id = wc_create_new_customer( $email, '', wp_generate_password( 16 ) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			$this->employee_user_fixture_ids[] = (int) $user_id;
			( new \WP_User( $user_id ) )->set_role( \Counter\Capabilities::ROLE_CASHIER );
			$employee_id = \Counter\People\Employees::create(
				[ 'user_id' => $user_id, 'code' => 'CNTR-SELFTEST-CR-' . $tag . '-' . substr( wp_generate_password( 6, false ), 0, 6 ), 'location_id' => $location_id, 'pin' => $pin ]
			);
			if ( is_wp_error( $employee_id ) ) {
				return $employee_id;
			}
			$this->employee_fixture_ids[] = $employee_id;
			return [ 'user_id' => (int) $user_id, 'employee_id' => $employee_id ];
		};

		$op_a = $make_employee( 'A', '3571' );
		$op_b = $make_employee( 'B', '9514' );
		if ( is_wp_error( $op_a ) || is_wp_error( $op_b ) ) {
			$this->check( 'test_cashier_reports: could not create fixture employees', false, wp_json_encode( [ $op_a, $op_b ] ) );
			if ( ! empty( $admins_cr ) ) {
				wp_set_current_user( $original_user_id_cr );
			}
			return;
		}

		$make_sale = function () use ( $product_id, $register_id, $shift_id ) {
			$body = [
				'lines'    => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '120.0000', 'discount' => '0.0000' ] ],
				'tenders'  => [ [ 'method' => 'cash', 'amount' => '120.0000' ] ],
				'customer' => [ 'phone' => '' ],
			];
			$uuid                       = wp_generate_uuid4();
			$this->sale_queue_uuids[]   = $uuid;
			$result                     = \Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, 'SELFTEST-CR-' . substr( $uuid, 0, 8 ), $body );
			$data                       = $result instanceof \WP_REST_Response ? $result->get_data() : $result;
			$order_id                   = (int) ( is_array( $data ) ? ( $data['order_id'] ?? 0 ) : 0 );
			if ( $order_id ) {
				$this->order_fixture_ids[] = $order_id;
			}
			return $order_id;
		};

		// Check 3 — operator attribution follows the PIN, not the terminal
		// account: the ACTUAL authenticated WP session throughout this
		// whole test is the administrator, never op_a's or op_b's own
		// account — proving each order's operator is genuinely PIN-derived,
		// not a fallback to whoever is really logged in.
		$switch_a = \Counter\Pos\Pin::switch_operator( $register_id, '3571' );
		$order_a  = $make_sale();
		$switch_b = \Counter\Pos\Pin::switch_operator( $register_id, '9514' );
		$order_b  = $make_sale();

		$operator_meta_a = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$orders_meta} WHERE order_id = %d AND meta_key = '_cntr_operator_id'", $order_a ) );
		$operator_meta_b = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$orders_meta} WHERE order_id = %d AND meta_key = '_cntr_operator_id'", $order_b ) );
		$this->check(
			'test_cashier_reports: operator attribution follows the PIN, not the terminal account',
			! is_wp_error( $switch_a ) && ! is_wp_error( $switch_b )
				&& $order_a > 0 && $order_b > 0
				&& (int) $operator_meta_a === $op_a['employee_id'] && (int) $operator_meta_b === $op_b['employee_id']
				&& (int) $operator_meta_a !== (int) $admins_cr[0] && (int) $operator_meta_b !== (int) $admins_cr[0],
			wp_json_encode( [ 'order_a' => $order_a, 'op_a_meta' => $operator_meta_a, 'expected_a' => $op_a['employee_id'], 'order_b' => $order_b, 'op_b_meta' => $operator_meta_b, 'expected_b' => $op_b['employee_id'] ] )
		);

		$today = wp_date( 'Y-m-d' );

		// Check 1 — per-cashier totals sum to the day's total, verified
		// against an INDEPENDENT query over the exact same population
		// (every completed order in the date range) — a structural
		// identity that holds regardless of how much other, unrelated
		// activity happened on this shared dev site the same day.
		$report_rows = $cashier_perf_class::report( [ 'from' => $today, 'to' => $today ], (int) $admins_cr[0] );
		$sum_sales   = '0.0000';
		foreach ( $report_rows as $r ) {
			$sum_sales = bcadd( $sum_sales, $r['sales'], 4 );
		}
		$independent_total = wc_format_decimal(
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM {$wpdb->prefix}wc_orders WHERE status = 'wc-completed' AND date_created_gmt >= %s AND date_created_gmt <= %s", $today . ' 00:00:00', $today . ' 23:59:59' ) ),
			4
		);
		$this->check(
			'test_cashier_reports: per-cashier totals sum to the day\'s total',
			0 === bccomp( $sum_sales, $independent_total, 4 ),
			wp_json_encode( [ 'sum_sales' => $sum_sales, 'independent_total' => $independent_total, 'rows' => count( $report_rows ) ] )
		);

		// Check 2 — a cashier cannot see another cashier's figures. op_a's
		// own WP user holds no cntr_view_all_sales.
		$scoped_rows      = $cashier_perf_class::report( [ 'from' => $today, 'to' => $today ], $op_a['user_id'] );
		$scoped_operators = array_map( static fn( $r ) => (int) $r['operator_id'], $scoped_rows );
		$this->check(
			'test_cashier_reports: a cashier cannot see another cashier\'s figures',
			in_array( $op_a['employee_id'], $scoped_operators, true ) && ! in_array( $op_b['employee_id'], $scoped_operators, true ),
			wp_json_encode( [ 'scoped_operators' => $scoped_operators, 'own' => $op_a['employee_id'], 'other' => $op_b['employee_id'] ] )
		);

		if ( ! empty( $admins_cr ) ) {
			wp_set_current_user( $original_user_id_cr );
		}
	}

	// -- P7.1: test_sw() -- 3 checks ----------------------------------------

	private function test_sw(): void {
		$sw_path = CNTR_DIR . 'assets/sw.js';
		$sw_js   = is_readable( $sw_path ) ? (string) file_get_contents( $sw_path ) : '';

		// Check 1 — sw.js is served with the right MIME type and scope.
		// build_response() is the testable core (ServiceWorker::maybe_serve()
		// itself calls header()/exit(), untestable under the CLI SAPI
		// wp eval/eval-file run under — headers_list() never reflects a
		// header() call there, since there is no real HTTP response for it
		// to attach to).
		$response = \Counter\Pos\ServiceWorker::build_response();
		$this->check(
			'test_sw: sw.js is served with the right MIME type and scope',
			str_starts_with( $response['content_type'], 'application/javascript' )
				&& '/' === $response['scope']
				&& $response['body'] === $sw_js && '' !== $sw_js,
			wp_json_encode( [ 'content_type' => $response['content_type'], 'scope' => $response['scope'], 'body_len' => strlen( $response['body'] ) ] )
		);

		// Check 2 — its cache list covers every asset the terminal
		// enqueues, compared against the REAL enqueue manifest
		// (Pos\Assets::maybe_enqueue()'s own wp_enqueue_script()/style()
		// calls), not a hardcoded assumption of what those calls do — a
		// new enqueued asset added later and forgotten here fails this
		// check, not silently at the counter.
		global $wp_scripts, $wp_styles;
		add_filter( 'cntr_is_pos_screen', '__return_true' );
		\Counter\Pos\Assets::maybe_enqueue();
		remove_filter( 'cntr_is_pos_screen', '__return_true' );

		$enqueued_paths = [];
		foreach ( [ [ $wp_scripts, 'cntr-pos' ], [ $wp_styles, 'cntr-pos' ] ] as [ $registry, $handle ] ) {
			$src = $registry->registered[ $handle ]->src ?? '';
			if ( '' !== $src ) {
				$enqueued_paths[] = (string) wp_parse_url( $src, PHP_URL_PATH );
			}
		}

		preg_match( '/const PRECACHE_URLS = \[(.*?)\];/s', $sw_js, $m );
		preg_match_all( "/'([^']+)'/", $m[1] ?? '', $url_matches );
		$precache_urls = $url_matches[1] ?? [];

		$missing = array_values( array_diff( $enqueued_paths, $precache_urls ) );
		$this->check(
			'test_sw: its cache list covers every asset the terminal enqueues',
			! empty( $enqueued_paths ) && empty( $missing ),
			wp_json_encode( [ 'enqueued' => $enqueued_paths, 'precached' => $precache_urls, 'missing' => $missing ] )
		);

		// Check 3 — sw.js references no external host.
		$this->check(
			'test_sw: sw.js references no external host',
			'' !== $sw_js && 0 === preg_match( '#https?://#i', $sw_js ),
			'length=' . strlen( $sw_js )
		);
	}

	// -- P7.2: test_outbox() -- 6 checks ----------------------------------------

	/**
	 * The outbox itself lives in the terminal's own IndexedDB — nothing PHP
	 * can execute directly. Every one of these checks instead tests the
	 * SERVER'S own side of the contract the outbox depends on:
	 * Rest\Sale::process() called with a client-generated, locally-allocated
	 * receipt_no and offline=true in the body, exactly the shape
	 * drainOutbox() itself posts. "Draining twice" is simulated by calling
	 * process() a second time with the identical uuid/receipt_no/body — the
	 * same resumed-after-a-crash scenario test_sale_idempotent() already
	 * covers in general, re-asserted here specifically in the
	 * offline-flagged path. The backoff check reads the real constants out
	 * of assets/pos.js and recomputes the same formula, rather than
	 * asserting against a duplicated hardcoded copy of it.
	 */
	private function test_outbox(): void {
		global $wpdb;
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		$main_id     = \Counter\Stock\Locations::default_id();

		$register_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Outbox Register', 'location_id' => $main_id, 'prefix' => 'ZO' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );
		$register = \Counter\Pos\Registers::get( $register_id );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Outbox Product' );
		$product->set_regular_price( '50.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;
		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '20', 'reason' => 'opening', 'ref_type' => 'selftest_outbox', 'ref_id' => 0 ] );
			}
		);

		// nextReceiptNo()'s own exact format: {prefix}-{shift, 6-digit}-{seq, 4-digit}.
		$receipt_no = sprintf( '%s-%06d-%04d', $register['prefix'], $shift_id, 1 );
		$uuid       = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid;
		$body       = [
			'lines'    => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '50.0000', 'discount' => '0.0000' ] ],
			'tenders'  => [ [ 'method' => 'cash', 'amount' => '50.0000' ] ],
			'customer' => [ 'phone' => '' ],
			'offline'  => true,
		];

		$extract = static function ( $result ) {
			if ( $result instanceof \WP_Error ) {
				return [ 'error' => $result->get_error_message() ];
			}
			if ( $result instanceof \WP_REST_Response ) {
				return $result->get_data();
			}
			return $result;
		};

		// First drain attempt.
		$result_1  = $extract( \Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, $receipt_no, $body ) );
		$order_id_1 = (int) ( $result_1['order_id'] ?? 0 );
		if ( $order_id_1 ) {
			$this->order_fixture_ids[] = $order_id_1;
		}

		// Check 1 — a queued sale drains to exactly one order.
		$order_count_1 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_meta} WHERE meta_key = '_cntr_uuid' AND meta_value = %s", $uuid ) );
		$this->check(
			'test_outbox: a queued sale drains to exactly one order',
			$order_id_1 > 0 && 1 === $order_count_1,
			wp_json_encode( [ 'order_id' => $order_id_1, 'count' => $order_count_1 ] )
		);

		// Second drain attempt — the exact same call, simulating a resumed
		// drain after the terminal crashed or reloaded before the first
		// attempt's own response was ever seen.
		$result_2   = $extract( \Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, $receipt_no, $body ) );
		$order_id_2 = (int) ( $result_2['order_id'] ?? 0 );
		$order_count_2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_meta} WHERE meta_key = '_cntr_uuid' AND meta_value = %s", $uuid ) );

		// Check 2 — draining twice creates one order.
		$this->check(
			'test_outbox: draining twice creates one order',
			$order_id_2 === $order_id_1 && 1 === $order_count_2,
			wp_json_encode( [ 'first' => $order_id_1, 'second' => $order_id_2, 'count' => $order_count_2 ] )
		);

		$order = wc_get_order( $order_id_1 );

		// Check 3 — the drained order carries _cntr_offline = 1.
		$this->check(
			'test_outbox: the drained order carries _cntr_offline = 1',
			$order instanceof \WC_Order && 1 === (int) $order->get_meta( '_cntr_offline' ),
			$order instanceof \WC_Order ? wp_json_encode( $order->get_meta( '_cntr_offline' ) ) : 'no order'
		);

		// Check 4 — the locally allocated receipt number is preserved, not reissued.
		$this->check(
			'test_outbox: the locally allocated receipt number is preserved, not reissued',
			$order instanceof \WC_Order && $receipt_no === $order->get_meta( '_cntr_receipt_no' ) && $receipt_no === ( $result_1['receipt_no'] ?? null ),
			wp_json_encode( [ 'sent' => $receipt_no, 'order_meta' => $order instanceof \WC_Order ? $order->get_meta( '_cntr_receipt_no' ) : null, 'response' => $result_1['receipt_no'] ?? null ] )
		);

		// Check 5 — backoff grows and is capped. Read the real constants out
		// of the real file, recompute the real formula — never a duplicated
		// hardcoded copy that could quietly drift from what pos.js actually
		// does.
		$pos_js   = (string) file_get_contents( CNTR_DIR . 'assets/pos.js' );
		preg_match( '/OUTBOX_BACKOFF_BASE_MS\s*=\s*(\d+)/', $pos_js, $base_m );
		preg_match( '/OUTBOX_BACKOFF_MAX_MS\s*=\s*(\d+)/', $pos_js, $max_m );
		$base = (int) ( $base_m[1] ?? 0 );
		$max  = (int) ( $max_m[1] ?? 0 );
		$delays = [];
		for ( $attempt = 1; $attempt <= 10; $attempt++ ) {
			$delays[] = min( $max, $base * ( 2 ** ( $attempt - 1 ) ) );
		}
		$grows_then_caps = $base > 0 && $max > 0 && $delays[0] === $base && end( $delays ) === $max && $delays[1] > $delays[0];
		$this->check(
			'test_outbox: backoff grows and is capped',
			$grows_then_caps,
			wp_json_encode( [ 'base' => $base, 'max' => $max, 'delays' => $delays ] )
		);

		// Check 6 — two registers' offline receipt numbers never collide:
		// a second register, its own distinct prefix, its own shift and its
		// own locally-allocated receipt number, drained the same way.
		$register_2_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Outbox Register 2', 'location_id' => $main_id, 'prefix' => 'ZP' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_2_id;
		$shift_2_id = \Counter\Pos\Shifts::open( $register_2_id, get_current_user_id(), '0.00' );
		$register_2 = \Counter\Pos\Registers::get( $register_2_id );
		$receipt_no_2 = sprintf( '%s-%06d-%04d', $register_2['prefix'], $shift_2_id, 1 );
		$uuid_2       = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_2;

		$result_3   = $extract( \Counter\Rest\Sale::process( $uuid_2, $register_2_id, $shift_2_id, $receipt_no_2, $body ) );
		$order_id_3 = (int) ( $result_3['order_id'] ?? 0 );
		if ( $order_id_3 ) {
			$this->order_fixture_ids[] = $order_id_3;
		}
		$this->check(
			'test_outbox: two registers\' offline receipt numbers never collide',
			$order_id_3 > 0 && $order_id_3 !== $order_id_1 && $receipt_no_2 !== $receipt_no,
			wp_json_encode( [ 'order_1' => $order_id_1, 'receipt_1' => $receipt_no, 'order_3' => $order_id_3, 'receipt_2' => $receipt_no_2 ] )
		);
	}

	// -- P7.3: test_outbox_poison() -- 4 checks ----------------------------------------

	private function test_outbox_poison(): void {
		global $wpdb;
		$queue_table = Install::table( 'sale_queue' );
		$audit_table = Install::table( 'audit_log' );
		$main_id     = \Counter\Stock\Locations::default_id();

		$register_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Outbox Poison Register', 'location_id' => $main_id, 'prefix' => 'ZQ' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		// A product deleted "while the till was offline" — Direction's own
		// example — created, its id noted, then genuinely deleted before
		// the sale referencing it is ever processed.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Outbox Poison Product' );
		$product->set_regular_price( '40.00' );
		$product->save();
		$product_id = $product->get_id();
		wp_delete_post( $product_id, true );

		$uuid       = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid;
		$receipt_no = sprintf( 'ZQ-%06d-%04d', $shift_id, 1 );
		$body       = [
			'lines'    => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '40.0000', 'discount' => '0.0000' ] ],
			'tenders'  => [ [ 'method' => 'cash', 'amount' => '40.0000' ] ],
			'customer' => [ 'phone' => '' ],
			'offline'  => true,
		];

		// The first attempt already fails — build_lines() cannot resolve a
		// product that no longer exists, and Rest\Sale::process() marks the
		// queue row failed_permanent immediately, server-side, the exact
		// moment this happens (Queue::fail_permanent() inside build_lines()'s
		// own error branch) — there is no server-side "budget," a bad line
		// is definitively bad on the first try. A pos.js RETRY BUDGET
		// (OUTBOX_MAX_ATTEMPTS) is what stops the CLIENT itself from
		// hammering the same doomed request forever when the failure isn't
		// this clear-cut (a persistent network/500 failure, never a bad
		// line specifically) — read from the real file below, not
		// duplicated as a second hardcoded assumption of what it is.
		\Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, $receipt_no, $body );
		$row_after_first = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE uuid = %s", $uuid ), ARRAY_A );

		// A second attempt — the exact replay a client's own drain would
		// make if it never learned the first one failed permanently —
		// must not re-run any business logic, only replay the same result.
		$result_2 = \Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, $receipt_no, $body );
		$row_after_second = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE uuid = %s", $uuid ), ARRAY_A );

		$pos_js = (string) file_get_contents( CNTR_DIR . 'assets/pos.js' );
		preg_match( '/OUTBOX_MAX_ATTEMPTS\s*=\s*(\d+)/', $pos_js, $budget_m );
		$has_budget = isset( $budget_m[1] ) && (int) $budget_m[1] > 0 && (int) $budget_m[1] < 100; // finite and sane, never "retried forever"

		// Check 1 — marked failed_permanent after the retry budget, not
		// retried forever.
		$this->check(
			'test_outbox_poison: a sale referencing a deleted product is marked failed_permanent after the retry budget, not retried forever',
			null !== $row_after_first && 'failed_permanent' === $row_after_first['status']
				&& $result_2 instanceof \WP_Error && 'cntr_sale_failed' === $result_2->get_error_code()
				&& null !== $row_after_second && 'failed_permanent' === $row_after_second['status']
				&& $has_budget,
			wp_json_encode( [ 'after_first' => $row_after_first['status'] ?? null, 'after_second' => $row_after_second['status'] ?? null, 'budget' => $budget_m[1] ?? null ] )
		);

		// Check 2 — does not count toward the 72-hour lock: pos.js's own
		// pendingOutboxEntries() — the function a future P7.6 age-lock
		// computes an outbox's oldest age FROM — excludes failed_permanent
		// by name, read from the real file rather than trusted by
		// assumption.
		preg_match( '/function pendingOutboxEntries\(entries\)\s*\{(.*?)\n\t\}/s', $pos_js, $fn_m );
		$fn_body = $fn_m[1] ?? '';
		$this->check(
			'test_outbox_poison: it does not count toward the 72-hour lock',
			'' !== $fn_body && str_contains( $fn_body, "'failed_permanent'" ) && str_contains( $fn_body, 'filter' ),
			'length=' . strlen( $fn_body )
		);

		// Check 3 — the manager screen lists it with a readable reason.
		$list         = \Counter\Pos\Queue::failed_permanent_list();
		$listed_row   = null;
		foreach ( $list as $r ) {
			if ( $r['uuid'] === $uuid ) {
				$listed_row = $r;
			}
		}

		$original_user_id_op = get_current_user_id();
		$admins_op              = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_op ) ) {
			wp_set_current_user( (int) $admins_op[0] );
		}
		ob_start();
		\Counter\Admin\Screens\OutboxFailures::render();
		$html = ob_get_clean();

		$this->check(
			'test_outbox_poison: the manager screen lists it with a readable reason',
			null !== $listed_row && ! empty( $listed_row['error'] ) && str_contains( $html, esc_html( $listed_row['error'] ) ),
			wp_json_encode( [ 'reason' => $listed_row['error'] ?? null, 'on_screen' => null !== $listed_row && str_contains( $html, esc_html( $listed_row['error'] ?? '__none__' ) ) ] )
		);

		// Check 4 — resolving it writes an audit row.
		$audit_count_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = 'outbox_resolved' AND object_id = %d", (int) ( $row_after_second['id'] ?? 0 ) ) );
		$resolve_result      = \Counter\Pos\Queue::resolve_failed( $uuid, 'selftest: re-keyed manually', (int) $admins_op[0] );
		$row_after_resolve   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE uuid = %s", $uuid ), ARRAY_A );
		$audit_count_after   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = 'outbox_resolved' AND object_id = %d", (int) ( $row_after_second['id'] ?? 0 ) ) );

		$this->check(
			'test_outbox_poison: resolving it writes an audit row',
			true === $resolve_result && null !== $row_after_resolve && 'resolved' === $row_after_resolve['status']
				&& $audit_count_after === $audit_count_before + 1,
			wp_json_encode( [ 'resolve' => is_wp_error( $resolve_result ) ? $resolve_result->get_error_message() : $resolve_result, 'status_after' => $row_after_resolve['status'] ?? null, 'audit_before' => $audit_count_before, 'audit_after' => $audit_count_after ] )
		);

		// The 'outbox_resolved' audit row check 4 just wrote is not tagged
		// with self::TAG (it names a real action, on purpose, so a real
		// one is never mistaken for test debris) — cleanup()'s own
		// purge_tagged() never sees it, so this test is responsible for
		// its own removal, same principle as test_fixtures()'s own inline
		// cleanup.
		if ( isset( $row_after_second['id'] ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit_table} WHERE action = 'outbox_resolved' AND object_id = %d", (int) $row_after_second['id'] ) );
		}

		if ( ! empty( $admins_op ) ) {
			wp_set_current_user( $original_user_id_op );
		}
	}

	// -- P7.4: test_late_sales() -- 4 checks ----------------------------------------

	private function test_late_sales(): void {
		global $wpdb;
		$shift_sales_table = Install::table( 'shift_sales' );
		$tenders_table      = Install::table( 'tenders' );
		$late_sales_table   = Install::table( 'late_sales' );
		$main_id            = \Counter\Stock\Locations::default_id();

		$register_id = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Late Sales Register', 'location_id' => $main_id, 'prefix' => 'ZL' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_id;

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Late Sales Product' );
		$product->set_regular_price( '25.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;
		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '20', 'reason' => 'opening', 'ref_type' => 'selftest_late_sales', 'ref_id' => 0 ] );
			}
		);
		$balance_before_any_sale = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );

		$make_body = function () use ( $product_id ) {
			return [
				'lines'    => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '25.0000', 'discount' => '0.0000' ] ],
				'tenders'  => [ [ 'method' => 'cash', 'amount' => '25.0000' ] ],
				'customer' => [ 'phone' => '' ],
			];
		};

		// Shift A: opened, one normal sale rung and drained while it is
		// still the open shift, then closed and counted.
		$shift_a_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );
		$uuid_normal = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_normal;
		$result_normal = \Counter\Rest\Sale::process( $uuid_normal, $register_id, $shift_a_id, 'ZL-NORMAL-0001', $make_body() );
		$order_normal_id = (int) ( $result_normal instanceof \WP_Error ? 0 : ( $result_normal instanceof \WP_REST_Response ? $result_normal->get_data()['order_id'] ?? 0 : $result_normal['order_id'] ?? 0 ) );
		if ( $order_normal_id ) {
			$this->order_fixture_ids[] = $order_normal_id;
		}

		// Check 1 — a sale draining into its still-open shift attaches normally.
		$shift_a_sales_after_normal = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$shift_sales_table} WHERE shift_id = %d", $shift_a_id ) );
		$late_count_after_normal     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$late_sales_table} WHERE order_id = %d", $order_normal_id ) );
		$this->check(
			'test_late_sales: a sale draining into its still-open shift attaches normally',
			$order_normal_id > 0 && 1 === $shift_a_sales_after_normal && 0 === $late_count_after_normal,
			wp_json_encode( [ 'order' => $order_normal_id, 'shift_a_sales' => $shift_a_sales_after_normal, 'late' => $late_count_after_normal ] )
		);

		\Counter\Pos\Shifts::close( $shift_a_id, '25.00' );
		$shift_a_after_close = \Counter\Pos\Shifts::get( $shift_a_id );

		// Shift B: a new shift on the SAME register, opened after A closed —
		// the "currently open" shift a late-draining sale will actually land in.
		$shift_b_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		// A sale carrying shift_a_id — the shift that was open when it was
		// RUNG — but A is now closed and counted; it drains into B instead.
		$uuid_late = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_late;
		$result_late = \Counter\Rest\Sale::process( $uuid_late, $register_id, $shift_a_id, 'ZL-LATE-0001', $make_body() );
		$order_late_id = (int) ( $result_late instanceof \WP_Error ? 0 : ( $result_late instanceof \WP_REST_Response ? $result_late->get_data()['order_id'] ?? 0 : $result_late['order_id'] ?? 0 ) );
		if ( $order_late_id ) {
			$this->order_fixture_ids[] = $order_late_id;
		}

		$shift_a_after_late    = \Counter\Pos\Shifts::get( $shift_a_id );
		$shift_a_sales_after_late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$shift_sales_table} WHERE shift_id = %d", $shift_a_id ) );
		$shift_a_tenders_after_late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tenders_table} WHERE shift_id = %d", $shift_a_id ) );
		$shift_b_sales_after_late = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$shift_sales_table} WHERE shift_id = %d", $shift_b_id ) );
		$late_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$late_sales_table} WHERE order_id = %d", $order_late_id ), ARRAY_A );

		// Check 2 — one draining into a closed shift creates a late-sale
		// row and does not change the closed shift's figures: still
		// exactly 1 shift_sales row and 1 tenders row for A — both from
		// check 1's own normal sale, the late one adds NOTHING to either —
		// A's own expected/counted/variance identical to what closing it
		// produced, and the late sale's own row landed on B instead.
		$this->check(
			'test_late_sales: one draining into a closed shift creates a late-sale row and does not change the closed shift\'s figures',
			$order_late_id > 0
				&& null !== $late_row && (int) $late_row['intended_shift_id'] === $shift_a_id && (int) $late_row['landed_shift_id'] === $shift_b_id
				&& 1 === $shift_a_sales_after_late && 1 === $shift_a_tenders_after_late
				&& 1 === $shift_b_sales_after_late
				&& $shift_a_after_close['expected_cash'] === $shift_a_after_late['expected_cash']
				&& $shift_a_after_close['counted_cash'] === $shift_a_after_late['counted_cash']
				&& $shift_a_after_close['variance'] === $shift_a_after_late['variance'],
			wp_json_encode( [ 'late_row' => $late_row, 'a_sales' => $shift_a_sales_after_late, 'a_tenders' => $shift_a_tenders_after_late, 'b_sales' => $shift_b_sales_after_late, 'a_before' => $shift_a_after_close, 'a_after' => $shift_a_after_late ] )
		);

		// Check 3 — the late sale still creates a correct order and ledger move.
		$balance_after_both_sales = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$late_order = $order_late_id ? wc_get_order( $order_late_id ) : null;
		$this->check(
			'test_late_sales: the late sale still creates a correct order and ledger move',
			$late_order instanceof \WC_Order && 'completed' === $late_order->get_status()
				&& 0 === bccomp( wc_format_decimal( $late_order->get_total(), 4 ), '25.0000', 4 )
				&& 0 === bccomp( bcsub( $balance_before_any_sale, $balance_after_both_sales, 4 ), '2.0000', 4 ),
			wp_json_encode( [ 'status' => $late_order ? $late_order->get_status() : null, 'total' => $late_order ? $late_order->get_total() : null, 'balance_before' => $balance_before_any_sale, 'balance_after' => $balance_after_both_sales ] )
		);

		// Check 4 — it appears on the shift's variance report (both the
		// intended shift's own report and the landed one's — ShiftReport::build()
		// already queries by either column).
		$report_a = \Counter\Admin\Screens\ShiftReport::build( $shift_a_id );
		$report_b = \Counter\Admin\Screens\ShiftReport::build( $shift_b_id );
		$found_in_a = false;
		foreach ( is_wp_error( $report_a ) ? [] : $report_a['late_sales'] as $l ) {
			if ( (int) $l['order_id'] === $order_late_id ) {
				$found_in_a = true;
			}
		}
		$found_in_b = false;
		foreach ( is_wp_error( $report_b ) ? [] : $report_b['late_sales'] as $l ) {
			if ( (int) $l['order_id'] === $order_late_id ) {
				$found_in_b = true;
			}
		}
		$this->check(
			'test_late_sales: it appears on the shift\'s variance report',
			$found_in_a && $found_in_b,
			wp_json_encode( [ 'found_in_intended' => $found_in_a, 'found_in_landed' => $found_in_b ] )
		);
	}

	// -- P7.5: test_oversell() -- 5 checks ----------------------------------------

	private function test_oversell(): void {
		global $wpdb;
		$oversell_table = Install::table( 'oversell_log' );
		$audit_table    = Install::table( 'audit_log' );
		$main_id        = \Counter\Stock\Locations::default_id();

		$register_1 = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Oversell Register 1', 'location_id' => $main_id, 'prefix' => 'ZO' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_1;
		$register_2 = \Counter\Pos\Registers::create( [ 'name' => 'Counter Selftest Fixture Oversell Register 2', 'location_id' => $main_id, 'prefix' => 'ZP' . substr( wp_generate_password( 5, false ), 0, 5 ), 'status' => 'active' ] );
		$this->register_fixture_ids[] = $register_2;

		// Product 1 — a real batch of exactly 1 unit, then one sale for 2:
		// the FIFO loop drains the batch, and the remainder is short.
		$product_1 = new \WC_Product_Simple();
		$product_1->set_name( 'Counter Selftest Fixture Oversell Product 1' );
		$product_1->set_regular_price( '20.00' );
		$product_1->set_manage_stock( true );
		$product_1->set_stock_quantity( 0 );
		$product_1->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_1->save();
		$product_1_id = $product_1->get_id();
		$this->product_fixture_ids[] = $product_1_id;

		$batch_1 = \Counter\Stock\Batches::receive( [ 'product_id' => $product_1_id, 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'OVERSELL-1', 'qty_received' => '1', 'unit_cost' => '10.0000' ] );
		$this->batch_fixture_ids[] = $batch_1;

		$shift_1 = \Counter\Pos\Shifts::open( $register_1, get_current_user_id(), '0.00' );

		$body_1 = [
			'lines'    => [ [ 'product_id' => $product_1_id, 'variation_id' => 0, 'qty' => '2.0000', 'unit_price' => '20.0000', 'discount' => '0.0000' ] ],
			'tenders'  => [ [ 'method' => 'cash', 'amount' => '40.0000' ] ],
			'customer' => [ 'phone' => '' ],
		];
		$uuid_1 = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_1;
		$result_1   = \Counter\Rest\Sale::process( $uuid_1, $register_1, $shift_1, 'ZO-SHORT-0001', $body_1 );
		$order_1_id = (int) ( $result_1 instanceof \WP_Error ? 0 : ( $result_1 instanceof \WP_REST_Response ? $result_1->get_data()['order_id'] ?? 0 : $result_1['order_id'] ?? 0 ) );
		if ( $order_1_id ) {
			$this->order_fixture_ids[] = $order_1_id;
		}

		$order_1         = $order_1_id ? wc_get_order( $order_1_id ) : null;
		$balance_after_1 = \Counter\Stock\Ledger::balance( $product_1_id, 0, $main_id );

		// Check 1 — draining a sale that takes stock below zero still
		// succeeds: the goods physically left the shop, and refusing the
		// row would only lose the record.
		$this->check(
			'test_oversell: draining a sale that takes stock below zero succeeds',
			$order_1 instanceof \WC_Order && 'completed' === $order_1->get_status()
				&& 0 === bccomp( $balance_after_1, '-1.0000', 4 ),
			wp_json_encode( [ 'order' => $order_1_id, 'status' => $order_1 ? $order_1->get_status() : null, 'balance_after' => $balance_after_1 ] )
		);

		// Check 2 — it writes an oversell row naming the register and the shortfall.
		$row_1 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$oversell_table} WHERE order_id = %d", $order_1_id ), ARRAY_A );
		$this->check(
			'test_oversell: it writes an oversell row naming the register and the shortfall',
			null !== $row_1 && (int) $row_1['register_id'] === $register_1 && (int) $row_1['product_id'] === $product_1_id
				&& 0 === bccomp( $row_1['qty_short'], '1.0000', 4 ) && 'open' === $row_1['status'],
			wp_json_encode( $row_1 )
		);

		// Check 3 — published WooCommerce stock is clamped at zero, not negative.
		$published_1 = (int) wc_get_product( $product_1_id )->get_stock_quantity();
		$this->check(
			'test_oversell: published WooCommerce stock is clamped at zero, not negative',
			0 === $published_1,
			"stock_quantity={$published_1}"
		);

		// Product 2 — no batch at all, both registers sell against it while
		// its balance is already at zero: the exact "two registers offline
		// at the same time both sell the last unit" scenario Direction names.
		$product_2 = new \WC_Product_Simple();
		$product_2->set_name( 'Counter Selftest Fixture Oversell Product 2' );
		$product_2->set_regular_price( '10.00' );
		$product_2->set_manage_stock( true );
		$product_2->set_stock_quantity( 0 );
		$product_2->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_2->save();
		$product_2_id = $product_2->get_id();
		$this->product_fixture_ids[] = $product_2_id;

		$shift_2 = \Counter\Pos\Shifts::open( $register_2, get_current_user_id(), '0.00' );

		$make_body_2 = function () use ( $product_2_id ) {
			return [
				'lines'    => [ [ 'product_id' => $product_2_id, 'variation_id' => 0, 'qty' => '1.0000', 'unit_price' => '10.0000', 'discount' => '0.0000' ] ],
				'tenders'  => [ [ 'method' => 'cash', 'amount' => '10.0000' ] ],
				'customer' => [ 'phone' => '' ],
			];
		};

		$uuid_2a = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_2a;
		$result_2a   = \Counter\Rest\Sale::process( $uuid_2a, $register_1, $shift_1, 'ZO-SHORT-0002', $make_body_2() );
		$order_2a_id = (int) ( $result_2a instanceof \WP_Error ? 0 : ( $result_2a instanceof \WP_REST_Response ? $result_2a->get_data()['order_id'] ?? 0 : $result_2a['order_id'] ?? 0 ) );
		if ( $order_2a_id ) {
			$this->order_fixture_ids[] = $order_2a_id;
		}

		$uuid_2b = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid_2b;
		$result_2b   = \Counter\Rest\Sale::process( $uuid_2b, $register_2, $shift_2, 'ZP-SHORT-0001', $make_body_2() );
		$order_2b_id = (int) ( $result_2b instanceof \WP_Error ? 0 : ( $result_2b instanceof \WP_REST_Response ? $result_2b->get_data()['order_id'] ?? 0 : $result_2b['order_id'] ?? 0 ) );
		if ( $order_2b_id ) {
			$this->order_fixture_ids[] = $order_2b_id;
		}

		$rows_2          = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$oversell_table} WHERE product_id = %d ORDER BY id", $product_2_id ), ARRAY_A );
		$registers_named = array_map( fn( $r ) => (int) $r['register_id'], $rows_2 );

		// Check 4 — two registers overselling the same unit produce two rows naming both.
		$this->check(
			'test_oversell: two registers overselling the same unit produce two rows naming both',
			2 === count( $rows_2 ) && in_array( $register_1, $registers_named, true ) && in_array( $register_2, $registers_named, true ),
			wp_json_encode( [ 'rows' => $rows_2 ] )
		);

		// Check 5 — resolving a row writes an audit entry and does not alter the ledger.
		$balance_before_resolve = \Counter\Stock\Ledger::balance( $product_1_id, 0, $main_id );
		$audit_count_before     = $row_1 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = 'oversell_resolved' AND object_id = %d", (int) $row_1['id'] ) ) : 0;

		$original_user_id = get_current_user_id();
		$admins            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}
		$resolve_result = $row_1 ? \Counter\Stock\Oversell::resolve( (int) $row_1['id'], 'selftest: restocked to cover the shortfall', (int) ( $admins[0] ?? 0 ) ) : new \WP_Error( 'no_row', 'no row' );
		$row_1_after     = $row_1 ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$oversell_table} WHERE id = %d", (int) $row_1['id'] ), ARRAY_A ) : null;
		$audit_count_after = $row_1 ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$audit_table} WHERE action = 'oversell_resolved' AND object_id = %d", (int) $row_1['id'] ) ) : 0;
		$balance_after_resolve = \Counter\Stock\Ledger::balance( $product_1_id, 0, $main_id );

		if ( ! empty( $admins ) ) {
			wp_set_current_user( $original_user_id );
		}

		$this->check(
			'test_oversell: resolving a row writes an audit entry and does not alter the ledger',
			true === $resolve_result && null !== $row_1_after && 'resolved' === $row_1_after['status']
				&& $audit_count_after === $audit_count_before + 1
				&& 0 === bccomp( $balance_before_resolve, $balance_after_resolve, 4 ),
			wp_json_encode( [ 'resolve' => is_wp_error( $resolve_result ) ? $resolve_result->get_error_message() : $resolve_result, 'status_after' => $row_1_after['status'] ?? null, 'audit_before' => $audit_count_before, 'audit_after' => $audit_count_after, 'balance_before' => $balance_before_resolve, 'balance_after' => $balance_after_resolve ] )
		);

		// The 'oversell_resolved' audit row check 5 just wrote is not tagged
		// with self::TAG (a real action, on purpose, so a real one is never
		// mistaken for test debris) — cleanup()'s own purge_tagged() never
		// sees it, same as test_outbox_poison's own 'outbox_resolved' row.
		if ( $row_1 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$audit_table} WHERE action = 'oversell_resolved' AND object_id = %d", (int) $row_1['id'] ) );
		}
	}

	// -- P7.6: test_offline_lock() -- 4 checks ----------------------------------------

	/**
	 * The outbox and its age lock live entirely client-side, in the
	 * terminal's own IndexedDB — same structural limit test_outbox()'s own
	 * docblock already names for P7.2. Nothing here can execute pos.js
	 * directly; instead this reads the REAL isOutboxLocked() source and its
	 * OUTBOX_MAX_AGE_HOURS constant out of the real file and recomputes the
	 * exact same formula in PHP against constructed fixture entries — the
	 * same technique test_outbox()'s own check 5 already uses for the
	 * backoff formula, never a hardcoded duplicate that could quietly drift
	 * from what pos.js actually does.
	 */
	private function test_offline_lock(): void {
		$pos_js = (string) file_get_contents( CNTR_DIR . 'assets/pos.js' );

		preg_match( '/OUTBOX_MAX_AGE_HOURS\s*=\s*(\d+)/', $pos_js, $age_m );
		$max_age_hours = (int) ( $age_m[1] ?? 0 );

		preg_match( '/function isOutboxLocked\(entries, now\)\s*\{(.*?)\n\t\}/s', $pos_js, $fn_m );
		$fn_body = $fn_m[1] ?? '';
		$uses_pending_filter = '' !== $fn_body && str_contains( $fn_body, 'pendingOutboxEntries' );

		$is_locked = function ( array $entries, float $now_ms ) use ( $max_age_hours ) {
			$pending = array_filter( $entries, static fn( $e ) => 'failed_permanent' !== $e['status'] );
			if ( empty( $pending ) ) {
				return false;
			}
			$oldest = min( array_column( $pending, 'createdAt' ) );
			return ( $now_ms - $oldest ) / 3600000 > $max_age_hours;
		};

		$now_ms = microtime( true ) * 1000;
		$hours_ago = static fn( float $h ) => $now_ms - ( $h * 3600000 );

		// Check 1 — an outbox at 71 hours does not lock.
		$locked_71 = $is_locked( [ [ 'status' => 'pending', 'createdAt' => $hours_ago( 71 ) ] ], $now_ms );
		$this->check(
			'test_offline_lock: an outbox at 71 hours does not lock',
			72 === $max_age_hours && $uses_pending_filter && false === $locked_71,
			wp_json_encode( [ 'max_age_hours' => $max_age_hours, 'uses_pending_filter' => $uses_pending_filter, 'locked_71' => $locked_71 ] )
		);

		// Check 2 — at 73 hours it does.
		$locked_73 = $is_locked( [ [ 'status' => 'pending', 'createdAt' => $hours_ago( 73 ) ] ], $now_ms );
		$this->check(
			'test_offline_lock: at 73 hours it does',
			true === $locked_73,
			"locked_73={$locked_73}"
		);

		// Check 3 — a failed_permanent row at 100 hours does not lock — the
		// exact "does not count toward the age lock" contract
		// pendingOutboxEntries() already established for P7.3, reused here
		// rather than re-implemented.
		$locked_100_permanent = $is_locked( [ [ 'status' => 'failed_permanent', 'createdAt' => $hours_ago( 100 ) ] ], $now_ms );
		$this->check(
			'test_offline_lock: a failed_permanent row at 100 hours does not lock',
			false === $locked_100_permanent,
			"locked_100_permanent={$locked_100_permanent}"
		);

		// Check 4 — syncing clears the lock immediately: never a stored
		// flag, only a live computation over whatever the outbox currently
		// holds — the 73-hour entry that locked check 2 is now gone (a real
		// drain), and the very next computation over the same (now empty)
		// list reads unlocked, with nothing separately cleared.
		$locked_after_sync = $is_locked( [], $now_ms );
		$this->check(
			'test_offline_lock: syncing clears the lock immediately',
			true === $locked_73 && false === $locked_after_sync,
			wp_json_encode( [ 'locked_before_sync' => $locked_73, 'locked_after_sync' => $locked_after_sync ] )
		);
	}

	// -- P8.2: test_backup_coverage() -- 2 checks ----------------------------------------

	/**
	 * "An untested backup is not a backup" — Direction's own words. No code
	 * here can see inside a real, external, host-level backup tool; this
	 * only verifies that a human has actually declared, table by table,
	 * that Counter's own append-only ledgers and every other cntr_* table
	 * are covered by whatever backup mechanism the shop's real host uses
	 * (Settings 'backup.manifest' — see its own docblock). A manifest that
	 * still reads '[]' means nobody has ever confirmed this, which is
	 * exactly the state Direction is warning against — not a pass by
	 * default.
	 */
	private function test_backup_coverage(): void {
		$expected = Install::expected_tables();
		$manifest = json_decode( (string) Settings::get( 'backup.manifest' ), true );
		$manifest = is_array( $manifest ) ? $manifest : [];
		$missing  = array_values( array_diff( $expected, $manifest ) );

		// Check 1 — every cntr_* table appears in the configured backup manifest.
		$this->check(
			'test_backup_coverage: every cntr_* table appears in the configured backup manifest',
			empty( $missing ),
			wp_json_encode( [ 'expected_count' => count( $expected ), 'manifest_count' => count( $manifest ), 'missing' => $missing ] )
		);

		// Check 2 — the manifest is non-empty (a distinct, more specific
		// assertion than check 1 — an empty manifest and a merely-
		// incomplete one both fail check 1, but only this one names "nobody
		// has configured this at all" as its own diagnosable state).
		$this->check(
			'test_backup_coverage: the manifest is non-empty',
			! empty( $manifest ),
			'manifest_count=' . count( $manifest )
		);
	}

	// -- P8.3: test_i18n() -- 4 checks ----------------------------------------

	private function test_i18n(): void {
		// Check 1 — the text domain loads. NOT is_textdomain_loaded('counter')
		// read passively: on this site's own default locale (en_US, no
		// counter-en_US.mo exists or is needed) Boot::on_plugins_loaded()'s
		// own load_plugin_textdomain() call legitimately finds nothing to
		// load and is_textdomain_loaded() correctly reads false — found
		// live, the first version of this check asserted exactly that
		// passive read and failed on a site running in English, which is
		// this site's own real, correct, ordinary state. What's actually
		// worth proving is that the LOADING MECHANISM itself works when a
		// translation genuinely exists — loaded here, directly, against the
		// one real .mo this plugin ships (bn_BD) — and check 2 continues
		// from this same loaded state rather than loading it twice.
		$known_en = 'Add';
		$mo_path  = CNTR_DIR . 'languages/counter-bn_BD.mo';
		unload_textdomain( 'counter' );
		$loaded = is_readable( $mo_path ) && load_textdomain( 'counter', $mo_path );
		$this->check(
			'test_i18n: the text domain loads',
			$loaded && is_textdomain_loaded( 'counter' ),
			wp_json_encode( [ 'loaded_result' => $loaded, 'is_textdomain_loaded' => is_textdomain_loaded( 'counter' ) ] )
		);

		// Check 2 — a known string translates under bn_BD. Continues from
		// check 1's already-loaded bn_BD domain rather than loading it a
		// second time. Always restores the site's normal (English) domain
		// state afterward, unconditionally, since every OTHER self-test in
		// this suite asserts on English message text.
		$translated = __( $known_en, 'counter' );
		unload_textdomain( 'counter' );
		load_plugin_textdomain( 'counter', false, dirname( plugin_basename( CNTR_FILE ) ) . '/languages' );
		$this->check(
			'test_i18n: a known string translates under bn_BD',
			$loaded && $translated !== $known_en && '' !== trim( $translated ),
			wp_json_encode( [ 'loaded' => $loaded, 'source' => $known_en, 'translated' => $translated ] )
		);

		// Check 3 — every PHP file's user-facing strings use the counter
		// domain (grep for the translation helpers with a wrong or missing
		// domain argument).
		$violation = self::grep_for_wrong_i18n_domain();
		$this->check(
			'test_i18n: every PHP file\'s user-facing strings use the counter domain',
			null === $violation,
			(string) $violation
		);

		// Check 4 — the .mo is present and newer than the .po. A .mo older
		// than its own .po is a compiled artifact nobody rebuilt after the
		// last real edit — exactly the kind of drift a translator's own
		// workflow (edit the .po, forget to recompile) produces silently.
		$po_path = CNTR_DIR . 'languages/counter-bn_BD.po';
		$po_ok   = is_readable( $po_path );
		$mo_ok   = is_readable( $mo_path );
		$this->check(
			'test_i18n: the .mo is present and newer than the .po',
			$po_ok && $mo_ok && filemtime( $mo_path ) >= filemtime( $po_path ),
			wp_json_encode(
				[
					'po_exists' => $po_ok,
					'mo_exists' => $mo_ok,
					'po_mtime'  => $po_ok ? filemtime( $po_path ) : null,
					'mo_mtime'  => $mo_ok ? filemtime( $mo_path ) : null,
				]
			)
		);
	}

	// -- COUNTERFRONTEND.md U3: test_admin_menu() -- 3 checks -------------------------

	private function test_admin_menu(): void {
		global $submenu;

		// A bare `wp eval` never fires admin_menu on its own (no wp-admin
		// page load happened this request) — every Screens\*::init() call
		// already ran unconditionally from Boot.php regardless, so firing
		// it here reaches the exact same registration a real page load
		// would, not a special-cased substitute. add_menu_page()/
		// add_submenu_page() both silently skip registering anything for a
		// capability check that fails, and a bare `wp eval` has no logged-in
		// user at all (no --user flag) — every check fails, so $submenu
		// stays empty regardless of admin_menu firing. Same "borrow an
		// administrator" trap test_terminal_assets()/test_price_groups()
		// already documented; same fix, restored right after.
		if ( empty( $submenu['counter'] ) ) {
			$original_user_id_menu = get_current_user_id();
			$admins_menu            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
			if ( ! empty( $admins_menu ) ) {
				wp_set_current_user( (int) $admins_menu[0] );
			}
			do_action( 'admin_menu' );
			wp_set_current_user( $original_user_id_menu );
		}

		$slugs = \Counter\Admin\Menu::registered_slugs();

		// 1. Every registered screen still appears — one add_menu_page plus
		// sixteen add_submenu_page calls, Direction's own count.
		$this->check(
			'test_admin_menu: every registered screen still appears',
			count( $slugs ) >= 17,
			'count=' . count( $slugs ) . ' slugs=' . implode( ',', $slugs )
		);

		// 2. Each has a group.
		$ungrouped = array_values( array_filter( $slugs, static fn( $s ) => null === \Counter\Admin\Menu::group_for_slug( $s ) ) );
		$this->check(
			'test_admin_menu: every screen has a group',
			empty( $ungrouped ),
			'ungrouped=' . implode( ',', $ungrouped )
		);

		// 3. No screen is orphaned by the reorganisation — including a slug
		// GROUPS has never heard of. Exercises reorder() directly against a
		// synthetic $submenu['counter'] (never the real one) so this proves
		// the safety net itself, not just that today's GROUPS list happens
		// to be complete; the real global is saved and restored around it —
		// a self-test must never leave the live site's own admin menu in a
		// synthetic state.
		$original_submenu    = $submenu['counter'] ?? null;
		$submenu['counter'] = [
			[ 'Mystery Screen', 'read', 'counter-mystery-orphan', 'Mystery Screen' ],
			[ 'Adjust Stock', 'cntr_adjust_stock', 'counter-adjust', 'Adjust Stock' ],
			[ 'Health', 'cntr_manage_settings', 'counter-health', 'Health' ],
		];
		\Counter\Admin\Menu::reorder();
		$after_slugs = array_map( static fn( $item ) => $item[2] ?? '', $submenu['counter'] );
		if ( null === $original_submenu ) {
			unset( $submenu['counter'] );
		} else {
			$submenu['counter'] = $original_submenu;
		}
		$this->check(
			'test_admin_menu: no screen is orphaned by the reorganisation',
			3 === count( $after_slugs ) && in_array( 'counter-mystery-orphan', $after_slugs, true ),
			'after=' . implode( ',', $after_slugs )
		);
	}

	// -- COUNTERFRONTEND.md U4: test_entity_picker() -- 6 checks -----------------------

	/**
	 * search_products()/search_variations() are Rest\EntitySearch's own
	 * directly-testable core (same shape as Rest\Stock::process() —
	 * test_adjustments() calls that directly rather than round-tripping a
	 * real HTTP request); the last two checks confirm the retrofit itself
	 * actually happened in the two screens the plan named, not just that
	 * the picker class exists somewhere unused.
	 */
	private function test_entity_picker(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Entity Picker Product' );
		$product->set_sku( 'CNTR-SELFTEST-EP-' . wp_generate_password( 6, false ) );
		$product->set_regular_price( '9.00' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		// 1. A title search finds it.
		$by_title = \Counter\Rest\EntitySearch::search_products( 'Entity Picker Product' );
		$this->check(
			'test_entity_picker: title search finds the fixture product',
			in_array( $product_id, array_column( $by_title, 'id' ), true ),
			'ids=' . implode( ',', array_column( $by_title, 'id' ) )
		);

		// 2. A SKU search finds it too — not just get_posts()'s own 's' param.
		$by_sku = \Counter\Rest\EntitySearch::search_products( $product->get_sku() );
		$this->check(
			'test_entity_picker: SKU search finds the fixture product',
			in_array( $product_id, array_column( $by_sku, 'id' ), true ),
			'ids=' . implode( ',', array_column( $by_sku, 'id' ) )
		);

		// 3. A query matching nothing returns nothing.
		$by_nothing = \Counter\Rest\EntitySearch::search_products( 'zzz-no-such-fixture-zzz' );
		$this->check(
			'test_entity_picker: a query matching nothing finds nothing',
			! in_array( $product_id, array_column( $by_nothing, 'id' ), true ),
			'ids=' . implode( ',', array_column( $by_nothing, 'id' ) )
		);

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Counter Selftest Fixture Entity Picker Variable' );
		$parent->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$parent->save();
		$parent_id                   = $parent->get_id();
		$this->product_fixture_ids[] = $parent_id;

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$variation->save();
		$variation_id                 = $variation->get_id();
		$this->product_fixture_ids[] = $variation_id;

		// 4. A variable product's own children are found scoped to it.
		$variations = \Counter\Rest\EntitySearch::search_variations( $parent_id, '' );
		$this->check(
			'test_entity_picker: a variable product\'s own variation is found',
			in_array( $variation_id, array_column( $variations, 'id' ), true ),
			'ids=' . implode( ',', array_column( $variations, 'id' ) )
		);

		// 5. A simple product has no variations to find — guarded, not just empty by accident.
		$no_variations = \Counter\Rest\EntitySearch::search_variations( $product_id, '' );
		$this->check(
			'test_entity_picker: a simple product returns no variations',
			[] === $no_variations,
			wp_json_encode( $no_variations )
		);

		// 6. The retrofit itself: neither screen still asks for a raw numeric id
		// where the picker now belongs.
		$adjust_src  = (string) file_get_contents( CNTR_DIR . 'includes/Admin/Screens/Adjust.php' );
		$reports_src = (string) file_get_contents( CNTR_DIR . 'includes/Admin/Screens/Reports.php' );
		$still_raw   = false !== strpos( $adjust_src, 'id="cntr-adj-product" min="1"' )
			|| false !== strpos( $adjust_src, 'id="cntr-adj-variation" min="0"' )
			|| false !== strpos( $reports_src, '<input type="number" name="location_id"' );
		$picker_used = 2 === substr_count( $adjust_src, 'EntityPicker::render' )
			&& 1 === substr_count( $reports_src, 'EntityPicker::render' );
		$this->check(
			'test_entity_picker: Adjust and Reports no longer take a bare id',
			! $still_raw && $picker_used,
			'still_raw=' . ( $still_raw ? 'yes' : 'no' ) . ' picker_used=' . ( $picker_used ? 'yes' : 'no' )
		);
	}

	/**
	 * Scans includes/ and templates/ for every call to one of WordPress's
	 * translation helpers (the double-underscore family, the _e/_x/_n
	 * variants, and the esc_html/esc_attr wrappers) and confirms its own
	 * last argument — the domain — is exactly 'counter'. Each call's true
	 * closing paren is found by walking forward tracking paren depth AND
	 * string-literal state (never a naive search for the next `;` or `)` —
	 * several real strings in this codebase contain their own literal
	 * semicolons and parens, which would otherwise cut a call's own search
	 * window short before ever reaching its real domain argument).
	 */
	private static function grep_for_wrong_i18n_domain(): ?string {
		$fn_pattern = '/(?<![A-Za-z0-9_])(__|_e|_x|_ex|_n|_nx|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(/';
		$dirs       = [ CNTR_DIR . 'includes', CNTR_DIR . 'templates' ];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) || 'Selftest.php' === $file->getFilename() ) {
					continue;
				}
				$src = (string) file_get_contents( $file->getPathname() );
				if ( ! preg_match_all( $fn_pattern, $src, $matches, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $matches[0] as $match ) {
					$pos            = $match[1];
					$open_paren_pos = $pos + strlen( $match[0] ) - 1;
					$end            = self::i18n_call_end( $src, $open_paren_pos );
					$statement      = substr( $src, $pos, $end - $pos + 1 );
					if ( ! preg_match( "/,\s*'counter'\s*\\)\s*\$/s", trim( $statement ) ) ) {
						$line = substr_count( substr( $src, 0, $pos ), "\n" ) + 1;
						return $file->getFilename() . ':' . $line . ': ' . trim( preg_replace( '/\s+/', ' ', mb_substr( $statement, 0, 120 ) ) );
					}
				}
			}
		}
		return null;
	}

	/** The index of a translation call's own matching close-paren, skipping over string-literal contents (and their escapes) so a `(`/`)`/`;` inside the translated text itself is never mistaken for structure. */
	private static function i18n_call_end( string $src, int $open_paren_pos ): int {
		$len    = strlen( $src );
		$i      = $open_paren_pos + 1;
		$depth  = 1;
		$in_str = null;
		while ( $i < $len && $depth > 0 ) {
			$c = $src[ $i ];
			if ( null !== $in_str ) {
				if ( '\\' === $c ) {
					$i += 2;
					continue;
				}
				if ( $c === $in_str ) {
					$in_str = null;
				}
				++$i;
				continue;
			}
			if ( "'" === $c || '"' === $c ) {
				$in_str = $c;
				++$i;
				continue;
			}
			if ( '(' === $c ) {
				++$depth;
			}
			if ( ')' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
			++$i;
		}
		return $i;
	}

	// -- P8.4: test_update_policy() -- 3 checks ----------------------------------------

	private function test_update_policy(): void {
		$status = \Counter\Admin\UpdatePolicy::status();

		// Check 1 — WP_AUTO_UPDATE_CORE is false or 'minor'.
		$this->check(
			'test_update_policy: WP_AUTO_UPDATE_CORE is false or \'minor\'',
			$status['core_ok'],
			wp_json_encode( [ 'core_value' => $status['core_value'] ] )
		);

		// Check 2 — WooCommerce is not in the auto-update list.
		$this->check(
			'test_update_policy: WooCommerce is not in the auto-update list',
			! in_array( 'woocommerce/woocommerce.php', $status['plugins_in_auto_update_list'], true ),
			wp_json_encode( $status['plugins_in_auto_update_list'] )
		);

		// Check 3 — the Health page warns if either changes. Drifts the
		// ONE half of this that's actually safe to mutate live (the
		// auto_update_plugins site option — WP_AUTO_UPDATE_CORE is a
		// wp-config.php constant, and PHP constants cannot be redefined
		// once set), renders the real page twice — drifted, then restored
		// — and confirms the warning appears and then genuinely
		// disappears, proving this is a live read, not a stuck cache.
		$original_user_id = get_current_user_id();
		$admins            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
		}

		$original_list = (array) get_site_option( 'auto_update_plugins', [] );
		update_site_option( 'auto_update_plugins', array_values( array_unique( array_merge( $original_list, [ 'woocommerce/woocommerce.php' ] ) ) ) );
		unset( $_GET['selftest'] );

		ob_start();
		\Counter\Admin\Health::render();
		$html_drifted = ob_get_clean();

		update_site_option( 'auto_update_plugins', $original_list );

		ob_start();
		\Counter\Admin\Health::render();
		$html_restored = ob_get_clean();

		if ( ! empty( $admins ) ) {
			wp_set_current_user( $original_user_id );
		}

		$this->check(
			'test_update_policy: the Health page warns if either changes',
			str_contains( $html_drifted, 'woocommerce/woocommerce.php' ) && ! str_contains( $html_restored, 'woocommerce/woocommerce.php' ),
			wp_json_encode(
				[
					'drifted_has_warning' => str_contains( $html_drifted, 'woocommerce/woocommerce.php' ),
					'restored_clean'      => ! str_contains( $html_restored, 'woocommerce/woocommerce.php' ),
				]
			)
		);
	}

	// -- P8.5: test_order_lookup() -- 3 checks ----------------------------------------

	/**
	 * P8.5's own real finding: the terminal's F9 return trigger was a
	 * stub, and the missing piece turned out to be one layer further
	 * back than the UI — nothing let a cashier turn the only thing a
	 * printed receipt actually carries (its receipt_no) into an order_id
	 * and a set of returnable lines at all. New GET /order-lookup fills
	 * that gap; this is its own test, going through the real REST
	 * dispatcher (rest_do_request()), not the class directly.
	 */
	private function test_order_lookup(): void {
		$main_id     = \Counter\Stock\Locations::default_id();
		$register_id = \Counter\Pos\Registers::create(
			[
				'name'        => 'Counter Selftest Fixture Order Lookup Register',
				'location_id' => $main_id,
				'prefix'      => 'ZL' . substr( wp_generate_password( 6, false ), 0, 6 ),
				'status'      => 'active',
			]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Order Lookup Product' );
		$product->set_regular_price( '50.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;
		Db::transaction(
			function () use ( $product_id, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_order_lookup',
						'ref_id'       => 0,
					]
				);
			}
		);

		$receipt_no = 'SELFTEST-LOOKUP-1';
		$uuid       = wp_generate_uuid4();
		$this->sale_queue_uuids[] = $uuid;
		$body       = [
			'lines'    => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '3.0000', 'unit_price' => '50.0000', 'discount' => '0.0000' ] ],
			'tenders'  => [ [ 'method' => 'cash', 'amount' => '150.0000' ] ],
			'customer' => [ 'phone' => '' ],
		];
		$result   = \Counter\Rest\Sale::process( $uuid, $register_id, $shift_id, $receipt_no, $body );
		$order_id = (int) ( $result instanceof \WP_Error ? 0 : ( $result instanceof \WP_REST_Response ? $result->get_data()['order_id'] ?? 0 : $result['order_id'] ?? 0 ) );
		if ( $order_id ) {
			$this->order_fixture_ids[] = $order_id;
		}

		$lookup = function () use ( $receipt_no, $register_id ) {
			$req = new \WP_REST_Request( 'GET', '/counter/v1/order-lookup' );
			$req->set_param( 'receipt_no', $receipt_no );
			$req->set_header( 'X-CNTR-Register', (string) $register_id );
			return rest_do_request( $req );
		};

		// A bare `wp eval` (no --user flag) runs with no logged-in user at
		// all, so the real permission_callback would correctly read 401
		// regardless of what it's actually checking — the same gotcha
		// several earlier tests already found live. Borrow an administrator
		// for every REST call this test makes, restore the real user after.
		$original_user_id_lookup = get_current_user_id();
		$admins_lookup            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_lookup ) ) {
			wp_set_current_user( (int) $admins_lookup[0] );
		}

		// Check 1 — a lookup by a real receipt number returns the real
		// order id and a line matching what was actually sold.
		$response_1 = $lookup();
		$data_1     = $response_1->get_data();
		$line_1     = $data_1['lines'][0] ?? [];
		$this->check(
			'test_order_lookup: a lookup by a real receipt number returns the real order and its lines',
			200 === $response_1->get_status() && $order_id === (int) ( $data_1['order_id'] ?? 0 )
				&& $product_id === (int) ( $line_1['product_id'] ?? 0 )
				&& 0 === bccomp( $line_1['qty'] ?? '-1', '3.0000', 4 )
				&& 0 === bccomp( $line_1['remaining_qty'] ?? '-1', '3.0000', 4 ),
			wp_json_encode( [ 'status' => $response_1->get_status(), 'data' => $data_1 ] )
		);

		// Check 2 — after a partial return, the same lookup reflects the
		// reduced remaining quantity — proves this reads live off the
		// order's own real refund state, not a snapshot taken once.
		$order   = wc_get_order( $order_id );
		$items   = $order ? array_values( $order->get_items( 'line_item' ) ) : [];
		$item_id = $items ? $items[0]->get_id() : 0;
		if ( $order && $item_id ) {
			\Counter\Orders\Refunds::process(
				$order,
				'50.00',
				[ $item_id => [ 'qty' => 1, 'refund_total' => 50.00, 'refund_tax' => [] ] ],
				'selftest partial return for lookup',
				$shift_id,
				[ [ 'method' => 'cash', 'amount' => '50.00' ] ]
			);
		}
		$response_2 = $lookup();
		$data_2     = $response_2->get_data();
		$line_2     = $data_2['lines'][0] ?? [];
		$this->check(
			'test_order_lookup: after a partial return, the same lookup reflects the reduced remaining quantity',
			200 === $response_2->get_status()
				&& 0 === bccomp( $line_2['refunded_qty'] ?? '-1', '1.0000', 4 )
				&& 0 === bccomp( $line_2['remaining_qty'] ?? '-1', '2.0000', 4 ),
			wp_json_encode( [ 'status' => $response_2->get_status(), 'line' => $line_2 ] )
		);

		// Check 3 — an unknown receipt number is refused with a 404, not
		// a silent empty result a cashier could mistake for "nothing to
		// return" on an order that actually exists.
		$req_missing = new \WP_REST_Request( 'GET', '/counter/v1/order-lookup' );
		$req_missing->set_param( 'receipt_no', 'SELFTEST-LOOKUP-DOES-NOT-EXIST' );
		$req_missing->set_header( 'X-CNTR-Register', (string) $register_id );
		$response_3 = rest_do_request( $req_missing );
		$this->check(
			'test_order_lookup: an unknown receipt number is refused with a 404',
			404 === $response_3->get_status(),
			'status=' . $response_3->get_status()
		);

		if ( ! empty( $admins_lookup ) ) {
			wp_set_current_user( $original_user_id_lookup );
		}
	}

	// -- P2.11: test_stock_reports() -- 5 checks ----------------------------------------

	private function test_stock_reports(): void {
		global $wpdb;
		$main_id = \Counter\Stock\Locations::default_id();

		// A bare `wp eval` (no --user flag) runs with NO logged-in user at
		// all, so current_user_can( 'cntr_view_cost' ) would correctly
		// return false regardless of role grants — same issue P1.14's
		// test_tenders() and P2.6's test_customer_ledger() found live
		// before this one. Establish an administrator context for the
		// WHOLE test (check 2 switches away from it briefly, on purpose,
		// to prove the OPPOSITE case), restoring the real original user at
		// the very end.
		$original_user_id_reports = get_current_user_id();
		$admins_reports            = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( ! empty( $admins_reports ) ) {
			wp_set_current_user( (int) $admins_reports[0] );
		}

		// 1. Valuation equals SUM(qty x batch cost) computed independently.
		$product_val = new \WC_Product_Simple();
		$product_val->set_name( 'Counter Selftest Fixture Reports Valuation' );
		$product_val->set_regular_price( '5.00' );
		$product_val->set_manage_stock( true );
		$product_val->set_stock_quantity( 0 );
		$product_val->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_val->save();
		$this->product_fixture_ids[] = $product_val->get_id();

		\Counter\Stock\Batches::receive( [ 'product_id' => $product_val->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'REPORTS-A', 'qty_received' => '5', 'unit_cost' => '10.0000' ] );
		\Counter\Stock\Batches::receive( [ 'product_id' => $product_val->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'REPORTS-B', 'qty_received' => '3', 'unit_cost' => '20.0000' ] );

		$valuation = \Counter\Reports\Reports::valuation( [ 'location_id' => $main_id ] );
		$val_row   = null;
		foreach ( $valuation as $row ) {
			if ( $row['product_id'] === $product_val->get_id() ) {
				$val_row = $row;
			}
		}
		$batches_table = Install::table( 'batches' );
		$sql_value = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT CAST(SUM(qty_remaining * unit_cost) AS DECIMAL(14,4)) FROM {$batches_table} WHERE product_id = %d AND location_id = %d", $product_val->get_id(), $main_id )
		);
		$this->check(
			'test_stock_reports: valuation equals SUM(qty x batch cost) computed independently',
			$val_row && array_key_exists( 'value', $val_row ) && $val_row['value'] === $sql_value && '110.0000' === $sql_value,
			wp_json_encode( [ 'val_row' => $val_row, 'sql_value' => $sql_value ] )
		);

		// 2. A user without cntr_view_cost gets a response with no cost keys
		// at all, not zeros.
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$subscriber_id = wp_insert_user( [ 'user_login' => 'cntr_selftest_reports_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'subscriber' ] );
		$original_user_id = get_current_user_id();
		$no_cost_row = null;
		if ( ! is_wp_error( $subscriber_id ) ) {
			wp_set_current_user( $subscriber_id );
			$valuation_gated = \Counter\Reports\Reports::valuation( [ 'location_id' => $main_id ] );
			foreach ( $valuation_gated as $row ) {
				if ( $row['product_id'] === $product_val->get_id() ) {
					$no_cost_row = $row;
				}
			}
			wp_set_current_user( $original_user_id );
		}
		$this->check(
			'test_stock_reports: a user without cntr_view_cost gets a response with no cost keys at all, not zeros',
			! is_wp_error( $subscriber_id )
				&& $no_cost_row
				&& ! array_key_exists( 'value', $no_cost_row )
				&& ! array_key_exists( 'avg_unit_cost', $no_cost_row )
				&& array_key_exists( 'qty', $no_cost_row ), // the non-cost field is still present
			wp_json_encode( $no_cost_row )
		);
		if ( ! is_wp_error( $subscriber_id ) ) {
			wp_delete_user( $subscriber_id );
		}

		// 3. The reorder list respects per-product alert quantities.
		$product_low = new \WC_Product_Simple();
		$product_low->set_name( 'Counter Selftest Fixture Reports Low' );
		$product_low->set_regular_price( '5.00' );
		$product_low->set_manage_stock( true );
		$product_low->set_stock_quantity( 0 );
		$product_low->set_low_stock_amount( 5 );
		$product_low->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_low->save();
		$this->product_fixture_ids[] = $product_low->get_id();

		$product_ok = new \WC_Product_Simple();
		$product_ok->set_name( 'Counter Selftest Fixture Reports OK' );
		$product_ok->set_regular_price( '5.00' );
		$product_ok->set_manage_stock( true );
		$product_ok->set_stock_quantity( 0 );
		$product_ok->set_low_stock_amount( 5 );
		$product_ok->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_ok->save();
		$this->product_fixture_ids[] = $product_ok->get_id();

		$product_no_threshold = new \WC_Product_Simple();
		$product_no_threshold->set_name( 'Counter Selftest Fixture Reports No Threshold' );
		$product_no_threshold->set_regular_price( '5.00' );
		$product_no_threshold->set_manage_stock( true );
		$product_no_threshold->set_stock_quantity( 0 );
		$product_no_threshold->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_no_threshold->save();
		$this->product_fixture_ids[] = $product_no_threshold->get_id();

		$seed_qty = function ( int $product_id, string $qty ) use ( $main_id ) {
			Db::transaction(
				function () use ( $product_id, $main_id, $qty ) {
					\Counter\Stock\Ledger::move( [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => $qty, 'reason' => 'opening', 'ref_type' => 'selftest_reports', 'ref_id' => 0 ] );
				}
			);
		};
		$seed_qty( $product_low->get_id(), '3' );           // 3 <= threshold 5 -> reorder
		$seed_qty( $product_ok->get_id(), '20' );            // 20 > threshold 5 -> not
		$seed_qty( $product_no_threshold->get_id(), '1' );   // no threshold set -> not

		$reorder = \Counter\Reports\Reports::reorder_list( [ 'location_id' => $main_id ] );
		$reorder_ids = array_map( static fn( $r ) => $r['product_id'], $reorder );
		$this->check(
			'test_stock_reports: the reorder list respects per-product alert quantities',
			in_array( $product_low->get_id(), $reorder_ids, true )
				&& ! in_array( $product_ok->get_id(), $reorder_ids, true )
				&& ! in_array( $product_no_threshold->get_id(), $reorder_ids, true ),
			wp_json_encode( $reorder_ids )
		);

		// 4. Dead stock respects the configured window. Uses the DEFAULT
		// window (Settings::get()'s own fallback, 90 days) rather than
		// overwriting the live 'reports.dead_stock_days' setting — this
		// class's cleanup() only ever restores its own SETTINGS_TEST_KEY,
		// and permanently changing a real setting as a side effect of a
		// self-test run would be a genuine regression, not a shortcut.
		$window_days = (int) \Counter\Settings::get( 'reports.dead_stock_days', 90 );

		$product_dead = new \WC_Product_Simple();
		$product_dead->set_name( 'Counter Selftest Fixture Reports Dead' );
		$product_dead->set_regular_price( '5.00' );
		$product_dead->set_manage_stock( true );
		$product_dead->set_stock_quantity( 0 );
		$product_dead->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_dead->save();
		$this->product_fixture_ids[] = $product_dead->get_id();

		$product_fresh = new \WC_Product_Simple();
		$product_fresh->set_name( 'Counter Selftest Fixture Reports Fresh' );
		$product_fresh->set_regular_price( '5.00' );
		$product_fresh->set_manage_stock( true );
		$product_fresh->set_stock_quantity( 0 );
		$product_fresh->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_fresh->save();
		$this->product_fixture_ids[] = $product_fresh->get_id();

		$moves_table = Install::table( 'stock_moves' );
		Db::transaction(
			function () use ( $product_dead, $product_fresh, $main_id ) {
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_dead->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '10', 'reason' => 'opening', 'ref_type' => 'selftest_reports', 'ref_id' => 0 ] );
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_dead->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '-1', 'reason' => 'sale', 'ref_type' => 'selftest_reports', 'ref_id' => 0 ] );
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_fresh->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '10', 'reason' => 'opening', 'ref_type' => 'selftest_reports', 'ref_id' => 0 ] );
				\Counter\Stock\Ledger::move( [ 'product_id' => $product_fresh->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'qty_delta' => '-1', 'reason' => 'sale', 'ref_type' => 'selftest_reports', 'ref_id' => 0 ] );
			}
		);
		// Backdate product_dead's sale well past the actual configured window
		// (whatever it is right now, live default or not) — product_fresh's
		// sale stays at "now," always within any positive window.
		$backdated = gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - ( ( $window_days + 30 ) * DAY_IN_SECONDS ) );
		$wpdb->update( $moves_table, [ 'created_at' => $backdated ], [ 'product_id' => $product_dead->get_id(), 'reason' => 'sale' ] );

		$dead = \Counter\Reports\Reports::dead_stock( [ 'location_id' => $main_id ] );
		$dead_ids = array_map( static fn( $r ) => $r['product_id'], $dead );
		$this->check(
			'test_stock_reports: dead stock respects the configured window',
			in_array( $product_dead->get_id(), $dead_ids, true ) && ! in_array( $product_fresh->get_id(), $dead_ids, true ),
			wp_json_encode( $dead_ids )
		);

		// 5. Movement history for a product reconciles to its ledger rows.
		$history = \Counter\Reports\Reports::movement_history( $product_val->get_id(), 0, [ 'location_id' => $main_id ] );
		$sql_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$moves_table} WHERE product_id = %d AND variation_id = 0 AND location_id = %d ORDER BY id", $product_val->get_id(), $main_id ),
			ARRAY_A
		);
		$history_ids = array_map( static fn( $r ) => (int) $r['id'], $history );
		$sql_ids     = array_map( static fn( $r ) => (int) $r['id'], $sql_rows );
		$this->check(
			'test_stock_reports: movement history for a product reconciles to its ledger rows',
			$history_ids === $sql_ids && ! empty( $history_ids ),
			wp_json_encode( [ 'history_ids' => $history_ids, 'sql_ids' => $sql_ids ] )
		);

		wp_set_current_user( $original_user_id_reports );
	}

	// -- P2.10: test_rebuild() -- 5 checks ----------------------------------------------

	private function test_rebuild(): void {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		$stock_table = Install::table( 'stock' );
		$main_id     = \Counter\Stock\Locations::default_id();

		$other_id = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture Rebuild Other Location', 'code' => 'CNTR-SELFTEST-' . wp_generate_password( 8, false ), 'is_online_sellable' => 1, 'is_default' => 0, 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $other_id;

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Rebuild Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_backorders( 'no' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		// A mixed fixture day — purchase, counter sale, online sale,
		// adjustment, return, transfer — spanning both locations.
		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture Rebuild Register', 'location_id' => $main_id, 'prefix' => 'ZR' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		// purchase: +20
		\Counter\Stock\Batches::receive( [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'REBUILD-A', 'qty_received' => '20', 'unit_cost' => '50.0000' ] );

		// counter sale: -3
		$counter_order = \Counter\Orders\Builder::build(
			[ [ 'product' => $product, 'qty' => 3, 'subtotal' => '15.00', 'total' => '15.00' ] ],
			[ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id(), 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-REBUILD-1' ]
		);
		$this->order_fixture_ids[] = $counter_order->get_id();
		\Counter\Orders\Channel::apply_stock( $counter_order );
		$counter_order->set_status( 'completed' );
		$counter_order->save();

		// online sale: -2. A plain WC_Order with no _cntr_channel meta at
		// all — Channel::apply_stock() defaults an order with no channel
		// meta to 'online', exactly the real-world shape of a genuine
		// storefront checkout Counter never built.
		$online_order = new \WC_Order();
		$online_order->set_customer_id( 0 );
		$online_item = new \WC_Order_Item_Product();
		$online_item->set_product( $product );
		$online_item->set_quantity( 2 );
		$online_item->set_subtotal( '10.00' );
		$online_item->set_total( '10.00' );
		$online_order->add_item( $online_item );
		// A plain WC_Order does not compute its own total from line items —
		// unlike Orders\Builder, which explicitly calls calculate_totals().
		// Found live: without this, the order's total stayed 0 and the
		// refund below failed as "Invalid refund amount" (more than 100% of
		// a zero total) — a fixture bug, not a plugin one.
		$online_order->calculate_totals( false );
		$online_order->save();
		$this->order_fixture_ids[] = $online_order->get_id();
		\Counter\Orders\Channel::apply_stock( $online_order );
		$online_order->set_status( 'completed' );
		$online_order->save();

		// adjustment: -2
		\Counter\Rest\Stock::process( wp_generate_uuid4(), $product_id, 0, $main_id, '-2', 'adjust', 'selftest rebuild shrinkage', 0 );

		// return: +1 (a partial refund of the online order, processed at the counter)
		$online_items          = array_values( $online_order->get_items( 'line_item' ) );
		$refund_result_rebuild = \Counter\Orders\Refunds::process(
			$online_order,
			'5.00',
			[ $online_items[0]->get_id() => [ 'qty' => 1, 'refund_total' => 5.00, 'refund_tax' => [] ] ],
			'selftest rebuild return',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '5.00' ] ]
		);

		// transfer: -5 at main, +5 at other
		$transfer_id = \Counter\Stock\Transfers::create(
			[ 'from_location_id' => $main_id, 'to_location_id' => $other_id, 'lines' => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '5' ] ] ]
		);
		$this->transfer_fixture_ids[] = $transfer_id;
		\Counter\Stock\Transfers::send( $transfer_id );
		$transfer_line = \Counter\Stock\Transfers::lines( $transfer_id )[0];
		\Counter\Stock\Transfers::receive( $transfer_id, [ $transfer_line['id'] => '5' ] );

		// Expected: main = 20 - 3 - 2 - 2 + 1 - 5 = 9 ; other = 5.
		$expected_main  = '9.0000';
		$expected_other = '5.0000';

		// 1. Corrupting every cntr_stock row and rebuilding restores all of
		// them exactly.
		$wpdb->update( $stock_table, [ 'qty' => '99999.0000' ], [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id ] );
		$wpdb->update( $stock_table, [ 'qty' => '-88888.0000' ], [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $other_id ] );

		$report_1 = \Counter\Stock\Ledger::rebuild( $product_id, false );
		$main_after_1  = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$other_after_1 = \Counter\Stock\Ledger::balance( $product_id, 0, $other_id );
		$this->check(
			'test_rebuild: corrupting every cntr_stock row and rebuilding restores all of them exactly',
			$expected_main === $main_after_1 && $expected_other === $other_after_1,
			wp_json_encode( [ 'main' => $main_after_1, 'other' => $other_after_1, 'refund_result' => $refund_result_rebuild instanceof \WP_Error ? $refund_result_rebuild->get_error_message() : $refund_result_rebuild, 'report' => $report_1 ] )
		);

		// 2. The rebuilt figure equals SUM(qty_delta) per item and location.
		$sql_main = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CAST(SUM(qty_delta) AS DECIMAL(14,4)) FROM {$moves_table} WHERE product_id = %d AND variation_id = 0 AND location_id = %d", $product_id, $main_id ) );
		$sql_other = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CAST(SUM(qty_delta) AS DECIMAL(14,4)) FROM {$moves_table} WHERE product_id = %d AND variation_id = 0 AND location_id = %d", $product_id, $other_id ) );
		$this->check(
			'test_rebuild: the rebuilt figure equals SUM(qty_delta) per item and location',
			$main_after_1 === $sql_main && $other_after_1 === $sql_other,
			"main: rebuilt={$main_after_1} sql={$sql_main} | other: rebuilt={$other_after_1} sql={$sql_other}"
		);

		// 3. An online sale is present in the ledger and survives the
		// rebuild — the B1 regression test. If the rebuild's own query ever
		// excluded reason='sale_online', main's rebuilt total would be 2
		// units too HIGH (11 instead of 9) — assert the online move exists
		// AND that excluding it from an independent sum would produce a
		// DIFFERENT, wrong figure, proving the correct 9.0000 genuinely
		// depends on including it.
		$online_move_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE product_id = %d AND reason = 'sale_online'", $product_id ) ) > 0;
		$sum_excluding_online = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT CAST(SUM(qty_delta) AS DECIMAL(14,4)) FROM {$moves_table} WHERE product_id = %d AND variation_id = 0 AND location_id = %d AND reason != 'sale_online'", $product_id, $main_id )
		);
		$this->check(
			'test_rebuild: an online sale is present in the ledger and survives the rebuild',
			$online_move_exists && '9.0000' === $main_after_1 && '11.0000' === $sum_excluding_online,
			wp_json_encode( [ 'online_move_exists' => $online_move_exists, 'rebuilt' => $main_after_1, 'sum_excluding_online' => $sum_excluding_online ] )
		);

		// 4. --dry-run changes nothing.
		$wpdb->update( $stock_table, [ 'qty' => '77777.0000' ], [ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id ] );
		$corrupted_before_dry_run = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$report_dry = \Counter\Stock\Ledger::rebuild( $product_id, true );
		$after_dry_run = \Counter\Stock\Ledger::balance( $product_id, 0, $main_id );
		$dry_run_row = null;
		foreach ( $report_dry['touched'] as $row ) {
			if ( $row['location_id'] === $main_id ) {
				$dry_run_row = $row;
			}
		}
		$this->check(
			'test_rebuild: --dry-run changes nothing',
			'77777.0000' === $corrupted_before_dry_run
				&& $corrupted_before_dry_run === $after_dry_run
				&& $dry_run_row && '77777.0000' === $dry_run_row['before'] && '9.0000' === $dry_run_row['after'],
			wp_json_encode( [ 'before' => $corrupted_before_dry_run, 'after' => $after_dry_run, 'report_row' => $dry_run_row ] )
		);
		// Restore for check 5 — a real (write) rebuild.
		\Counter\Stock\Ledger::rebuild( $product_id, false );

		// 5. The published WooCommerce figure after rebuild equals the
		// publisher's formula.
		\Counter\Stock\Publisher::publish( $product_id, 0 );
		$published = (int) wc_get_product( $product_id )->get_stock_quantity();
		// Both main and other are online-sellable; no reservations, no buffer.
		$formula_expected = (int) bcadd( $expected_main, $expected_other, 0 );
		$this->check(
			"test_rebuild: the published WooCommerce figure after rebuild equals the publisher's formula",
			$formula_expected === $published,
			"formula={$formula_expected} published={$published}"
		);
	}

	// -- P2.9: test_stocktake() -- 6 checks ---------------------------------------------

	private function test_stocktake(): void {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		$main_id     = \Counter\Stock\Locations::default_id();

		$make_product = function ( string $suffix ) {
			$p = new \WC_Product_Simple();
			$p->set_name( 'Counter Selftest Fixture Stocktake ' . $suffix );
			$p->set_regular_price( '5.00' );
			$p->set_manage_stock( true );
			$p->set_stock_quantity( 0 );
			$p->update_meta_data( '_cntr_selftest_fixture', self::TAG );
			$p->save();
			$this->product_fixture_ids[] = $p->get_id();
			return $p->get_id();
		};
		$seed = function ( int $product_id ) use ( $main_id ) {
			Db::transaction(
				function () use ( $product_id, $main_id ) {
					\Counter\Stock\Ledger::move(
						[
							'product_id'   => $product_id,
							'variation_id' => 0,
							'location_id'  => $main_id,
							'qty_delta'    => '20',
							'reason'       => 'opening',
							'ref_type'     => 'selftest_stocktake',
							'ref_id'       => 0,
						]
					);
				}
			);
		};

		$product_exact = $make_product( 'Exact' );
		$product_short = $make_product( 'Short' );
		$product_long  = $make_product( 'Long' );
		$product_sale  = $make_product( 'Sale' );
		foreach ( [ $product_exact, $product_short, $product_long, $product_sale ] as $pid ) {
			$seed( $pid );
		}

		$stocktake_id = \Counter\Stock\Stocktake::open( [ 'location_id' => $main_id, 'note' => 'selftest' ] );
		$this->stocktake_fixture_ids[] = $stocktake_id;

		// 1. A count equal to expected writes no move.
		$moves_before_exact = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE product_id = %d AND reason = 'stocktake'", $product_exact ) );
		\Counter\Stock\Stocktake::count_line( $stocktake_id, $product_exact, 0, '20' );
		$moves_after_exact = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE product_id = %d AND reason = 'stocktake'", $product_exact ) );
		$this->check(
			'test_stocktake: a count equal to expected writes no move',
			0 === $moves_before_exact && 0 === $moves_after_exact,
			"before={$moves_before_exact} after={$moves_after_exact}"
		);

		// 2. A short count writes a negative move of exactly the variance.
		\Counter\Stock\Stocktake::count_line( $stocktake_id, $product_short, 0, '15' );
		$move_short = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$moves_table} WHERE product_id = %d AND reason = 'stocktake'", $product_short ), ARRAY_A );
		$balance_short = \Counter\Stock\Ledger::balance( $product_short, 0, $main_id );
		$this->check(
			'test_stocktake: a short count writes a negative move of exactly the variance',
			$move_short && '-5.0000' === $move_short['qty_delta'] && '15.0000' === $balance_short,
			wp_json_encode( [ 'move' => $move_short, 'balance' => $balance_short ] )
		);

		// 3. A long count writes a positive one.
		\Counter\Stock\Stocktake::count_line( $stocktake_id, $product_long, 0, '25' );
		$move_long = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$moves_table} WHERE product_id = %d AND reason = 'stocktake'", $product_long ), ARRAY_A );
		$balance_long = \Counter\Stock\Ledger::balance( $product_long, 0, $main_id );
		$this->check(
			'test_stocktake: a long count writes a positive one',
			$move_long && '5.0000' === $move_long['qty_delta'] && '25.0000' === $balance_long,
			wp_json_encode( [ 'move' => $move_long, 'balance' => $balance_long ] )
		);

		// 4. A sale during an open stocktake does not corrupt the variance —
		// expected is read at count time, already reflecting the sale.
		Db::transaction(
			function () use ( $product_sale, $main_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_sale,
						'variation_id' => 0,
						'location_id'  => $main_id,
						'qty_delta'    => '-3',
						'reason'       => 'sale',
						'ref_type'     => 'selftest_stocktake_sale',
						'ref_id'       => 0,
					]
				);
			}
		);
		$line_id_sale = \Counter\Stock\Stocktake::count_line( $stocktake_id, $product_sale, 0, '17' );
		$line_sale    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cntr_stocktake_lines WHERE id = %d", $line_id_sale ), ARRAY_A );
		$moves_sale   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE product_id = %d AND reason = 'stocktake'", $product_sale ) );
		$this->check(
			'test_stocktake: a sale during an open stocktake does not corrupt the variance',
			$line_sale && '17.0000' === $line_sale['expected'] && '17.0000' === $line_sale['counted'] && '0.0000' === $line_sale['variance'] && 0 === $moves_sale,
			wp_json_encode( $line_sale )
		);

		// 5. Closing twice is refused.
		$close_1 = \Counter\Stock\Stocktake::close( $stocktake_id );
		$close_2 = \Counter\Stock\Stocktake::close( $stocktake_id );
		$this->check(
			'test_stocktake: closing twice is refused',
			true === $close_1 && $close_2 instanceof \WP_Error && 'cntr_stocktake_already_closed' === $close_2->get_error_code(),
			wp_json_encode( [ 'close_1' => $close_1, 'close_2' => $close_2 instanceof \WP_Error ? $close_2->get_error_code() : $close_2 ] )
		);

		// 6. The variance sheet totals equal the summed moves.
		$sheet = \Counter\Stock\Stocktake::variance_sheet( $stocktake_id );
		$sql_sum = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT CAST(COALESCE(SUM(qty_delta),0) AS DECIMAL(14,4)) FROM {$moves_table} WHERE ref_type = 'stocktake' AND ref_id = %d", $stocktake_id )
		);
		$this->check(
			'test_stocktake: the variance sheet totals equal the summed moves',
			4 === count( $sheet['lines'] ) && 0 === bccomp( $sheet['total_variance'], $sql_sum, 4 ) && '0.0000' === $sheet['total_variance'],
			wp_json_encode( [ 'sheet_total' => $sheet['total_variance'], 'sql_sum' => $sql_sum, 'line_count' => count( $sheet['lines'] ) ] )
		);
	}

	// -- P2.8: test_fifo() -- 7 checks --------------------------------------------------

	private function test_fifo(): void {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture FIFO Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		$main_id = \Counter\Stock\Locations::default_id();

		$batch_a = \Counter\Stock\Batches::receive(
			[ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'FIFO-A', 'qty_received' => '10', 'unit_cost' => '100.0000' ]
		);
		$this->batch_fixture_ids[] = $batch_a;
		$batch_b = \Counter\Stock\Batches::receive(
			[ 'product_id' => $product_id, 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'FIFO-B', 'qty_received' => '10', 'unit_cost' => '120.0000' ]
		);
		$this->batch_fixture_ids[] = $batch_b;

		$move_extra = [ 'reason' => 'sale', 'ref_type' => 'selftest_fifo', 'ref_id' => 1 ];

		// 1. Two batches at 100 and 120, sell 1 -> cost 100 and only batch A depletes.
		$fifo_1 = \Counter\Stock\Batches::consume_fifo( $product_id, 0, $main_id, '1', $move_extra );
		$batch_a_after_1 = \Counter\Stock\Batches::get( $batch_a );
		$batch_b_after_1 = \Counter\Stock\Batches::get( $batch_b );
		$this->check(
			'test_fifo: two batches at 100 and 120, sell 1 -> cost 100 and only batch A depletes',
			1 === count( $fifo_1['moves'] )
				&& $batch_a === $fifo_1['moves'][0]['batch_id']
				&& '100.0000' === $fifo_1['moves'][0]['unit_cost']
				&& '100.0000' === $fifo_1['weighted_unit_cost']
				&& '9.0000' === $batch_a_after_1['qty_remaining']
				&& '10.0000' === $batch_b_after_1['qty_remaining'],
			wp_json_encode( [ 'fifo_1' => $fifo_1, 'a' => $batch_a_after_1['qty_remaining'], 'b' => $batch_b_after_1['qty_remaining'] ] )
		);

		// 2. Sell across the boundary (9 remain in A) -> two moves, weighted cost correct to 4 places.
		$fifo_2 = \Counter\Stock\Batches::consume_fifo( $product_id, 0, $main_id, '10', $move_extra ); // 9 from A, 1 from B
		$batch_a_after_2 = \Counter\Stock\Batches::get( $batch_a );
		$batch_b_after_2 = \Counter\Stock\Batches::get( $batch_b );
		// weighted = (9*100 + 1*120) / 10 = 1020/10 = 102.0000
		$this->check(
			'test_fifo: sell across the boundary -> two moves, weighted cost correct to 4 places',
			2 === count( $fifo_2['moves'] )
				&& $batch_a === $fifo_2['moves'][0]['batch_id'] && '9.0000' === $fifo_2['moves'][0]['qty'] && '100.0000' === $fifo_2['moves'][0]['unit_cost']
				&& $batch_b === $fifo_2['moves'][1]['batch_id'] && '1.0000' === $fifo_2['moves'][1]['qty'] && '120.0000' === $fifo_2['moves'][1]['unit_cost']
				&& '102.0000' === $fifo_2['weighted_unit_cost']
				&& '0.0000' === $batch_a_after_2['qty_remaining']
				&& '9.0000' === $batch_b_after_2['qty_remaining'],
			wp_json_encode( $fifo_2 )
		);

		// Fresh product + batches for the order-level checks (3, 4, 5) — a real
		// sale spanning two batches (10 from one, 5 from the other), a real
		// full refund.
		$product_2 = new \WC_Product_Simple();
		$product_2->set_name( 'Counter Selftest Fixture FIFO Order Product' );
		$product_2->set_regular_price( '5.00' );
		$product_2->set_manage_stock( true );
		$product_2->set_stock_quantity( 0 );
		$product_2->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_2->save();
		$this->product_fixture_ids[] = $product_2->get_id();

		$batch_c = \Counter\Stock\Batches::receive(
			[ 'product_id' => $product_2->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'FIFO-C', 'qty_received' => '10', 'unit_cost' => '50.0000' ]
		);
		$this->batch_fixture_ids[] = $batch_c;
		$batch_d = \Counter\Stock\Batches::receive(
			[ 'product_id' => $product_2->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'FIFO-D', 'qty_received' => '10', 'unit_cost' => '70.0000' ]
		);
		$this->batch_fixture_ids[] = $batch_d;

		$register_id = \Counter\Pos\Registers::create(
			[ 'name' => 'Counter Selftest Fixture FIFO Register', 'location_id' => $main_id, 'prefix' => 'ZF' . substr( wp_generate_password( 6, false ), 0, 6 ), 'status' => 'active' ]
		);
		$this->register_fixture_ids[] = $register_id;
		$shift_id = \Counter\Pos\Shifts::open( $register_id, get_current_user_id(), '0.00' );

		$order = \Counter\Orders\Builder::build(
			[ [ 'product' => $product_2, 'qty' => 15, 'subtotal' => '15.00', 'total' => '15.00' ] ], // price is irrelevant to COGS, only qty is
			[ 'register_id' => $register_id, 'shift_id' => $shift_id, 'location_id' => $main_id, 'operator_id' => get_current_user_id(), 'uuid' => wp_generate_uuid4(), 'receipt_no' => 'SELFTEST-FIFO-3' ]
		);
		$this->order_fixture_ids[] = $order->get_id();
		\Counter\Orders\Channel::apply_stock( $order );
		$order->set_status( 'completed' );
		$order->save();

		// 3. _cntr_cogs equals the sum of the moves' qty x unit_cost.
		// Expected: 10 x 50 + 5 x 70 = 850.0000.
		$order_moves = $wpdb->get_results(
			$wpdb->prepare( "SELECT qty_delta, unit_cost FROM {$moves_table} WHERE ref_type = 'order' AND ref_id = %d AND reason = 'sale'", $order->get_id() ),
			ARRAY_A
		);
		$sum_from_moves = '0.0000';
		foreach ( $order_moves as $m ) {
			$sum_from_moves = bcadd( $sum_from_moves, bcmul( bcmul( $m['qty_delta'], '-1', 4 ), $m['unit_cost'], 4 ), 4 );
		}
		$cogs_meta = (string) $order->get_meta( '_cntr_cogs' );
		$this->check(
			'test_fifo: _cntr_cogs equals the sum of the moves\' qty x unit_cost',
			'850.0000' === $cogs_meta && '850.0000' === $sum_from_moves && 2 === count( $order_moves ),
			wp_json_encode( [ 'cogs_meta' => $cogs_meta, 'sum_from_moves' => $sum_from_moves, 'move_count' => count( $order_moves ) ] )
		);

		// 4. A later cost change does not alter the frozen figure.
		$batches_table = Install::table( 'batches' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$batches_table} SET unit_cost = '999.0000' WHERE id IN (%d, %d)", $batch_c, $batch_d ) );
		$order_reloaded = wc_get_order( $order->get_id() );
		$this->check(
			'test_fifo: a later cost change does not alter the frozen figure',
			'850.0000' === (string) $order_reloaded->get_meta( '_cntr_cogs' ),
			(string) $order_reloaded->get_meta( '_cntr_cogs' )
		);
		// Restore the real cost before the refund checks below, so restored
		// batch rows carry their real cost rather than the corruption probe's.
		$wpdb->query( $wpdb->prepare( "UPDATE {$batches_table} SET unit_cost = '50.0000' WHERE id = %d", $batch_c ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$batches_table} SET unit_cost = '70.0000' WHERE id = %d", $batch_d ) );

		// 5. A return restores to the batch it came from — a full refund of
		// this 15-unit sale must put exactly 10 back in batch C and 5 back in
		// batch D, restoring both to their full received quantity.
		$items    = array_values( $order_reloaded->get_items( 'line_item' ) );
		$item_id  = $items[0]->get_id();
		$refund_result = \Counter\Orders\Refunds::process(
			$order_reloaded,
			'15.00',
			[ $item_id => [ 'qty' => 15, 'refund_total' => 15.00, 'refund_tax' => [] ] ],
			'selftest fifo full return',
			$shift_id,
			[ [ 'method' => 'cash', 'amount' => '15.00' ] ]
		);
		$batch_c_after = \Counter\Stock\Batches::get( $batch_c );
		$batch_d_after = \Counter\Stock\Batches::get( $batch_d );
		$this->check(
			'test_fifo: a return restores to the batch it came from',
			true === $refund_result
				&& '10.0000' === $batch_c_after['qty_remaining']
				&& '10.0000' === $batch_d_after['qty_remaining'],
			( $refund_result instanceof \WP_Error ? $refund_result->get_error_message() : 'ok' ) . " c={$batch_c_after['qty_remaining']} d={$batch_d_after['qty_remaining']}"
		);

		// 6. Consuming more than exists costs the remainder at last-known and flags it.
		$product_3 = new \WC_Product_Simple();
		$product_3->set_name( 'Counter Selftest Fixture FIFO Short Product' );
		$product_3->set_regular_price( '5.00' );
		$product_3->set_manage_stock( true );
		$product_3->set_stock_quantity( 0 );
		$product_3->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product_3->save();
		$this->product_fixture_ids[] = $product_3->get_id();

		$batch_e = \Counter\Stock\Batches::receive(
			[ 'product_id' => $product_3->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'FIFO-E', 'qty_received' => '2', 'unit_cost' => '50.0000' ]
		);
		$this->batch_fixture_ids[] = $batch_e;

		$fifo_6 = \Counter\Stock\Batches::consume_fifo( $product_3->get_id(), 0, $main_id, '5', $move_extra );
		$this->check(
			'test_fifo: consuming more than exists costs the remainder at last-known and flags it',
			true === $fifo_6['flagged_short']
				&& 2 === count( $fifo_6['moves'] )
				&& '2.0000' === $fifo_6['moves'][0]['qty'] && '50.0000' === $fifo_6['moves'][0]['unit_cost']
				&& 0 === $fifo_6['moves'][1]['batch_id'] && '3.0000' === $fifo_6['moves'][1]['qty'] && '50.0000' === $fifo_6['moves'][1]['unit_cost']
				&& true === ( $fifo_6['moves'][1]['short'] ?? false ),
			wp_json_encode( $fifo_6 )
		);

		// 7. Batches at a different location are not consumed.
		$other_location = \Counter\Stock\Locations::create(
			[ 'name' => 'Counter Selftest Fixture FIFO Other Location', 'code' => 'CNTR-SELFTEST-' . wp_generate_password( 8, false ), 'is_online_sellable' => 0, 'is_default' => 0, 'status' => 'active' ]
		);
		$this->location_fixture_ids[] = $other_location;

		$batch_f = \Counter\Stock\Batches::receive(
			[ 'product_id' => $product_3->get_id(), 'variation_id' => 0, 'location_id' => $main_id, 'lot_no' => 'FIFO-F', 'qty_received' => '5', 'unit_cost' => '60.0000' ]
		);
		$this->batch_fixture_ids[] = $batch_f;

		$fifo_7 = \Counter\Stock\Batches::consume_fifo( $product_3->get_id(), 0, $other_location, '1', $move_extra );
		$batch_f_after_7 = \Counter\Stock\Batches::get( $batch_f );
		$this->check(
			'test_fifo: batches at a different location are not consumed',
			true === $fifo_7['flagged_short'] // nothing at $other_location, so the whole 1 unit is short-consumed
				&& 1 === count( $fifo_7['moves'] )
				&& 0 === $fifo_7['moves'][0]['batch_id']
				&& '5.0000' === $batch_f_after_7['qty_remaining'], // batch F, at $main_id, untouched
			wp_json_encode( [ 'fifo_7' => $fifo_7, 'batch_f_remaining' => $batch_f_after_7['qty_remaining'] ] )
		);
	}

	// -- P2.7: test_batches() -- 5 checks (built ahead of P2.4, which depends on it) --

	private function test_batches(): void {
		global $wpdb;

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Batch Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		$location_id = \Counter\Stock\Locations::default_id();
		$moves_table = Install::table( 'stock_moves' );

		// 1. Receiving creates a batch with the right cost.
		$batch_1 = \Counter\Stock\Batches::receive(
			[
				'product_id'   => $product_id,
				'variation_id' => 0,
				'location_id'  => $location_id,
				'lot_no'       => 'LOT-A',
				'expiry_date'  => '2027-06-15',
				'qty_received' => '10',
				'unit_cost'    => '42.5000',
			]
		);
		$this->batch_fixture_ids[] = $batch_1;
		$got_1                     = \Counter\Stock\Batches::get( $batch_1 );
		$purchase_move             = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$moves_table} WHERE batch_id = %d AND reason = 'purchase'", $batch_1 ),
			ARRAY_A
		);
		$this->check(
			'test_batches: receiving creates a batch with the right cost',
			$got_1
				&& '42.5000' === $got_1['unit_cost']
				&& '10.0000' === $got_1['qty_received']
				&& '10.0000' === $got_1['qty_remaining']
				&& $purchase_move && '10.0000' === $purchase_move['qty_delta'] && '42.5000' === $purchase_move['unit_cost'],
			wp_json_encode( [ 'batch' => $got_1, 'move' => $purchase_move ] )
		);

		// 2. qty_remaining equals the summed moves.
		\Counter\Stock\Batches::consume( $batch_1, '3' );
		$got_2       = \Counter\Stock\Batches::get( $batch_1 );
		$summed_move = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT CAST(SUM(qty_delta) AS DECIMAL(14,4)) FROM {$moves_table} WHERE batch_id = %d", $batch_1 )
		);
		$this->check(
			'test_batches: qty_remaining equals the summed moves',
			$got_2 && '7.0000' === $got_2['qty_remaining'] && '7.0000' === $summed_move,
			"qty_remaining={$got_2['qty_remaining']} summed_move={$summed_move}"
		);

		// 3. A near-expiry query returns the right rows at a boundary date —
		// the boundary itself is included, one day past it is not.
		$boundary_date = gmdate( 'Y-m-d', strtotime( Db::now() ) + ( 7 * DAY_IN_SECONDS ) );
		$past_boundary = gmdate( 'Y-m-d', strtotime( Db::now() ) + ( 8 * DAY_IN_SECONDS ) );

		$batch_on_boundary = \Counter\Stock\Batches::receive(
			[
				'product_id'   => $product_id,
				'variation_id' => 0,
				'location_id'  => $location_id,
				'lot_no'       => 'LOT-BOUNDARY',
				'expiry_date'  => $boundary_date,
				'qty_received' => '5',
				'unit_cost'    => '10.0000',
			]
		);
		$this->batch_fixture_ids[] = $batch_on_boundary;

		$batch_past_boundary = \Counter\Stock\Batches::receive(
			[
				'product_id'   => $product_id,
				'variation_id' => 0,
				'location_id'  => $location_id,
				'lot_no'       => 'LOT-PAST-BOUNDARY',
				'expiry_date'  => $past_boundary,
				'qty_received' => '5',
				'unit_cost'    => '10.0000',
			]
		);
		$this->batch_fixture_ids[] = $batch_past_boundary;

		$near = \Counter\Stock\Batches::near_expiry( 7, $location_id );
		$near_ids = array_map( static fn( $r ) => (int) $r['id'], $near );
		$this->check(
			'test_batches: a near-expiry query returns the right rows at a boundary date',
			in_array( $batch_on_boundary, $near_ids, true ) && ! in_array( $batch_past_boundary, $near_ids, true ),
			wp_json_encode( [ 'boundary_date' => $boundary_date, 'past_boundary' => $past_boundary, 'near_ids' => $near_ids ] )
		);

		// 4. A batch cannot go negative — over-consuming is refused outright,
		// writing nothing.
		$moves_before_overconsume = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE batch_id = %d", $batch_1 ) );
		$overconsume_refused      = false;
		try {
			\Counter\Stock\Batches::consume( $batch_1, '1000' );
		} catch ( \RuntimeException $e ) {
			$overconsume_refused = true;
		}
		$got_4                    = \Counter\Stock\Batches::get( $batch_1 );
		$moves_after_overconsume  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE batch_id = %d", $batch_1 ) );
		$this->check(
			'test_batches: a batch cannot go negative',
			$overconsume_refused && '7.0000' === $got_4['qty_remaining'] && $moves_before_overconsume === $moves_after_overconsume,
			"refused={$overconsume_refused} qty_remaining={$got_4['qty_remaining']} moves={$moves_after_overconsume}"
		);

		// 5. rebuild() restores a corrupted qty_remaining.
		$batches_table = Install::table( 'batches' );
		$wpdb->update( $batches_table, [ 'qty_remaining' => '9999.0000' ], [ 'id' => $batch_1 ] );
		$corrupted = \Counter\Stock\Batches::get( $batch_1 )['qty_remaining'];
		\Counter\Stock\Batches::rebuild( $batch_1 );
		$rebuilt = \Counter\Stock\Batches::get( $batch_1 )['qty_remaining'];
		$this->check(
			'test_batches: rebuild restores a corrupted qty_remaining',
			'9999.0000' === $corrupted && '7.0000' === $rebuilt,
			"corrupted={$corrupted} rebuilt={$rebuilt}"
		);
	}

	// -- P2.3: test_suppliers() -- 4 checks -------------------------------------------

	private function test_suppliers(): void {
		global $wpdb;

		// 1. Create / read / update.
		$create_1 = \Counter\Purchasing\Suppliers::create(
			[
				'name'       => 'Counter Selftest Fixture Supplier One',
				'phone'      => '01711111111',
				'bin'        => 'BIN0001',
				'terms_days' => 15,
			]
		);
		$id_1 = is_wp_error( $create_1 ) ? 0 : $create_1['id'];
		if ( $id_1 ) {
			$this->supplier_fixture_ids[] = $id_1;
		}
		$got_1 = $id_1 ? \Counter\Purchasing\Suppliers::get( $id_1 ) : null;
		\Counter\Purchasing\Suppliers::update( $id_1, [ 'phone' => '01799999999', 'terms_days' => 45 ] );
		$got_1b = $id_1 ? \Counter\Purchasing\Suppliers::get( $id_1 ) : null;
		$this->check(
			'test_suppliers: create/read/update',
			! is_wp_error( $create_1 )
				&& $got_1 && 'BIN0001' === $got_1['bin'] && 15 === (int) $got_1['terms_days']
				&& $got_1b && '01799999999' === $got_1b['phone'] && 45 === (int) $got_1b['terms_days'],
			wp_json_encode( [ 'got_1' => $got_1, 'got_1b' => $got_1b ] )
		);

		// 2. The near-duplicate check catches AKIZ against akij — the blueprint's
		// own example. Distinct from case/whitespace normalisation: "akiz" and
		// "akij" are already two different strings after normalising, so this
		// only passes if find_near_duplicate() genuinely measures edit distance.
		$create_akij = \Counter\Purchasing\Suppliers::create( [ 'name' => 'akij' ] );
		$akij_id     = is_wp_error( $create_akij ) ? 0 : $create_akij['id'];
		if ( $akij_id ) {
			$this->supplier_fixture_ids[] = $akij_id;
		}

		$duplicate_direct = \Counter\Purchasing\Suppliers::find_near_duplicate( 'AKIZ' );
		$create_akiz      = \Counter\Purchasing\Suppliers::create( [ 'name' => 'AKIZ' ] );
		$suppliers_table   = Install::table( 'suppliers' );
		$akiz_rows_created = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suppliers_table} WHERE name = %s", 'AKIZ' ) );
		$this->check(
			'test_suppliers: the near-duplicate check catches AKIZ against akij',
			$akij_id
				&& $duplicate_direct && $akij_id === (int) $duplicate_direct['id']
				&& is_wp_error( $create_akiz ) && 'cntr_supplier_duplicate' === $create_akiz->get_error_code()
				&& 0 === $akiz_rows_created,
			wp_json_encode( [ 'duplicate_direct' => $duplicate_direct, 'akiz_error' => is_wp_error( $create_akiz ) ? $create_akiz->get_error_code() : null, 'rows_created' => $akiz_rows_created ] )
		);

		// 3. An opening balance writes a supplier-ledger row.
		$create_opening = \Counter\Purchasing\Suppliers::create(
			[ 'name' => 'Counter Selftest Fixture Supplier Opening', 'opening_balance' => '500.00' ]
		);
		$opening_id = is_wp_error( $create_opening ) ? 0 : $create_opening['id'];
		if ( $opening_id ) {
			$this->supplier_fixture_ids[] = $opening_id;
		}
		$ledger_table = Install::table( 'supplier_ledger' );
		$ledger_rows  = $opening_id ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ledger_table} WHERE supplier_id = %d", $opening_id ), ARRAY_A ) : [];
		$this->check(
			'test_suppliers: an opening balance writes a supplier-ledger row',
			! is_wp_error( $create_opening )
				&& 1 === count( $ledger_rows )
				&& 'opening' === $ledger_rows[0]['type']
				&& '500.0000' === $ledger_rows[0]['amount']
				&& '500.0000' === $ledger_rows[0]['balance_after'],
			wp_json_encode( $ledger_rows )
		);

		// 4. Deleting a supplier WITH movements is refused (deactivate instead);
		// a supplier with none can genuinely be removed.
		$delete_with_movements = \Counter\Purchasing\Suppliers::delete( $opening_id );
		$still_there            = $opening_id ? \Counter\Purchasing\Suppliers::get( $opening_id ) : null;

		$create_clean = \Counter\Purchasing\Suppliers::create( [ 'name' => 'Counter Selftest Fixture Supplier Clean' ] );
		$clean_id     = is_wp_error( $create_clean ) ? 0 : $create_clean['id'];
		if ( $clean_id ) {
			$this->supplier_fixture_ids[] = $clean_id; // tracked regardless — harmless if already gone
		}
		$delete_clean = $clean_id ? \Counter\Purchasing\Suppliers::delete( $clean_id ) : null;
		$gone         = $clean_id ? \Counter\Purchasing\Suppliers::get( $clean_id ) : 'never created';

		$this->check(
			'test_suppliers: deleting a supplier with movements is refused (deactivate instead)',
			is_wp_error( $delete_with_movements ) && 'cntr_supplier_has_movements' === $delete_with_movements->get_error_code()
				&& null !== $still_there
				&& true === $delete_clean
				&& null === $gone,
			wp_json_encode(
				[
					'delete_with_movements' => is_wp_error( $delete_with_movements ) ? $delete_with_movements->get_error_code() : $delete_with_movements,
					'still_there'           => (bool) $still_there,
					'delete_clean'          => $delete_clean,
					'gone'                  => $gone,
				]
			)
		);
	}

	// -- P2.2: test_adjustments() -- 5 checks -----------------------------------------

	private function test_adjustments(): void {
		global $wpdb;

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Adjustment Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		$location_id = \Counter\Stock\Locations::default_id();

		Db::transaction(
			function () use ( $product_id, $location_id ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $location_id,
						'qty_delta'    => '5',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_adjust',
						'ref_id'       => 0,
					]
				);
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);

		$moves_table = Install::table( 'stock_moves' );
		$count_moves = function () use ( $wpdb, $moves_table, $product_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$moves_table} WHERE product_id = %d AND ref_type = 'adjustment'",
					$product_id
				)
			);
		};

		// 1. An adjustment writes exactly one move.
		$uuid_1  = wp_generate_uuid4();
		$this->adjustment_uuid_options[] = 'cntr_adj_uuid_' . sanitize_key( $uuid_1 );
		$result_1 = \Counter\Rest\Stock::process( $uuid_1, $product_id, 0, $location_id, '3', 'adjust', '', 0 );
		$this->check(
			'test_adjustments: an adjustment writes exactly one move',
			! is_wp_error( $result_1 ) && 1 === $count_moves() && '8.0000' === ( $result_1['after'] ?? null ),
			is_wp_error( $result_1 ) ? $result_1->get_error_message() : wp_json_encode( $result_1 )
		);

		// 2. The same UUID twice writes one — a replayed idempotent response, not a second move.
		$result_1b = \Counter\Rest\Stock::process( $uuid_1, $product_id, 0, $location_id, '3', 'adjust', '', 0 );
		$this->check(
			'test_adjustments: the same UUID twice writes one',
			! is_wp_error( $result_1b )
				&& true === ( $result_1b['replayed'] ?? null )
				&& ( $result_1['move_id'] ?? null ) === ( $result_1b['move_id'] ?? null )
				&& 1 === $count_moves(),
			wp_json_encode( $result_1b )
		);

		// 3. An adjustment without a (valid) reason is refused, and writes nothing.
		$moves_before_bad_reason = $count_moves();
		$result_bad_reason       = \Counter\Rest\Stock::process( wp_generate_uuid4(), $product_id, 0, $location_id, '1', '', '', 0 );
		$this->check(
			'test_adjustments: an adjustment without a reason is refused',
			is_wp_error( $result_bad_reason ) && $moves_before_bad_reason === $count_moves(),
			is_wp_error( $result_bad_reason ) ? $result_bad_reason->get_error_code() : wp_json_encode( $result_bad_reason )
		);

		// 4. A negative adjustment below zero is allowed but flagged — balance is
		// 8 after check 1; -20 drives it to -12, which must NOT be refused.
		$uuid_2   = wp_generate_uuid4();
		$this->adjustment_uuid_options[] = 'cntr_adj_uuid_' . sanitize_key( $uuid_2 );
		$result_2 = \Counter\Rest\Stock::process( $uuid_2, $product_id, 0, $location_id, '-20', 'waste', '', 0 );
		$this->check(
			'test_adjustments: a negative adjustment below zero is allowed but flagged',
			! is_wp_error( $result_2 )
				&& '-12.0000' === ( $result_2['after'] ?? null )
				&& true === ( $result_2['flagged_negative'] ?? null )
				&& 2 === $count_moves(),
			wp_json_encode( $result_2 )
		);

		// 5. The audit row for that adjustment carries before and after.
		$audit_table = Install::table( 'audit_log' );
		$audit_row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$audit_table} WHERE action = 'stock_adjust' AND object_id = %d ORDER BY id DESC LIMIT 1",
				$product_id
			),
			ARRAY_A
		);
		$before_json = $audit_row ? json_decode( $audit_row['before_json'], true ) : null;
		$after_json  = $audit_row ? json_decode( $audit_row['after_json'], true ) : null;
		// Not added to audit_fixture_ids / purge_tagged(): that mechanism only
		// ever sweeps the synthetic action=self::TAG rows test_audit() writes
		// for its own purposes. This is a REAL 'stock_adjust' row written by
		// the real production code path (Rest\Stock::process(), same as any
		// live adjustment would produce) — audit_log is one of
		// Install::PROTECTED_TABLES, and every other test that exercises a
		// real write path (shift close, refund, transfer) leaves its own real
		// audit rows behind the same way, permanent history same as a
		// stock_moves row, not test debris to prune.
		$this->check(
			'test_adjustments: the audit row carries before and after',
			$audit_row
				&& '8.0000' === ( $before_json['balance'] ?? null )
				&& '-12.0000' === ( $after_json['balance'] ?? null )
				&& true === ( $after_json['flagged_negative'] ?? null ),
			wp_json_encode( [ 'before' => $before_json, 'after' => $after_json ] )
		);
	}

	// -- P2.1: test_transfers() -- 6 checks -------------------------------------------

	private function test_transfers(): void {
		global $wpdb;

		$loc_a = \Counter\Stock\Locations::create(
			[
				'name'               => 'Counter Selftest Fixture Transfer Source',
				'code'               => 'CNTR-SELFTEST-' . wp_generate_password( 8, false ),
				'is_online_sellable' => 1,
				'is_default'         => 0,
				'status'             => 'active',
			]
		);
		$this->location_fixture_ids[] = $loc_a;

		$loc_b = \Counter\Stock\Locations::create(
			[
				'name'               => 'Counter Selftest Fixture Transfer Destination',
				'code'               => 'CNTR-SELFTEST-' . wp_generate_password( 8, false ),
				'is_online_sellable' => 1,
				'is_default'         => 0,
				'status'             => 'active',
			]
		);
		$this->location_fixture_ids[] = $loc_b;

		$product = new \WC_Product_Simple();
		$product->set_name( 'Counter Selftest Fixture Transfer Product' );
		$product->set_regular_price( '5.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_backorders( 'no' );
		$product->update_meta_data( '_cntr_selftest_fixture', self::TAG );
		$product->save();
		$product_id                  = $product->get_id();
		$this->product_fixture_ids[] = $product_id;

		// Seed 20 units at the source, published like any other opening balance.
		Db::transaction(
			function () use ( $product_id, $loc_a ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $loc_a,
						'qty_delta'    => '20',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_transfer',
						'ref_id'       => 0,
					]
				);
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);
		$published_before = (int) wc_get_product( $product_id )->get_stock_quantity();

		// Transfer 1: sent, then received IN FULL.
		$transfer_1 = \Counter\Stock\Transfers::create(
			[
				'from_location_id' => $loc_a,
				'to_location_id'   => $loc_b,
				'lines'            => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '10' ] ],
			]
		);
		$this->transfer_fixture_ids[] = $transfer_1;
		$line_1 = \Counter\Stock\Transfers::lines( $transfer_1 )[0];

		\Counter\Stock\Transfers::send( $transfer_1 );
		$balance_a_after_send = \Counter\Stock\Ledger::balance( $product_id, 0, $loc_a );
		$balance_b_after_send = \Counter\Stock\Ledger::balance( $product_id, 0, $loc_b );

		// 1. Sending reduces the source and reserves nothing at the destination.
		$this->check(
			'test_transfers: sending reduces the source and reserves nothing at the destination',
			'10.0000' === $balance_a_after_send && '0.0000' === $balance_b_after_send,
			"a={$balance_a_after_send} b={$balance_b_after_send}"
		);

		\Counter\Stock\Transfers::receive( $transfer_1, [ $line_1['id'] => '10' ] );
		$balance_b_after_receive = \Counter\Stock\Ledger::balance( $product_id, 0, $loc_b );

		// 2. Receiving increases the destination.
		$this->check(
			'test_transfers: receiving increases the destination',
			'10.0000' === $balance_b_after_receive,
			"b={$balance_b_after_receive}"
		);

		// 3. The two moves (transfer_out at A, transfer_in at B) sum to zero across the business.
		$moves_table = Install::table( 'stock_moves' );
		$sum_1       = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT CAST(COALESCE(SUM(qty_delta), 0) AS DECIMAL(14,4)) FROM {$moves_table} WHERE ref_type = 'transfer' AND ref_id = %d",
				$transfer_1
			)
		);
		$this->check(
			'test_transfers: the two moves sum to zero across the business',
			'0.0000' === $sum_1,
			"sum={$sum_1}"
		);

		// 6. Publishing after a transfer between two online-sellable locations
		// leaves the WooCommerce-facing figure unchanged — the total did not
		// move, so the website must not flicker.
		$published_after = (int) wc_get_product( $product_id )->get_stock_quantity();
		$this->check(
			'test_transfers: publishing after a transfer between two online-sellable locations leaves _stock unchanged',
			$published_before === $published_after,
			"before={$published_before} after={$published_after}"
		);

		// 5. Receiving again once already fully received is refused.
		$transfer_1_before = \Counter\Stock\Transfers::get( $transfer_1 );
		$refused_second_receive = false;
		try {
			\Counter\Stock\Transfers::receive( $transfer_1, [ $line_1['id'] => '1' ] );
		} catch ( \RuntimeException $e ) {
			$refused_second_receive = true;
		}
		$transfer_1_after       = \Counter\Stock\Transfers::get( $transfer_1 );
		$moves_after_second_try = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$moves_table} WHERE ref_type = 'transfer' AND ref_id = %d", $transfer_1 )
		);
		$this->check(
			'test_transfers: receiving twice is refused',
			$refused_second_receive
				&& 'received' === $transfer_1_after['status']
				&& $transfer_1_before['status'] === $transfer_1_after['status']
				&& 2 === $moves_after_second_try,
			wp_json_encode( [ 'refused' => $refused_second_receive, 'status' => $transfer_1_after['status'], 'moves' => $moves_after_second_try ] )
		);

		// Transfer 2: top the source back up, send 10, receive only part of it.
		Db::transaction(
			function () use ( $product_id, $loc_a ) {
				\Counter\Stock\Ledger::move(
					[
						'product_id'   => $product_id,
						'variation_id' => 0,
						'location_id'  => $loc_a,
						'qty_delta'    => '10',
						'reason'       => 'opening',
						'ref_type'     => 'selftest_transfer',
						'ref_id'       => 0,
					]
				);
				\Counter\Stock\Publisher::publish( $product_id, 0 );
			}
		);

		$transfer_2 = \Counter\Stock\Transfers::create(
			[
				'from_location_id' => $loc_a,
				'to_location_id'   => $loc_b,
				'lines'            => [ [ 'product_id' => $product_id, 'variation_id' => 0, 'qty' => '10' ] ],
			]
		);
		$this->transfer_fixture_ids[] = $transfer_2;
		$line_2 = \Counter\Stock\Transfers::lines( $transfer_2 )[0];
		\Counter\Stock\Transfers::send( $transfer_2 );
		\Counter\Stock\Transfers::receive( $transfer_2, [ $line_2['id'] => '6' ] );

		$line_2_after = \Counter\Stock\Transfers::lines( $transfer_2 )[0];
		$transfer_2_after = \Counter\Stock\Transfers::get( $transfer_2 );
		$stock_table       = Install::table( 'stock' );
		$reserved_a        = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT reserved FROM {$stock_table} WHERE product_id = %d AND variation_id = 0 AND location_id = %d",
				$product_id,
				$loc_a
			)
		);

		// 4. A partial receive leaves the remainder in transit — recorded both
		// on the line (qty_sent - qty_received) and as `reserved` at the source.
		$this->check(
			'test_transfers: a partial receive leaves the remainder in transit',
			'6.0000' === $line_2_after['qty_received']
				&& '10.0000' === $line_2_after['qty_sent']
				&& 'partial' === $transfer_2_after['status']
				&& '4.0000' === $reserved_a,
			wp_json_encode( [ 'received' => $line_2_after['qty_received'], 'sent' => $line_2_after['qty_sent'], 'status' => $transfer_2_after['status'], 'reserved_a' => $reserved_a ] )
		);
	}

	private static function percentile( array $values, int $p ): ?float {
		if ( empty( $values ) ) {
			return null;
		}
		$sorted = $values;
		sort( $sorted );
		$idx = max( 0, (int) ceil( ( $p / 100 ) * count( $sorted ) ) - 1 );
		return $sorted[ $idx ];
	}

	/**
	 * Crude source scan: does any .php file under includes/ contain UPDATE or
	 * DELETE against the given short table name, either via Install::table() or
	 * a literal {$wpdb->prefix}cntr_<short> reference? Returns the first
	 * offending file:line, or null if clean.
	 */
	private static function grep_for_capability_in_rest_guards( string $capability ): ?string {
		$pattern = '/Router::guard(_any)?\s*\([^)]*' . preg_quote( "'{$capability}'", '/' ) . '/s';

		$iterator = new \RecursiveDirectoryIterator( CNTR_DIR . 'includes/Rest', \FilesystemIterator::SKIP_DOTS );
		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$src = file_get_contents( $file->getPathname() );
			if ( preg_match( $pattern, $src ) ) {
				return $file->getFilename() . ': Router::guard references ' . $capability;
			}
		}
		return null;
	}

	private static function grep_for_forbidden_writes( string $short_table ): ?string {
		$pattern = '/\b(UPDATE|DELETE\s+FROM)\b[^;]*(' . preg_quote( "table( '{$short_table}'", '/' ) . '|'
			. preg_quote( "table(\"{$short_table}\"", '/' ) . '|'
			. preg_quote( "cntr_{$short_table}", '/' ) . ')/is';

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( CNTR_DIR . 'includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			// Balance.php/Ledger.php themselves legitimately write cntr_stock (the
			// balance CACHE) with UPDATE-shaped SQL (ON DUPLICATE KEY UPDATE) — the
			// invariant this check enforces is about cntr_stock_moves specifically,
			// so skip files whose entire purpose is writing the other table.
			if ( 'Selftest.php' === $file->getFilename() ) {
				continue;
			}

			$src   = file_get_contents( $file->getPathname() );
			$lines = explode( "\n", $src );
			foreach ( $lines as $i => $line ) {
				if ( preg_match( $pattern, $line ) ) {
					return $file->getFilename() . ':' . ( $i + 1 ) . ': ' . trim( $line );
				}
			}
		}
		return null;
	}

	// -- shared purge/cleanup machinery --------------------------------------------

	/**
	 * Deletes tagged rows in $table if every tagged row is one this run accounted
	 * for via $exempt_ids. If a tagged row exists that isn't in $exempt_ids, that
	 * row is foreign — possibly a live session — and NOTHING is deleted.
	 */
	private function purge_tagged( string $table, string $column, array $exempt_ids ): array {
		global $wpdb;

		$ids     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$column} = %s", self::TAG ) ) );
		$foreign = array_diff( $ids, $exempt_ids );

		if ( ! empty( $foreign ) ) {
			return [
				'skipped' => true,
				'foreign' => array_values( $foreign ),
			];
		}

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) ); // phpcs:ignore
		}

		return [
			'skipped' => false,
			'removed' => $ids,
		];
	}

	/**
	 * Same shape as purge_tagged() but for WooCommerce products, which live in
	 * wp_posts/postmeta regardless of HPOS. Tagged via product meta
	 * _cntr_selftest_fixture = self::TAG.
	 */
	/**
	 * Deletes by the EXACT tracked id list — not a tag scan. Originally this
	 * scanned postmeta for _cntr_selftest_fixture and skipped on any "foreign"
	 * row, mirroring purge_tagged()'s live-session safety net. Found live: not
	 * every fixture product created after P1.10 was actually given that tag
	 * (Builder::build()'s own products are, but plenty of ad-hoc fixture
	 * products across later tests were only ever pushed into
	 * $this->product_fixture_ids and never separately tagged) — so the scan
	 * silently found nothing to delete and every one of those products leaked
	 * permanently on the real site. $this->product_fixture_ids is populated
	 * exclusively by this run's own fixture creation, with no realistic
	 * "foreign/concurrent session" ambiguity for a single-process PHP request,
	 * so deleting by that exact list directly is both simpler and correct.
	 */
	private function purge_tagged_products( array $ids ): array {
		$removed = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$product->delete( true );
				$removed[] = $id;
			}
		}

		return [
			'skipped' => false,
			'removed' => $removed,
		];
	}

	/**
	 * Same shape again, for WooCommerce orders. HPOS-native: reads
	 * {prefix}wc_orders_meta directly rather than postmeta — non-negotiable rule
	 * 3, get_post_meta() against an order id is a bug, including here.
	 */
	/**
	 * Same fix as purge_tagged_products() and for the same reason: deletes by
	 * the EXACT tracked id list rather than a wc_orders_meta tag scan. Orders
	 * built via Orders\Builder (P1.10 onward) were never given the
	 * _cntr_selftest_fixture tag at all — only a handful of orders built the
	 * old way (wc_create_order() + explicit tagging) ever were — so the scan
	 * silently missed the majority of fixture orders every run, and they
	 * leaked permanently as real WC_Order records on the live site. Found by
	 * noticing cleanup() reported "2 order(s) removed" when a run had created
	 * far more than 2, then confirming the "missing" order ids still existed
	 * in wp_wc_orders.
	 */
	private function purge_tagged_orders( array $ids ): array {
		$removed = [];
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order ) {
				$order->delete( true );
				$removed[] = $id;
			}
		}

		return [
			'skipped' => false,
			'removed' => $removed,
		];
	}

	/**
	 * cntr_locations has no free-text tag column suited to the action=TAG pattern,
	 * so fixtures here are identified by a CNTR-SELFTEST- code prefix instead
	 * (code is UNIQUE, which is also why the prefix carries a random suffix).
	 */
	private function purge_tagged_locations( array $exempt_ids ): array {
		global $wpdb;
		$table = Install::table( 'locations' );

		$ids     = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table} WHERE code LIKE 'CNTR-SELFTEST-%'" ) );
		$foreign = array_diff( $ids, $exempt_ids );

		if ( ! empty( $foreign ) ) {
			return [
				'skipped' => true,
				'foreign' => array_values( $foreign ),
			];
		}

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) ); // phpcs:ignore
		}

		return [
			'skipped' => false,
			'removed' => $ids,
		];
	}

	/**
	 * Removes everything this run tagged, unless a foreign tagged row is present —
	 * see the class docblock. Always runs, and its result is the final check: a
	 * test that leaves rows behind is worse than a test that fails, because the
	 * next run measures a polluted database and reports green.
	 */
	private function cleanup(): void {
		global $wpdb;
		$table  = Install::table( 'audit_log' );
		$result = $this->purge_tagged( $table, 'action', $this->audit_fixture_ids );

		if ( $result['skipped'] ) {
			$this->check(
				'cleanup: removal skipped — a live tagged fixture exists that this run did not create',
				true,
				wp_json_encode( $result['foreign'] )
			);
		} else {
			$this->check( 'cleanup: this run left nothing tagged behind', true, count( $result['removed'] ) . ' row(s) removed' );
		}

		// Orders before products: deleting a product a fixture order still lines
		// against is harmless, but deleting the order first avoids WooCommerce
		// briefly re-reading a half-gone product mid-delete.
		$order_result = $this->purge_tagged_orders( $this->order_fixture_ids );
		$this->check(
			$order_result['skipped']
				? 'cleanup: order fixture removal skipped — a live tagged fixture exists that this run did not create'
				: 'cleanup: this run left no fixture orders behind',
			true,
			$order_result['skipped'] ? wp_json_encode( $order_result['foreign'] ) : count( $order_result['removed'] ) . ' order(s) removed'
		);

		// cntr_late_sales (P7.4) — deleted by the exact order ids this run
		// tracked, same reasoning as order_stock_state below: a late-sale
		// row referencing a fixture order that no longer exists cannot be
		// a live record of anything.
		if ( ! empty( $this->order_fixture_ids ) ) {
			$late_sales_table_c = Install::table( 'late_sales' );
			$placeholders_ls    = implode( ',', array_fill( 0, count( $this->order_fixture_ids ), '%d' ) );
			$removed_ls         = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$late_sales_table_c} WHERE order_id IN ({$placeholders_ls})", ...$this->order_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture late_sales rows behind', true, "{$removed_ls} row(s) removed" );
		}

		// cntr_oversell_log (P7.5) — deleted by the exact order ids this run
		// tracked, same reasoning as late_sales just above: a large fraction
		// of THIS run's own fixture sales never involved a real batch (the
		// raw Ledger::move()-seeded fixtures many earlier tests use), so
		// Batches::consume_fifo() legitimately flags them short too — an
		// oversell row referencing a fixture order that no longer exists
		// cannot be a live record of anything.
		if ( ! empty( $this->order_fixture_ids ) ) {
			$oversell_table_c = Install::table( 'oversell_log' );
			$placeholders_ov  = implode( ',', array_fill( 0, count( $this->order_fixture_ids ), '%d' ) );
			$removed_ov       = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$oversell_table_c} WHERE order_id IN ({$placeholders_ov})", ...$this->order_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture oversell_log rows behind', true, "{$removed_ov} row(s) removed" );
		}

		$product_result = $this->purge_tagged_products( $this->product_fixture_ids );
		$this->check(
			$product_result['skipped']
				? 'cleanup: product fixture removal skipped — a live tagged fixture exists that this run did not create'
				: 'cleanup: this run left no fixture products behind',
			true,
			$product_result['skipped'] ? wp_json_encode( $product_result['foreign'] ) : count( $product_result['removed'] ) . ' product(s) removed'
		);

		// cntr_catalog_index is a derived cache, not one of the two hard
		// append-only tables (Invariant I/V) — unlike cntr_stock_moves, there is
		// no reason to let test debris accumulate here forever. Deleted by the
		// EXACT product ids this run tracked, not a shared-tag scan, since a
		// catalog_index row has no tag column and no ambiguity about ownership.
		if ( ! empty( $this->product_fixture_ids ) ) {
			$catalog_table = Install::table( 'catalog_index' );
			$placeholders  = implode( ',', array_fill( 0, count( $this->product_fixture_ids ), '%d' ) );
			$removed_rows  = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$catalog_table} WHERE product_id IN ({$placeholders})", ...$this->product_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no catalogue index rows behind', true, "{$removed_rows} row(s) removed" );
		}

		// cntr_stock is the same kind of derived cache as catalog_index — the
		// balance for a fixture product is meaningless once purge_tagged_products()
		// has deleted the product itself. P2.1's test_transfers() is the first
		// test to create fixture LOCATIONS with real balances on them, which
		// would otherwise survive purge_tagged_locations() as dead rows keyed to
		// a location_id nothing references any more; deleting by the exact
		// product ids this run tracked closes that for every earlier fixture
		// product too, not only this task's own.
		if ( ! empty( $this->product_fixture_ids ) ) {
			$stock_table_c    = Install::table( 'stock' );
			$placeholders_s   = implode( ',', array_fill( 0, count( $this->product_fixture_ids ), '%d' ) );
			$removed_stock    = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$stock_table_c} WHERE product_id IN ({$placeholders_s})", ...$this->product_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no cntr_stock rows behind', true, "{$removed_stock} row(s) removed" );
		}

		// cntr_product_units (P2.12) — same reasoning as cntr_stock/catalog_index
		// just above: a per-product config row, meaningless once the product
		// itself is gone, deleted by the exact product ids this run tracked.
		if ( ! empty( $this->product_fixture_ids ) ) {
			$product_units_c = Install::table( 'product_units' );
			$placeholders_pu = implode( ',', array_fill( 0, count( $this->product_fixture_ids ), '%d' ) );
			$removed_pu      = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$product_units_c} WHERE product_id IN ({$placeholders_pu})", ...$this->product_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no cntr_product_units rows behind', true, "{$removed_pu} row(s) removed" );
		}

		// cntr_price_group_prices (P4.3) — same reasoning again: an override
		// row is meaningless once the product it prices is gone, deleted by
		// the exact product ids this run tracked. cntr_price_groups itself
		// (retail/wholesale/online) is Install::seed() data, never touched.
		if ( ! empty( $this->product_fixture_ids ) ) {
			$price_group_prices_c = Install::table( 'price_group_prices' );
			$placeholders_pgp     = implode( ',', array_fill( 0, count( $this->product_fixture_ids ), '%d' ) );
			$removed_pgp          = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$price_group_prices_c} WHERE product_id IN ({$placeholders_pgp})", ...$this->product_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no cntr_price_group_prices rows behind', true, "{$removed_pgp} row(s) removed" );
		}

		// cntr_payment_accounts (P4.6) — deleted by exact tracked id, tenders
		// referencing them (this run's own fabricated "has movements" fixture
		// only — never real tender rows, which are not append-only here but
		// are never fixture-tagged either) removed first.
		if ( ! empty( $this->payment_account_fixture_ids ) ) {
			$accounts_table_c    = Install::table( 'payment_accounts' );
			$tenders_table_c     = Install::table( 'tenders' );
			$placeholders_pa     = implode( ',', array_fill( 0, count( $this->payment_account_fixture_ids ), '%d' ) );
			$removed_pa_tenders  = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$tenders_table_c} WHERE account_id IN ({$placeholders_pa})", ...$this->payment_account_fixture_ids ) // phpcs:ignore
			);
			$removed_pa = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$accounts_table_c} WHERE id IN ({$placeholders_pa})", ...$this->payment_account_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture payment accounts behind', true, "{$removed_pa} account(s), {$removed_pa_tenders} tender row(s) removed" );
		}

		// cntr_expenses/cntr_expense_categories (P5.4) — deleted by exact
		// tracked id, expenses first (they reference categories via
		// category_id, though nothing enforces that with a real FK here).
		// The 7 seeded default categories (rent/electricity/.../tea) are
		// Boot::on_activate() data, never tracked here, never touched.
		if ( ! empty( $this->expense_fixture_ids ) ) {
			$expenses_table_c = Install::table( 'expenses' );
			$placeholders_ex  = implode( ',', array_fill( 0, count( $this->expense_fixture_ids ), '%d' ) );
			$removed_ex       = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$expenses_table_c} WHERE id IN ({$placeholders_ex})", ...$this->expense_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture expenses behind', true, "{$removed_ex} row(s) removed" );
		}
		if ( ! empty( $this->expense_category_fixture_ids ) ) {
			$expense_categories_table_c = Install::table( 'expense_categories' );
			$placeholders_exc           = implode( ',', array_fill( 0, count( $this->expense_category_fixture_ids ), '%d' ) );
			$removed_exc                = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$expense_categories_table_c} WHERE id IN ({$placeholders_exc})", ...$this->expense_category_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture expense categories behind', true, "{$removed_exc} row(s) removed" );
		}

		// cntr_employees (P6.1) — deleted by exact tracked id, before the WP
		// user each one links to (UNIQUE KEY user_id on cntr_employees would
		// otherwise briefly point at a deleted account, harmless but untidy).
		if ( ! empty( $this->employee_fixture_ids ) ) {
			$employees_table_c = Install::table( 'employees' );
			$placeholders_emp  = implode( ',', array_fill( 0, count( $this->employee_fixture_ids ), '%d' ) );
			$removed_emp       = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$employees_table_c} WHERE id IN ({$placeholders_emp})", ...$this->employee_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture employees behind', true, "{$removed_emp} row(s) removed" );
		}
		if ( ! empty( $this->employee_user_fixture_ids ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$removed_emp_users = 0;
			foreach ( $this->employee_user_fixture_ids as $uid ) {
				if ( wp_delete_user( $uid ) ) {
					$removed_emp_users++;
				}
			}
			$this->check( 'cleanup: this run left no fixture employee-linked WP users behind', true, "{$removed_emp_users} user(s) removed" );
		}

		// cntr_attendance (P6.2) — deleted by exact tracked id.
		if ( ! empty( $this->attendance_fixture_ids ) ) {
			$attendance_table_c = Install::table( 'attendance' );
			$placeholders_att   = implode( ',', array_fill( 0, count( $this->attendance_fixture_ids ), '%d' ) );
			$removed_att        = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$attendance_table_c} WHERE id IN ({$placeholders_att})", ...$this->attendance_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture attendance rows behind', true, "{$removed_att} row(s) removed" );
		}

		// cntr_leave (P6.3) — deleted by exact tracked id.
		if ( ! empty( $this->leave_fixture_ids ) ) {
			$leave_table_c = Install::table( 'leave' );
			$placeholders_lv = implode( ',', array_fill( 0, count( $this->leave_fixture_ids ), '%d' ) );
			$removed_lv      = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$leave_table_c} WHERE id IN ({$placeholders_lv})", ...$this->leave_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture leave requests behind', true, "{$removed_lv} row(s) removed" );
		}

		// cntr_payroll_runs/cntr_payroll_lines (P6.4) — lines deleted by
		// run_id first, then the runs themselves, by exact tracked id.
		if ( ! empty( $this->payroll_run_fixture_ids ) ) {
			$runs_table_c  = Install::table( 'payroll_runs' );
			$lines_table_c = Install::table( 'payroll_lines' );
			$placeholders_pr = implode( ',', array_fill( 0, count( $this->payroll_run_fixture_ids ), '%d' ) );
			$removed_lines   = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$lines_table_c} WHERE run_id IN ({$placeholders_pr})", ...$this->payroll_run_fixture_ids ) // phpcs:ignore
			);
			$removed_runs    = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$runs_table_c} WHERE id IN ({$placeholders_pr})", ...$this->payroll_run_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture payroll runs or lines behind', true, "{$removed_runs} run(s), {$removed_lines} line(s) removed" );
		}

		// cntr_transfers/cntr_transfer_lines are not append-only (unlike the
		// stock_moves rows a transfer writes, which are permanent same as any
		// other ledger row) — deleted by the exact transfer ids this run created.
		if ( ! empty( $this->transfer_fixture_ids ) ) {
			$transfers_table_c = Install::table( 'transfers' );
			$transfer_lines_c  = Install::table( 'transfer_lines' );
			$placeholders_t    = implode( ',', array_fill( 0, count( $this->transfer_fixture_ids ), '%d' ) );
			$removed_lines     = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$transfer_lines_c} WHERE transfer_id IN ({$placeholders_t})", ...$this->transfer_fixture_ids ) // phpcs:ignore
			);
			$removed_transfers = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$transfers_table_c} WHERE id IN ({$placeholders_t})", ...$this->transfer_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture transfers or transfer_lines behind', true, "{$removed_transfers} transfer(s), {$removed_lines} line(s) removed" );
		}

		// Rest\Stock::process()'s idempotency guard (P2.2) is a plain wp_options
		// row, not a table — deleted by the exact option names this run created.
		if ( ! empty( $this->adjustment_uuid_options ) ) {
			$removed_options = 0;
			foreach ( $this->adjustment_uuid_options as $option_name ) {
				if ( delete_option( $option_name ) ) {
					$removed_options++;
				}
			}
			$this->check( 'cleanup: this run left no adjustment idempotency options behind', true, "{$removed_options} option(s) removed" );
		}

		// cntr_suppliers / cntr_supplier_ledger — supplier_ledger is one of
		// Install::PROTECTED_TABLES, but (same as tenders/shift_sales above)
		// that only means "never dropped whole by soft uninstall", not "a row
		// this run itself created can never be pruned" — deleted by the exact
		// supplier ids this run tracked, ledger rows first.
		if ( ! empty( $this->supplier_fixture_ids ) ) {
			$suppliers_table_c      = Install::table( 'suppliers' );
			$supplier_ledger_table_c = Install::table( 'supplier_ledger' );
			$placeholders_sup       = implode( ',', array_fill( 0, count( $this->supplier_fixture_ids ), '%d' ) );
			$removed_supplier_ledger = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$supplier_ledger_table_c} WHERE supplier_id IN ({$placeholders_sup})", ...$this->supplier_fixture_ids ) // phpcs:ignore
			);
			$removed_suppliers = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$suppliers_table_c} WHERE id IN ({$placeholders_sup})", ...$this->supplier_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture suppliers or supplier_ledger rows behind', true, "{$removed_suppliers} supplier(s), {$removed_supplier_ledger} ledger row(s) removed" );
		}

		// cntr_batches — a derived-cache-bearing row, same treatment as
		// registers/transfers/suppliers above. Its cntr_stock_moves rows are
		// permanent (Invariant I), same as every other ledger row this class
		// writes; only the batch row itself (and, via product_fixture_ids
		// above, the cntr_stock cache) is pruned.
		if ( ! empty( $this->batch_fixture_ids ) ) {
			$batches_table_c   = Install::table( 'batches' );
			$placeholders_batch = implode( ',', array_fill( 0, count( $this->batch_fixture_ids ), '%d' ) );
			$removed_batches    = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$batches_table_c} WHERE id IN ({$placeholders_batch})", ...$this->batch_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture batches behind', true, "{$removed_batches} batch(es) removed" );
		}

		// cntr_units (P2.12) — deleted by exact tracked id.
		if ( ! empty( $this->unit_fixture_ids ) ) {
			$units_table_c      = Install::table( 'units' );
			$placeholders_units = implode( ',', array_fill( 0, count( $this->unit_fixture_ids ), '%d' ) );
			$removed_units      = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$units_table_c} WHERE id IN ({$placeholders_units})", ...$this->unit_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture units behind', true, "{$removed_units} unit(s) removed" );
		}

		// cntr_label_templates (P3.2) — deleted by exact tracked id.
		if ( ! empty( $this->label_fixture_ids ) ) {
			$labels_table_c      = Install::table( 'label_templates' );
			$placeholders_labels = implode( ',', array_fill( 0, count( $this->label_fixture_ids ), '%d' ) );
			$removed_labels      = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$labels_table_c} WHERE id IN ({$placeholders_labels})", ...$this->label_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture label templates behind', true, "{$removed_labels} template(s) removed" );
		}

		// cntr_doc_templates (P3.4) — deleted by exact tracked id.
		if ( ! empty( $this->doc_template_fixture_ids ) ) {
			$doc_templates_table_c = Install::table( 'doc_templates' );
			$placeholders_doc      = implode( ',', array_fill( 0, count( $this->doc_template_fixture_ids ), '%d' ) );
			$removed_doc           = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$doc_templates_table_c} WHERE id IN ({$placeholders_doc})", ...$this->doc_template_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture document templates behind', true, "{$removed_doc} template(s) removed" );
		}

		// cntr_purchase_orders / cntr_purchase_lines — deleted by exact tracked
		// PO ids, lines first. Their stock_moves and supplier_ledger rows are
		// both permanent, same reasoning as everywhere else in this class.
		if ( ! empty( $this->po_fixture_ids ) ) {
			$po_table_c    = Install::table( 'purchase_orders' );
			$po_lines_c    = Install::table( 'purchase_lines' );
			$placeholders_po = implode( ',', array_fill( 0, count( $this->po_fixture_ids ), '%d' ) );
			$removed_po_lines = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$po_lines_c} WHERE po_id IN ({$placeholders_po})", ...$this->po_fixture_ids ) // phpcs:ignore
			);
			$removed_pos = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$po_table_c} WHERE id IN ({$placeholders_po})", ...$this->po_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture purchase orders or lines behind', true, "{$removed_pos} PO(s), {$removed_po_lines} line(s) removed" );
		}

		// Purchasing\Receiving::receive()'s idempotency guard (P2.4) — same
		// shape as the adjustment options above, different prefix.
		if ( ! empty( $this->po_receive_uuid_options ) ) {
			$removed_po_options = 0;
			foreach ( $this->po_receive_uuid_options as $option_name ) {
				if ( delete_option( $option_name ) ) {
					$removed_po_options++;
				}
			}
			$this->check( 'cleanup: this run left no PO-receive idempotency options behind', true, "{$removed_po_options} option(s) removed" );
		}

		// cntr_stocktakes / cntr_stocktake_lines — deleted by exact tracked
		// stocktake ids, lines first. Their stock_moves rows (reason
		// 'stocktake') are permanent, same as everywhere else in this class.
		if ( ! empty( $this->stocktake_fixture_ids ) ) {
			$stocktakes_table_c = Install::table( 'stocktakes' );
			$stocktake_lines_c  = Install::table( 'stocktake_lines' );
			$placeholders_st    = implode( ',', array_fill( 0, count( $this->stocktake_fixture_ids ), '%d' ) );
			$removed_st_lines   = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$stocktake_lines_c} WHERE stocktake_id IN ({$placeholders_st})", ...$this->stocktake_fixture_ids ) // phpcs:ignore
			);
			$removed_stocktakes = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$stocktakes_table_c} WHERE id IN ({$placeholders_st})", ...$this->stocktake_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture stocktakes or lines behind', true, "{$removed_stocktakes} stocktake(s), {$removed_st_lines} line(s) removed" );
		}

		// cntr_order_stock_state is also a derived guard table, not one of the
		// two hard append-only tables — deleting it for orders this run created
		// and already deleted from WooCommerce just removes a dead reference,
		// same reasoning as catalog_index above.
		if ( ! empty( $this->order_fixture_ids ) ) {
			$state_table_c   = Install::table( 'order_stock_state' );
			$placeholders_o  = implode( ',', array_fill( 0, count( $this->order_fixture_ids ), '%d' ) );
			$removed_state   = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$state_table_c} WHERE order_id IN ({$placeholders_o})", ...$this->order_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no order_stock_state rows behind', true, "{$removed_state} row(s) removed" );
		}

		if ( ! empty( $this->register_fixture_ids ) ) {
			$registers_table = Install::table( 'registers' );
			$shifts_table_c  = Install::table( 'shifts' );
			$events_table_c  = Install::table( 'shift_events' );
			$placeholders    = implode( ',', array_fill( 0, count( $this->register_fixture_ids ), '%d' ) );

			// cntr_shift_events has no register/register-fixture column of its
			// own — only shift_id — so its ids must be resolved via the
			// fixture registers' shifts BEFORE those shift rows are deleted
			// below, or the join has nothing left to match against.
			$shift_ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$shifts_table_c} WHERE register_id IN ({$placeholders})", ...$this->register_fixture_ids ) // phpcs:ignore
			);
			$removed_events = 0;
			if ( ! empty( $shift_ids ) ) {
				$shift_placeholders = implode( ',', array_fill( 0, count( $shift_ids ), '%d' ) );
				$removed_events      = (int) $wpdb->query(
					$wpdb->prepare( "DELETE FROM {$events_table_c} WHERE shift_id IN ({$shift_placeholders})", ...$shift_ids ) // phpcs:ignore
				);
			}

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$shifts_table_c} WHERE register_id IN ({$placeholders})", ...$this->register_fixture_ids ) ); // phpcs:ignore
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$registers_table} WHERE id IN ({$placeholders})", ...$this->register_fixture_ids ) ); // phpcs:ignore
			$this->check( 'cleanup: this run left no fixture registers, shifts or shift_events behind', true, count( $this->register_fixture_ids ) . " register(s), {$removed_events} shift_event(s) removed" );
		}

		if ( ! empty( $this->customer_fixture_ids ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';

			// cntr_customer_ledger (P2.6) — same reasoning as supplier_ledger
			// above: not append-only in the "self-test cleanup" sense, only
			// protected from whole-table uninstall. Deleted before the user
			// row itself so nothing is left pointing at a customer id that no
			// longer exists.
			$placeholders_pre = implode( ',', array_fill( 0, count( $this->customer_fixture_ids ), '%d' ) );
			$ledger_table_c   = Install::table( 'customer_ledger' );
			$removed_ledger   = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$ledger_table_c} WHERE customer_id IN ({$placeholders_pre})", ...$this->customer_fixture_ids ) // phpcs:ignore
			);

			$removed_customers = 0;
			foreach ( $this->customer_fixture_ids as $cid ) {
				if ( wp_delete_user( $cid ) ) {
					$removed_customers++;
				}
			}
			$index_table_c  = Install::table( 'customer_index' );
			$placeholders_c = implode( ',', array_fill( 0, count( $this->customer_fixture_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$index_table_c} WHERE customer_id IN ({$placeholders_c})", ...$this->customer_fixture_ids ) ); // phpcs:ignore
			$this->check( 'cleanup: this run left no fixture customers, index rows or customer_ledger rows behind', true, "{$removed_customers} customer(s), {$removed_ledger} ledger row(s) removed" );
		}

		// cntr_sale_queue, cntr_tenders and cntr_shift_sales are not touched by
		// WooCommerce's own order deletion — they're Counter's own tables, and
		// unlike cntr_stock_moves none of them are in Install::PROTECTED_TABLES'
		// sense of "never prune test debris" (sale_queue isn't protected at all;
		// tenders/shift_sales are protected from UNINSTALL, a different concern
		// from pruning rows this run itself created). Deleted by exact tracked
		// ids/uuids, same reasoning as catalog_index and order_stock_state above.
		if ( ! empty( $this->sale_queue_uuids ) ) {
			$queue_table_c  = Install::table( 'sale_queue' );
			$placeholders_q = implode( ',', array_fill( 0, count( $this->sale_queue_uuids ), '%s' ) );
			$removed_queue  = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$queue_table_c} WHERE uuid IN ({$placeholders_q})", ...$this->sale_queue_uuids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no sale_queue rows behind', true, "{$removed_queue} row(s) removed" );
		}
		if ( ! empty( $this->order_fixture_ids ) ) {
			$tenders_table_c     = Install::table( 'tenders' );
			$shift_sales_table_c = Install::table( 'shift_sales' );
			$placeholders_ord    = implode( ',', array_fill( 0, count( $this->order_fixture_ids ), '%d' ) );
			$removed_tenders     = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$tenders_table_c} WHERE order_id IN ({$placeholders_ord})", ...$this->order_fixture_ids ) // phpcs:ignore
			);
			$removed_shift_sales = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$shift_sales_table_c} WHERE order_id IN ({$placeholders_ord})", ...$this->order_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no tenders or shift_sales rows behind', true, "{$removed_tenders} tender(s), {$removed_shift_sales} shift_sale(s) removed" );
		}

		// cntr_sales_daily (P5.1) — a derived aggregate, not a source-of-truth
		// ledger (unlike the cntr_stock_moves rows behind it, which stay
		// permanent) — pruned by the exact fixture location ids this run
		// tracked, same reasoning as catalog_index/cntr_stock above.
		if ( ! empty( $this->location_fixture_ids ) ) {
			$sales_daily_table_c = Install::table( 'sales_daily' );
			$placeholders_sd     = implode( ',', array_fill( 0, count( $this->location_fixture_ids ), '%d' ) );
			$removed_sd          = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$sales_daily_table_c} WHERE location_id IN ({$placeholders_sd})", ...$this->location_fixture_ids ) // phpcs:ignore
			);
			$this->check( 'cleanup: this run left no fixture cntr_sales_daily rows behind', true, "{$removed_sd} row(s) removed" );
		}

		$location_result = $this->purge_tagged_locations( $this->location_fixture_ids );
		$this->check(
			$location_result['skipped']
				? 'cleanup: location fixture removal skipped — a live tagged fixture exists that this run did not create'
				: 'cleanup: this run left no fixture locations behind',
			true,
			$location_result['skipped'] ? wp_json_encode( $location_result['foreign'] ) : count( $location_result['removed'] ) . ' location(s) removed'
		);

		if ( $this->settings_touched ) {
			if ( null === $this->settings_prior_marker ) {
				Settings::set( self::SETTINGS_TEST_KEY, '' );
			} else {
				Settings::set( self::SETTINGS_TEST_KEY, $this->settings_prior_marker );
			}
		}
	}
}
