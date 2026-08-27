<?php
namespace Counter;

defined( 'ABSPATH' ) || exit;

/**
 * Schema engine. §5 is the source of truth for the DDL; this class only wraps it.
 *
 * dbDelta() is fussy and fails silently (§3.5): two spaces after PRIMARY KEY, KEY
 * never INDEX, lowercase types, one field per line, InnoDB always. plain() strips
 * the prose comment lines schema() carries for documentation before dbDelta ever
 * sees the SQL, because dbDelta splits on newlines and would read a comment line as
 * a column definition.
 *
 * The version stamp (cntr_db_ver option) can lie: upgrade() writes it whatever
 * dbDelta actually did. missing() is the thing that tells the truth — read it on the
 * Health page after any schema change before concluding a feature is broken in code.
 */
class Install {

	// Keep in lock-step with CNTR_DB_VER in counter.php. test_version() fails on
	// drift between the two — that drift is exactly how "the stamp lied" happens.
	// D1 — the first schema change since this plugin went live: cntr_sales_hourly.
	const VERSION = 2;

	/**
	 * Never dropped by the normal uninstall path. stock_moves and challan_register
	 * are the two Invariant-I/V legal records; the rest are ledgers and receipts a
	 * shop needs even after deactivating the plugin. Audit finding G7: a clean-
	 * uninstall routine that drops these is a compliance hazard triggered by a
	 * misclick on the plugins screen.
	 */
	const PROTECTED_TABLES = [
		'stock_moves',
		'challan_register',
		'audit_log',
		'customer_ledger',
		'supplier_ledger',
		'shift_sales',
		'tenders',
	];

	/** 'stock_moves' -> '{$wpdb->prefix}cntr_stock_moves'. Never write the prefix inline. */
	public static function table( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . 'cntr_' . $short;
	}

	/** Every short table name this plugin expects to exist. Used by test_schema(). */
	public static function expected_tables(): array {
		return array_keys( self::schema() );
	}

	/** Derived and operational tables — rebuildable from the protected ones. */
	public static function soft_drop_tables(): array {
		return [ 'catalog_index', 'stock', 'sales_daily', 'sales_hourly' ];
	}

	/**
	 * Drops caches and operational data only. Never touches a table in
	 * PROTECTED_TABLES. Also clears settings, the version stamp, and re-syncs
	 * roles/capabilities to remove Counter's own grants.
	 */
	public static function uninstall_soft(): void {
		global $wpdb;

		foreach ( self::soft_drop_tables() as $short ) {
			$table = self::table( $short );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, not user input
		}

		delete_option( 'cntr_settings' );
		delete_option( 'cntr_db_ver' );

		foreach ( [ 'cntr_cashier', 'cntr_supervisor', 'cntr_stockkeeper', 'cntr_manager', 'cntr_terminal' ] as $role ) {
			remove_role( $role );
		}
	}

	/**
	 * The decision function this task's self-test exercises (§0.1 rule "test the
	 * decision function, never the destruction"): checks the opt-in constant and
	 * returns without touching the database if it is absent. Never call the
	 * destructive half of uninstall_hard() from a test — this early return is
	 * exactly what makes that safe to assert.
	 */
	public static function may_uninstall_hard(): bool {
		return defined( 'CNTR_ALLOW_DESTRUCTIVE_UNINSTALL' ) && CNTR_ALLOW_DESTRUCTIVE_UNINSTALL;
	}

	/**
	 * Requires CNTR_ALLOW_DESTRUCTIVE_UNINSTALL defined true in wp-config.php.
	 * Exports every protected table to CSV in the uploads directory BEFORE
	 * dropping anything, then drops every cntr_ table including the protected
	 * ones.
	 */
	public static function uninstall_hard(): array {
		if ( ! self::may_uninstall_hard() ) {
			return [
				'ok'     => false,
				'reason' => 'CNTR_ALLOW_DESTRUCTIVE_UNINSTALL not defined',
			];
		}

		global $wpdb;
		$exported = self::export_protected_tables_to_csv();

		foreach ( self::expected_tables() as $short ) {
			$table = self::table( $short );
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, not user input
		}

		return [
			'ok'       => true,
			'exported' => $exported,
		];
	}

	/**
	 * CSV export of every protected table, written before uninstall_hard() drops
	 * anything. Public so the self-test can exercise the export itself without
	 * calling the destructive half of uninstall_hard() — see may_uninstall_hard().
	 */
	public static function export_protected_tables_to_csv(): array {
		global $wpdb;

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'counter-uninstall-export/';
		wp_mkdir_p( $dir );

		$files = [];
		$stamp = gmdate( 'Ymd-His' );

		foreach ( self::PROTECTED_TABLES as $short ) {
			$table = self::table( $short );
			$rows  = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, not user input

			$path = $dir . $short . '-' . $stamp . '.csv';
			$fh   = fopen( $path, 'w' );

			if ( ! empty( $rows ) ) {
				fputcsv( $fh, array_keys( $rows[0] ) );
				foreach ( $rows as $row ) {
					fputcsv( $fh, $row );
				}
			} else {
				$cols = self::columns_from_sql( self::schema()[ $short ] );
				fputcsv( $fh, $cols );
			}

			fclose( $fh );
			$files[ $short ] = $path;
		}

		return $files;
	}

	public static function activate(): void {
		self::run_dbdelta();
		update_option( 'cntr_db_ver', self::VERSION );
		self::seed();
	}

	public static function upgrade(): void {
		self::run_dbdelta();
		update_option( 'cntr_db_ver', self::VERSION );
		self::seed();
	}

	/**
	 * information_schema is asked directly rather than trusting the version stamp,
	 * because upgrade() writes the stamp whatever dbDelta actually did — a partial
	 * ALTER on a locked table still bumps cntr_db_ver.
	 */
	public static function missing(): array {
		global $wpdb;
		$gaps    = [];
		$schema  = self::schema();
		$db_name = DB_NAME;

		foreach ( $schema as $short => $sql ) {
			$table = self::table( $short );

			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.TABLES
					 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$db_name,
					$table
				)
			);

			if ( ! $exists ) {
				$gaps[] = [
					'table'  => $table,
					'column' => null,
					'issue'  => 'table missing',
				];
				continue;
			}

			$expected_cols = self::columns_from_sql( $sql );
			$actual_cols   = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$db_name,
					$table
				)
			);

			foreach ( $expected_cols as $col ) {
				if ( ! in_array( $col, $actual_cols, true ) ) {
					$gaps[] = [
						'table'  => $table,
						'column' => $col,
						'issue'  => 'column missing',
					];
				}
			}
		}

		return $gaps;
	}

	/** Re-run dbDelta and report which tables/columns it just added. */
	public static function reconcile(): array {
		$before = self::missing();
		self::run_dbdelta();
		$after = self::missing();

		$after_keys = array_map(
			static fn( $g ) => $g['table'] . '.' . ( $g['column'] ?? '' ),
			$after
		);

		$fixed = array_values(
			array_filter(
				$before,
				static fn( $g ) => ! in_array( $g['table'] . '.' . ( $g['column'] ?? '' ), $after_keys, true )
			)
		);

		return [
			'fixed'          => $fixed,
			'still_missing'  => $after,
		];
	}

	private static function run_dbdelta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::plain() as $sql ) {
			dbDelta( $sql );
		}
	}

	/** Column names declared in a CREATE TABLE body, in the order dbDelta reads them. */
	private static function columns_from_sql( string $sql ): array {
		$cols  = [];
		$lines = explode( "\n", $sql );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === stripos( $line, 'PRIMARY KEY' ) || 0 === stripos( $line, 'UNIQUE KEY' )
				|| 0 === stripos( $line, 'KEY ' ) || 0 === stripos( $line, 'CREATE TABLE' ) || ')' === substr( $line, 0, 1 ) ) {
				continue;
			}
			if ( preg_match( '/^([a-z_][a-z0-9_]*)\s/i', $line, $m ) ) {
				$cols[] = $m[1];
			}
		}
		return $cols;
	}

	/**
	 * Comments strip before dbDelta ever sees a statement. dbDelta splits on
	 * newlines and takes the first word of each line as a column name — a leftover
	 * "-- prose" line reads as a bogus column and fails silently.
	 */
	private static function plain(): array {
		$plain = [];
		foreach ( self::schema() as $short => $sql ) {
			$lines = explode( "\n", $sql );
			$kept  = [];
			foreach ( $lines as $line ) {
				$trimmed = ltrim( $line );
				if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
					continue;
				}
				$kept[] = $line;
			}
			$plain[ $short ] = implode( "\n", $kept );
		}
		return $plain;
	}

	private static function seed(): void {
		global $wpdb;

		$locations = self::table( 'locations' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$locations} WHERE code = %s", 'MAIN' ) ) ) {
			$wpdb->insert(
				$locations,
				[
					'name'               => 'Main',
					'code'               => 'MAIN',
					'is_online_sellable' => 1,
					'is_default'         => 1,
					'status'             => 'active',
					'created_at'         => current_time( 'mysql', true ),
				]
			);
		}

		$registers = self::table( 'registers' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$registers} WHERE prefix = %s", 'R1' ) ) ) {
			$wpdb->insert(
				$registers,
				[
					'name'       => 'Register 1',
					'location_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$locations} WHERE code = %s", 'MAIN' ) ),
					'prefix'     => 'R1',
					'status'     => 'active',
					'created_at' => current_time( 'mysql', true ),
				]
			);
		}

		$groups = self::table( 'price_groups' );
		foreach ( [ 'retail' => 1, 'wholesale' => 0, 'online' => 0 ] as $code => $is_default ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$groups} WHERE code = %s", $code ) ) ) {
				$wpdb->insert(
					$groups,
					[
						'name'       => ucfirst( $code ),
						'code'       => $code,
						'is_default' => $is_default,
						'status'     => 'active',
					]
				);
			}
		}

		$counters = self::table( 'counters' );
		foreach ( [ 'catalog_rev', 'po_no', 'transfer_ref', 'stocktake_ref' ] as $name ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$counters} (name, value) VALUES (%s, 0)",
					$name
				)
			);
		}

		// Payment accounts (P1.14). A tender written without a named account is
		// how a live system once carried 1,168 payments against nothing at all
		// (§5.3's own schema comment) — seeded here so cntr_tenders.account_id
		// always has somewhere real to point from the very first sale. Exact
		// bank/MFS accounts the shop actually uses is one of §10.1's unanswered
		// blocking questions; CASH and a placeholder BKASH cover the two
		// payment types every Bangladeshi counter takes on day one.
		// P4.6 made every Pos\Tenders::VALID_METHODS entry (except 'credit',
		// a customer-ledger concept rather than a real account, and
		// 'change', which already aliases to CASH) need a real account to
		// resolve to BEFORE that method can ever be tendered — extended
		// here from the original CASH/BKASH pair to the full common set so
		// the mandatory check doesn't refuse a method a real counter
		// legitimately takes on day one just because nobody has configured
		// its account yet.
		$accounts = self::table( 'payment_accounts' );
		$default_accounts = [
			'CASH'   => [ 'name' => 'Cash Drawer', 'kind' => 'cash', 'is_drawer' => 1 ],
			'BKASH'  => [ 'name' => 'bKash', 'kind' => 'mfs', 'is_drawer' => 0 ],
			'NAGAD'  => [ 'name' => 'Nagad', 'kind' => 'mfs', 'is_drawer' => 0 ],
			'ROCKET' => [ 'name' => 'Rocket', 'kind' => 'mfs', 'is_drawer' => 0 ],
			'CARD'   => [ 'name' => 'Card Terminal', 'kind' => 'card', 'is_drawer' => 0 ],
			'BANK'   => [ 'name' => 'Bank Account', 'kind' => 'bank', 'is_drawer' => 0 ],
		];
		foreach ( $default_accounts as $code => $data ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$accounts} WHERE code = %s", $code ) ) ) {
				$wpdb->insert(
					$accounts,
					[
						'name'            => $data['name'],
						'code'            => $code,
						'kind'            => $data['kind'],
						'is_drawer'       => $data['is_drawer'],
						'opening_balance' => '0.0000',
						'status'          => 'active',
					]
				);
			}
		}
	}

	/**
	 * Complete DDL, §5. Keyed by short table name. Prose comment lines are kept here
	 * for documentation and stripped by plain() before dbDelta runs.
	 */
	private static function schema(): array {
		global $wpdb;
		$p = $wpdb->prefix . 'cntr_';
		$c = $wpdb->get_charset_collate();

		return [

			// -- 5.1 Infrastructure ------------------------------------------------

			'counters' => "CREATE TABLE {$p}counters (
  name varchar(64) NOT NULL,
  value bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (name)
) {$c};",

			'audit_log' => "CREATE TABLE {$p}audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL DEFAULT '',
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  before_json longtext NULL,
  after_json longtext NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY created (created_at),
  KEY actor (user_id,created_at)
) {$c};",

			// -- 5.2 Stock -----------------------------------------------------------

			'locations' => "CREATE TABLE {$p}locations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  code varchar(32) NOT NULL DEFAULT '',
  is_online_sellable tinyint(1) NOT NULL DEFAULT 0,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  address_json longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) {$c};",

			'stock_moves' => "CREATE TABLE {$p}stock_moves (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty_delta decimal(14,4) NOT NULL DEFAULT 0.0000,
  unit_cost decimal(14,4) NOT NULL DEFAULT 0.0000,
  balance_after decimal(14,4) NOT NULL DEFAULT 0.0000,
  reason varchar(32) NOT NULL DEFAULT '',
  ref_type varchar(32) NOT NULL DEFAULT '',
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  channel varchar(16) NOT NULL DEFAULT '',
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY item (product_id,variation_id,location_id),
  KEY ref (ref_type,ref_id),
  KEY created (created_at),
  KEY reason_created (reason,created_at),
  KEY batch (batch_id)
) {$c};",

			'stock' => "CREATE TABLE {$p}stock (
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty decimal(14,4) NOT NULL DEFAULT 0.0000,
  reserved decimal(14,4) NOT NULL DEFAULT 0.0000,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (product_id,variation_id,location_id),
  KEY location (location_id)
) {$c};",

			'batches' => "CREATE TABLE {$p}batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  lot_no varchar(64) NOT NULL DEFAULT '',
  expiry_date date NULL,
  qty_received decimal(14,4) NOT NULL DEFAULT 0.0000,
  qty_remaining decimal(14,4) NOT NULL DEFAULT 0.0000,
  unit_cost decimal(14,4) NOT NULL DEFAULT 0.0000,
  po_id bigint(20) unsigned NOT NULL DEFAULT 0,
  received_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY fifo (product_id,variation_id,location_id,received_at),
  KEY expiry (expiry_date)
) {$c};",

			'order_stock_state' => "CREATE TABLE {$p}order_stock_state (
  order_id bigint(20) unsigned NOT NULL,
  refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
  state varchar(16) NOT NULL DEFAULT 'applied',
  applied_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  reverted_at datetime NULL,
  PRIMARY KEY  (order_id,refund_id),
  KEY state (state)
) {$c};",

			'catalog_index' => "CREATE TABLE {$p}catalog_index (
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rev bigint(20) unsigned NOT NULL DEFAULT 0,
  deleted tinyint(1) NOT NULL DEFAULT 0,
  sku varchar(100) NOT NULL DEFAULT '',
  barcode varchar(64) NOT NULL DEFAULT '',
  payload_json longtext NULL,
  PRIMARY KEY  (product_id,variation_id),
  KEY rev (rev),
  KEY barcode (barcode),
  KEY sku (sku)
) {$c};",

			'transfers' => "CREATE TABLE {$p}transfers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ref_no varchar(32) NOT NULL DEFAULT '',
  from_location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  to_location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'draft',
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  received_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ref_no (ref_no)
) {$c};",

			'transfer_lines' => "CREATE TABLE {$p}transfer_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transfer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty_sent decimal(14,4) NOT NULL DEFAULT 0.0000,
  qty_received decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY  (id),
  KEY transfer (transfer_id)
) {$c};",

			'stocktakes' => "CREATE TABLE {$p}stocktakes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ref_no varchar(32) NOT NULL DEFAULT '',
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'open',
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  opened_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  closed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ref_no (ref_no)
) {$c};",

			'stocktake_lines' => "CREATE TABLE {$p}stocktake_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  stocktake_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  expected decimal(14,4) NOT NULL DEFAULT 0.0000,
  counted decimal(14,4) NOT NULL DEFAULT 0.0000,
  variance decimal(14,4) NOT NULL DEFAULT 0.0000,
  counted_at datetime NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY stocktake (stocktake_id)
) {$c};",

			'oversell_log' => "CREATE TABLE {$p}oversell_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  register_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty_short decimal(14,4) NOT NULL DEFAULT 0.0000,
  balance_after decimal(14,4) NOT NULL DEFAULT 0.0000,
  status varchar(16) NOT NULL DEFAULT 'open',
  resolution text NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  resolved_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY item (product_id,variation_id)
) {$c};",

			'units' => "CREATE TABLE {$p}units (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(64) NOT NULL DEFAULT '',
  name_bn varchar(64) NOT NULL DEFAULT '',
  code varchar(32) NOT NULL DEFAULT '',
  base_unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  multiplier decimal(14,4) NOT NULL DEFAULT 1.0000,
  allow_decimal tinyint(1) NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY base_unit (base_unit_id)
) {$c};",

			'product_units' => "CREATE TABLE {$p}product_units (
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  unit_id bigint(20) unsigned NOT NULL DEFAULT 0,
  price decimal(14,4) NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (product_id,variation_id,unit_id),
  KEY unit (unit_id)
) {$c};",

			// -- 5.3 The till ----------------------------------------------------------

			'registers' => "CREATE TABLE {$p}registers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  prefix varchar(8) NOT NULL DEFAULT '',
  settings_json longtext NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY prefix (prefix)
) {$c};",

			'shifts' => "CREATE TABLE {$p}shifts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  register_id bigint(20) unsigned NOT NULL DEFAULT 0,
  open_key bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  opened_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  closed_at datetime NULL,
  opening_float decimal(14,4) NOT NULL DEFAULT 0.0000,
  expected_cash decimal(14,4) NOT NULL DEFAULT 0.0000,
  counted_cash decimal(14,4) NOT NULL DEFAULT 0.0000,
  variance decimal(14,4) NOT NULL DEFAULT 0.0000,
  denoms_json longtext NULL,
  note text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY one_open (open_key),
  KEY register (register_id,opened_at)
) {$c};",

			'shift_events' => "CREATE TABLE {$p}shift_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(24) NOT NULL DEFAULT '',
  amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  method varchar(32) NOT NULL DEFAULT '',
  ref_type varchar(32) NOT NULL DEFAULT '',
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY shift (shift_id,type)
) {$c};",

			'shift_sales' => "CREATE TABLE {$p}shift_sales (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
  register_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'sale',
  exchange_group_id char(36) NOT NULL DEFAULT '',
  receipt_no varchar(32) NOT NULL DEFAULT '',
  total decimal(14,4) NOT NULL DEFAULT 0.0000,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY receipt_no (receipt_no),
  KEY shift (shift_id),
  KEY order_id (order_id),
  KEY exchange (exchange_group_id)
) {$c};",

			'tenders' => "CREATE TABLE {$p}tenders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
  shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
  method varchar(32) NOT NULL DEFAULT '',
  amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  is_change tinyint(1) NOT NULL DEFAULT 0,
  reference varchar(120) NOT NULL DEFAULT '',
  account_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY shift_method (shift_id,method)
) {$c};",

			'sale_queue' => "CREATE TABLE {$p}sale_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL,
  register_id bigint(20) unsigned NOT NULL DEFAULT 0,
  shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'sale',
  payload_json longtext NULL,
  receipt_json longtext NULL,
  status varchar(24) NOT NULL DEFAULT 'queued',
  attempts int(11) NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  error text NULL,
  rung_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  completed_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY status (status,created_at),
  KEY register (register_id,status)
) {$c};",

			'late_sales' => "CREATE TABLE {$p}late_sales (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  intended_shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
  landed_shift_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  status varchar(16) NOT NULL DEFAULT 'open',
  resolution text NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status)
) {$c};",

			'payment_accounts' => "CREATE TABLE {$p}payment_accounts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  code varchar(32) NOT NULL DEFAULT '',
  kind varchar(24) NOT NULL DEFAULT 'cash',
  is_drawer tinyint(1) NOT NULL DEFAULT 0,
  opening_balance decimal(14,4) NOT NULL DEFAULT 0.0000,
  status varchar(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) {$c};",

			// -- 5.4 Customers, credit, purchasing --------------------------------------

			'customer_index' => "CREATE TABLE {$p}customer_index (
  phone_norm varchar(24) NOT NULL,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  display_name varchar(160) NOT NULL DEFAULT '',
  last_order_at datetime NULL,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (phone_norm,customer_id),
  KEY phone (phone_norm,last_order_at),
  KEY customer (customer_id)
) {$c};",

			'customer_ledger' => "CREATE TABLE {$p}customer_ledger (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(24) NOT NULL DEFAULT '',
  amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  balance_after decimal(14,4) NOT NULL DEFAULT 0.0000,
  ref_type varchar(32) NOT NULL DEFAULT '',
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  due_date date NULL,
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY customer (customer_id,created_at),
  KEY due (due_date)
) {$c};",

			'suppliers' => "CREATE TABLE {$p}suppliers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(160) NOT NULL DEFAULT '',
  phone varchar(32) NOT NULL DEFAULT '',
  email varchar(120) NOT NULL DEFAULT '',
  bin varchar(32) NOT NULL DEFAULT '',
  address text NULL,
  terms_days int(11) NOT NULL DEFAULT 0,
  opening_balance decimal(14,4) NOT NULL DEFAULT 0.0000,
  status varchar(16) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY name (name(64))
) {$c};",

			'purchase_orders' => "CREATE TABLE {$p}purchase_orders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  po_no varchar(32) NOT NULL DEFAULT '',
  supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'draft',
  subtotal decimal(14,4) NOT NULL DEFAULT 0.0000,
  tax_total decimal(14,4) NOT NULL DEFAULT 0.0000,
  shipping_total decimal(14,4) NOT NULL DEFAULT 0.0000,
  other_total decimal(14,4) NOT NULL DEFAULT 0.0000,
  grand_total decimal(14,4) NOT NULL DEFAULT 0.0000,
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ordered_at datetime NULL,
  expected_at date NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY po_no (po_no),
  KEY supplier (supplier_id,status)
) {$c};",

			'purchase_lines' => "CREATE TABLE {$p}purchase_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  po_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  qty_ordered decimal(14,4) NOT NULL DEFAULT 0.0000,
  qty_received decimal(14,4) NOT NULL DEFAULT 0.0000,
  unit_cost decimal(14,4) NOT NULL DEFAULT 0.0000,
  tax_rate decimal(7,4) NOT NULL DEFAULT 0.0000,
  landed_cost_share decimal(14,4) NOT NULL DEFAULT 0.0000,
  batch_id bigint(20) unsigned NOT NULL DEFAULT 0,
  lot_no varchar(64) NOT NULL DEFAULT '',
  expiry_date date NULL,
  PRIMARY KEY  (id),
  KEY po (po_id),
  KEY item (product_id,variation_id)
) {$c};",

			'supplier_ledger' => "CREATE TABLE {$p}supplier_ledger (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(24) NOT NULL DEFAULT '',
  amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  balance_after decimal(14,4) NOT NULL DEFAULT 0.0000,
  ref_type varchar(32) NOT NULL DEFAULT '',
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  due_date date NULL,
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY supplier (supplier_id,created_at),
  KEY due (due_date)
) {$c};",

			'price_groups' => "CREATE TABLE {$p}price_groups (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(64) NOT NULL DEFAULT '',
  code varchar(32) NOT NULL DEFAULT '',
  is_default tinyint(1) NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) {$c};",

			'price_group_prices' => "CREATE TABLE {$p}price_group_prices (
  group_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  price decimal(14,4) NOT NULL DEFAULT 0.0000,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (group_id,product_id,variation_id)
) {$c};",

			// -- 5.5 Documents and compliance -------------------------------------------

			'label_templates' => "CREATE TABLE {$p}label_templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  page_json longtext NULL,
  fields_json longtext NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id)
) {$c};",

			'doc_templates' => "CREATE TABLE {$p}doc_templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(32) NOT NULL DEFAULT '',
  name varchar(120) NOT NULL DEFAULT '',
  width_mm int(11) NOT NULL DEFAULT 79,
  locale varchar(10) NOT NULL DEFAULT '',
  html longtext NULL,
  css longtext NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY type (type)
) {$c};",

			'challan_register' => "CREATE TABLE {$p}challan_register (
  seq bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  serial varchar(48) NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'issue',
  reverses_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  bin varchar(32) NOT NULL DEFAULT '',
  buyer_name varchar(160) NOT NULL DEFAULT '',
  buyer_bin varchar(32) NOT NULL DEFAULT '',
  taxable_value decimal(14,4) NOT NULL DEFAULT 0.0000,
  sd_amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  vat_amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  rounding decimal(14,4) NOT NULL DEFAULT 0.0000,
  total decimal(14,4) NOT NULL DEFAULT 0.0000,
  lines_json longtext NULL,
  issued_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (seq),
  UNIQUE KEY serial (serial),
  KEY order_id (order_id),
  KEY issued (issued_at)
) {$c};",

			// -- 5.6 People --------------------------------------------------------------

			'employees' => "CREATE TABLE {$p}employees (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  code varchar(32) NOT NULL DEFAULT '',
  pin_hash varchar(255) NOT NULL DEFAULT '',
  designation varchar(120) NOT NULL DEFAULT '',
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  join_date date NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  starting_salary decimal(14,4) NOT NULL DEFAULT 0.0000,
  basic decimal(14,4) NOT NULL DEFAULT 0.0000,
  allowances_json longtext NULL,
  note text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  UNIQUE KEY code (code)
) {$c};",

			'attendance' => "CREATE TABLE {$p}attendance (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  work_date date NOT NULL,
  clock_in datetime NULL,
  clock_out datetime NULL,
  minutes int(11) NOT NULL DEFAULT 0,
  source varchar(16) NOT NULL DEFAULT 'terminal',
  status varchar(16) NOT NULL DEFAULT 'present',
  note text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY emp_day (employee_id,work_date)
) {$c};",

			'leave' => "CREATE TABLE {$p}leave (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(32) NOT NULL DEFAULT '',
  from_date date NOT NULL,
  to_date date NOT NULL,
  days decimal(7,2) NOT NULL DEFAULT 0.00,
  status varchar(16) NOT NULL DEFAULT 'requested',
  approver_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reason text NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  decided_at datetime NULL,
  PRIMARY KEY  (id),
  KEY employee (employee_id,from_date),
  KEY status (status)
) {$c};",

			'payroll_runs' => "CREATE TABLE {$p}payroll_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  period varchar(7) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'draft',
  total decimal(14,4) NOT NULL DEFAULT 0.0000,
  account_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  approved_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY period (period)
) {$c};",

			'payroll_lines' => "CREATE TABLE {$p}payroll_lines (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  days_worked decimal(7,2) NOT NULL DEFAULT 0.00,
  earned decimal(14,4) NOT NULL DEFAULT 0.0000,
  allowances decimal(14,4) NOT NULL DEFAULT 0.0000,
  deductions_json longtext NULL,
  deductions decimal(14,4) NOT NULL DEFAULT 0.0000,
  net decimal(14,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY  (id),
  UNIQUE KEY run_emp (run_id,employee_id)
) {$c};",

			// -- 5.7 Reporting and expenses -----------------------------------------------

			'sales_daily' => "CREATE TABLE {$p}sales_daily (
  day date NOT NULL,
  channel varchar(16) NOT NULL DEFAULT '',
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  orders_count int(11) NOT NULL DEFAULT 0,
  gross decimal(14,4) NOT NULL DEFAULT 0.0000,
  discount decimal(14,4) NOT NULL DEFAULT 0.0000,
  tax decimal(14,4) NOT NULL DEFAULT 0.0000,
  shipping decimal(14,4) NOT NULL DEFAULT 0.0000,
  rounding decimal(14,4) NOT NULL DEFAULT 0.0000,
  refunds decimal(14,4) NOT NULL DEFAULT 0.0000,
  net decimal(14,4) NOT NULL DEFAULT 0.0000,
  cogs decimal(14,4) NOT NULL DEFAULT 0.0000,
  margin decimal(14,4) NOT NULL DEFAULT 0.0000,
  items_count decimal(14,4) NOT NULL DEFAULT 0.0000,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (day,channel,location_id),
  KEY day (day)
) {$c};",

			// D1 — a sibling table, not an extra column on sales_daily's own
			// key: keeps every existing daily query (and every existing PK
			// lookup) untouched, and Rollup::rebuild() already recomputes
			// both grains from the same pass over cntr_stock_moves rather
			// than deriving one from the other.
			'sales_hourly' => "CREATE TABLE {$p}sales_hourly (
  day date NOT NULL,
  hour tinyint(2) unsigned NOT NULL DEFAULT 0,
  channel varchar(16) NOT NULL DEFAULT '',
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  orders_count int(11) NOT NULL DEFAULT 0,
  gross decimal(14,4) NOT NULL DEFAULT 0.0000,
  discount decimal(14,4) NOT NULL DEFAULT 0.0000,
  tax decimal(14,4) NOT NULL DEFAULT 0.0000,
  shipping decimal(14,4) NOT NULL DEFAULT 0.0000,
  rounding decimal(14,4) NOT NULL DEFAULT 0.0000,
  refunds decimal(14,4) NOT NULL DEFAULT 0.0000,
  net decimal(14,4) NOT NULL DEFAULT 0.0000,
  cogs decimal(14,4) NOT NULL DEFAULT 0.0000,
  margin decimal(14,4) NOT NULL DEFAULT 0.0000,
  items_count decimal(14,4) NOT NULL DEFAULT 0.0000,
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (day,hour,channel,location_id),
  KEY day (day)
) {$c};",

			'expense_categories' => "CREATE TABLE {$p}expense_categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(120) NOT NULL DEFAULT '',
  code varchar(32) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) {$c};",

			'expenses' => "CREATE TABLE {$p}expenses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ref_no varchar(32) NOT NULL DEFAULT '',
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL DEFAULT 0,
  account_id bigint(20) unsigned NOT NULL DEFAULT 0,
  supplier_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  tax_amount decimal(14,4) NOT NULL DEFAULT 0.0000,
  channel varchar(16) NOT NULL DEFAULT '',
  spent_on date NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'draft',
  recurring_json longtext NULL,
  note text NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY ref_no (ref_no),
  KEY period (spent_on,category_id),
  KEY status (status),
  KEY channel (channel)
) {$c};",

		];
	}
}
