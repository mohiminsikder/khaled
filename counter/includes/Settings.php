<?php
namespace Counter;

defined( 'ABSPATH' ) || exit;

/**
 * One typed, defaulted, sanitised option store. Every key any later module reads
 * is declared here with a sane default, so no task has to invent one — declaring a
 * key here is the documentation.
 */
class Settings {

	const OPTION = 'cntr_settings';

	/** @var array|null Cached per request. */
	private static ?array $cache = null;

	/** key => [ default, type ] where type is 'string' | 'int' | 'float' | 'bool'. */
	public static function defaults(): array {
		return [
			'shop.name'                => [ '', 'string' ],
			'shop.bin'                 => [ '', 'string' ],
			'shop.address'             => [ '', 'string' ],
			'shop.phone'               => [ '', 'string' ],
			'shop.footer_text'         => [ '', 'string' ],

			'cash.rounding_step'       => [ 1.00, 'float' ],  // T7 — nearest taka
			'cash.rounding_mode'       => [ 'nearest', 'string' ],

			'stock.online_buffer'      => [ 0, 'int' ],       // units withheld from the website
			'stock.allow_negative_pos' => [ true, 'bool' ],   // goods left the shop; refusing loses the record
			'stock.adjustment_note_threshold' => [ 0.0, 'float' ], // ৳ value above which Rest\Stock::process() requires a note

			'pos.default_register'      => [ 0, 'int' ],
			'pos.search_fields'         => [ 'sku,barcode,name', 'string' ],
			'pos.discount_ceiling_pct'  => [ 0.0, 'float' ],
			'pos.weight_barcode_prefix' => [ '2', 'string' ], // EAN-13 weight-embedded barcode prefix digit

			'offline.enabled'          => [ false, 'bool' ],  // P7 turns this on
			'offline.max_age_hours'    => [ 72, 'int' ],
			'offline.challan_policy'   => [ 'provisional', 'string' ],

			'vat.enabled'              => [ false, 'bool' ],
			'vat.rate'                 => [ 0.0, 'float' ],
			'vat.challan_prefix'       => [ '', 'string' ],
			'vat.challan_start'        => [ 1, 'int' ],

			'print.receipt_template_id' => [ 0, 'int' ],
			'print.label_template_id'   => [ 0, 'int' ],

			'catalog.delta_cap'        => [ 2000, 'int' ],

			'credit.default_due_days' => [ 30, 'int' ], // a credit sale's due_date when no other term is given

			'reports.dead_stock_days' => [ 90, 'int' ], // no sale in this many days = dead stock

			// P4.6 — the one map-shaped setting this plugin has: gateway_id =>
			// account_id, JSON-encoded under a plain 'string' type. Read/written
			// only through Pos\Accounts::gateway_map()/set_gateway_map(), never
			// decoded ad hoc elsewhere.
			'payments.gateway_accounts' => [ '{}', 'string' ],

			// P5.4 — the same idiom: a map-shaped setting rather than a third
			// table. Each rule is {category_id, amount, account_id,
			// location_id, channel, note}; Expenses::generate_recurring_drafts()
			// reads this list, cntr_expenses itself is where the resulting
			// drafts land.
			'expenses.recurring_rules' => [ '[]', 'string' ],

			// P6.3 — leave types and their own annual day caps. Not a
			// table (the plan's own Schema line names cntr_leave alone):
			// {code, name, annual_days} per type, the same map-shaped-
			// setting idiom as 'payments.gateway_accounts' and
			// 'expenses.recurring_rules'.
			'people.leave_types' => [ '[{"code":"casual","name":"Casual Leave","annual_days":10},{"code":"sick","name":"Sick Leave","annual_days":14},{"code":"earned","name":"Earned Leave","annual_days":12}]', 'string' ],

			// P6.4 — explicit dates ('Y-m-d'), JSON array, not a recurring
			// day-of-week rule: a real shop's public/religious holidays
			// move every year and are not derivable from a formula.
			'people.holidays' => [ '[]', 'string' ],

			// P8.2 — "an untested backup is not a backup." No code here can see
			// inside a third-party/host-level backup tool, so this is a
			// declared, human-confirmed record: the exact cntr_* table short
			// names (Install::expected_tables()) someone has actually checked
			// are covered by whatever backup mechanism the shop's real host
			// uses. JSON array, empty by default — deliberately, since an
			// unconfigured shop has NOT had this confirmed, and defaulting to
			// "everything" would let test_backup_coverage() pass without
			// anyone ever having looked.
			'backup.manifest' => [ '[]', 'string' ],
		];
	}

	public static function get( string $key, $default = null ) {
		$store = self::load();
		if ( array_key_exists( $key, $store ) ) {
			return $store[ $key ];
		}
		$declared = self::defaults();
		if ( array_key_exists( $key, $declared ) ) {
			return $declared[ $key ][0];
		}
		return $default;
	}

	public static function set( string $key, $value ): void {
		$store          = self::load();
		$store[ $key ]  = self::sanitize( $key, $value );
		self::$cache    = $store;
		update_option( self::OPTION, $store );
	}

	private static function sanitize( string $key, $value ) {
		$declared = self::defaults();
		if ( ! isset( $declared[ $key ] ) ) {
			return $value;
		}
		[ , $type ] = $declared[ $key ];
		return match ( $type ) {
			'int'    => (int) $value,
			'float'  => (float) $value,
			'bool'   => (bool) $value,
			default  => (string) $value,
		};
	}

	private static function load(): array {
		if ( null === self::$cache ) {
			$stored       = get_option( self::OPTION, [] );
			self::$cache  = is_array( $stored ) ? $stored : [];
		}
		return self::$cache;
	}
}
