<?php
namespace Counter\Reports;

use Counter\Install;
use Counter\Db;
use Counter\Settings;
use Counter\Pos\Accounts;
use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * P5.4 — the other half of a P&L. Revenue and COGS already exist
 * (`cntr_sales_daily`, P5.1); this is the expense side neither WooCommerce
 * nor the rollup has any concept of.
 *
 * `cntr_expense_categories`/`cntr_expenses` were both pre-scaffolded ahead
 * of this task (the same "schema seeded ahead of the task that wires it"
 * pattern as `cntr_price_groups`/`cntr_payment_accounts") — one real column
 * was added here (`channel`, `varchar(16)`, default `''`): the scaffolded
 * shape had no way at all to record Direction's own "channel-attributable
 * expenses ... split by channel; the rest sit in ... unallocated" — a
 * `''`/`'pos'`/`'online'` tag on the expense itself, not a property of the
 * category, since the same category (e.g. "Fees") could plausibly carry
 * both a POS card-terminal fee and an online gateway fee in the same month.
 *
 * A manually booked expense (create()) is `status = 'confirmed'` the
 * moment it exists — only generate_recurring_drafts() ever writes
 * `status = 'draft'`, and a draft is invisible to profit_and_loss() until
 * a human calls confirm(). "An expense nobody looked at is how a P&L stops
 * being read" (Direction's own words) is enforced structurally here: the
 * P&L's own SQL filters `status = 'confirmed'`, not merely a convention.
 *
 * Recurring rules live in Settings ('expenses.recurring_rules', JSON) —
 * the same map-shaped-setting idiom P4.6 already established for
 * 'payments.gateway_accounts' — rather than a third table. Each rule is
 * `{id, category_id, amount, account_id, location_id, channel, note}`.
 * generate_recurring_drafts() is idempotent per (rule, period) through a
 * DETERMINISTIC ref_no ("REC-{rule id}-{period}") landing on
 * `cntr_expenses`' own `UNIQUE KEY ref_no` — the database enforces
 * "one draft per rule per month," not a second application-level check
 * that could race it.
 */
class Expenses {

	const DEFAULT_CATEGORIES = [
		[ 'code' => 'rent', 'name' => 'Rent' ],
		[ 'code' => 'electricity', 'name' => 'Electricity' ],
		[ 'code' => 'internet', 'name' => 'Internet' ],
		[ 'code' => 'wages', 'name' => 'Wages' ],
		[ 'code' => 'transport', 'name' => 'Transport' ],
		[ 'code' => 'packaging', 'name' => 'Packaging' ],
		[ 'code' => 'tea', 'name' => 'Tea' ],
	];

	public static function init(): void {
		// seed_default_categories() is called once, from Boot::on_activate() —
		// not on every admin_init, which would mean 7 SELECTs on every single
		// wp-admin page load forever after the categories already exist.
	}

	/** Idempotent — create_category() itself refuses a duplicate code, so a repeat call every admin page load is a cheap no-op once seeded. */
	public static function seed_default_categories(): void {
		foreach ( self::DEFAULT_CATEGORIES as $cat ) {
			if ( null === self::category_by_code( $cat['code'] ) ) {
				self::create_category( $cat['name'], $cat['code'] );
			}
		}
	}

	// -- Categories -------------------------------------------------------

	public static function categories( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'expense_categories' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name", $status ), ARRAY_A );
	}

	public static function category( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'expense_categories' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function category_by_code( string $code ): ?array {
		global $wpdb;
		$table = Install::table( 'expense_categories' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return $row ?: null;
	}

	/** @return int|\WP_Error */
	public static function create_category( string $name, string $code ) {
		global $wpdb;
		$table = Install::table( 'expense_categories' );
		$ok    = $wpdb->insert( $table, [ 'name' => $name, 'code' => $code, 'status' => 'active' ] );
		if ( ! $ok ) {
			return new \WP_Error( 'cntr_expense_category_exists', __( 'A category with this code already exists.', 'counter' ), [ 'status' => 409 ] );
		}
		return (int) $wpdb->insert_id;
	}

	// -- Expenses -----------------------------------------------------------

	/**
	 * $args: category_id, location_id, account_id (all required, > 0),
	 * amount (required, > 0), spent_on ('Y-m-d', required), channel
	 * ('', 'pos' or 'online' — optional, default ''), note, user_id.
	 * A manually booked expense is 'confirmed' immediately — only
	 * generate_recurring_drafts() ever creates a 'draft' row.
	 *
	 * @return int|\WP_Error
	 */
	public static function create( array $args ) {
		$category_id = (int) ( $args['category_id'] ?? 0 );
		$location_id = (int) ( $args['location_id'] ?? 0 );
		$account_id  = (int) ( $args['account_id'] ?? 0 );
		$amount      = wc_format_decimal( $args['amount'] ?? '0', 4 );
		$spent_on    = (string) ( $args['spent_on'] ?? '' );
		$channel     = in_array( $args['channel'] ?? '', [ 'pos', 'online' ], true ) ? $args['channel'] : '';

		// "Every expense names a payment account and a location" — Direction's
		// own words. Checked before anything else: an expense against no real
		// account cannot be reconciled to any real drawer or bank statement.
		if ( $account_id <= 0 || ! Accounts::is_active( $account_id ) ) {
			return new \WP_Error( 'cntr_expense_no_account', __( 'Every expense must name an active payment account.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( $location_id <= 0 || ! Locations::get( $location_id ) ) {
			return new \WP_Error( 'cntr_expense_no_location', __( 'Every expense must name a location.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( null === self::category( $category_id ) ) {
			return new \WP_Error( 'cntr_expense_no_category', __( 'Unknown expense category.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( bccomp( $amount, '0', 4 ) <= 0 ) {
			return new \WP_Error( 'cntr_expense_bad_amount', __( 'An expense amount must be greater than zero.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $spent_on ) ) {
			return new \WP_Error( 'cntr_expense_bad_date', __( 'An expense needs a real date.', 'counter' ), [ 'status' => 422 ] );
		}

		global $wpdb;
		$table  = Install::table( 'expenses' );
		$ref_no = sprintf( 'EXP-%06d', Db::next_seq( 'expense_ref' ) );

		$wpdb->insert(
			$table,
			[
				'ref_no'      => $ref_no,
				'category_id' => $category_id,
				'location_id' => $location_id,
				'account_id'  => $account_id,
				'amount'      => $amount,
				'channel'     => $channel,
				'spent_on'    => $spent_on,
				'status'      => 'confirmed',
				'note'        => (string) ( $args['note'] ?? '' ),
				'user_id'     => (int) ( $args['user_id'] ?? get_current_user_id() ),
				'created_at'  => Db::now(),
			]
		);

		return (int) $wpdb->insert_id;
	}

	/** @return true|\WP_Error */
	public static function confirm( int $expense_id ) {
		global $wpdb;
		$table = Install::table( 'expenses' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE id = %d", $expense_id ), ARRAY_A );
		if ( ! $row ) {
			return new \WP_Error( 'cntr_expense_missing', __( 'Expense not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'draft' !== $row['status'] ) {
			return new \WP_Error( 'cntr_expense_not_draft', __( 'Only a draft expense can be confirmed.', 'counter' ), [ 'status' => 409 ] );
		}
		$wpdb->update( $table, [ 'status' => 'confirmed' ], [ 'id' => $expense_id ] );
		return true;
	}

	public static function drafts(): array {
		global $wpdb;
		$table = Install::table( 'expenses' );
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'draft' ORDER BY spent_on", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'expenses' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	// -- Recurring rules (Settings-backed, P4.6's own map-shaped-setting idiom) --

	public static function recurring_rules(): array {
		$decoded = json_decode( (string) Settings::get( 'expenses.recurring_rules' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @return int the new rule's own id, stable across edits so generate_recurring_drafts() stays idempotent for it. */
	public static function add_recurring_rule( array $rule ): int {
		$rules  = self::recurring_rules();
		$new_id = 1;
		foreach ( $rules as $r ) {
			$new_id = max( $new_id, (int) ( $r['id'] ?? 0 ) + 1 );
		}
		$rule['id'] = $new_id;
		$rules[]    = $rule;
		Settings::set( 'expenses.recurring_rules', wp_json_encode( $rules ) );
		return $new_id;
	}

	public static function remove_recurring_rule( int $rule_id ): void {
		$rules = array_values( array_filter( self::recurring_rules(), static fn( $r ) => (int) ( $r['id'] ?? 0 ) !== $rule_id ) );
		Settings::set( 'expenses.recurring_rules', wp_json_encode( $rules ) );
	}

	/**
	 * One draft per (rule, period), ever — a second call for the same
	 * period is a no-op for every rule already generated, enforced by
	 * `cntr_expenses`' own `UNIQUE KEY ref_no`, not a SELECT-then-INSERT
	 * race.
	 *
	 * @return array{created:int[],skipped:string[]}
	 */
	public static function generate_recurring_drafts( string $period ): array {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return [ 'created' => [], 'skipped' => [] ];
		}

		global $wpdb;
		$table    = Install::table( 'expenses' );
		$spent_on = $period . '-01';
		$created  = [];
		$skipped  = [];

		foreach ( self::recurring_rules() as $rule ) {
			$rule_id = (int) ( $rule['id'] ?? 0 );
			$ref_no  = sprintf( 'REC-%d-%s', $rule_id, $period );
			$channel = in_array( $rule['channel'] ?? '', [ 'pos', 'online' ], true ) ? $rule['channel'] : '';

			$ok = $wpdb->insert(
				$table,
				[
					'ref_no'         => $ref_no,
					'category_id'    => (int) ( $rule['category_id'] ?? 0 ),
					'location_id'    => (int) ( $rule['location_id'] ?? 0 ),
					'account_id'     => (int) ( $rule['account_id'] ?? 0 ),
					'amount'         => wc_format_decimal( $rule['amount'] ?? '0', 4 ),
					'channel'        => $channel,
					'spent_on'       => $spent_on,
					'status'         => 'draft',
					'recurring_json' => wp_json_encode( $rule ),
					'note'           => (string) ( $rule['note'] ?? '' ),
					'user_id'        => 0,
					'created_at'     => Db::now(),
				]
			);

			if ( $ok ) {
				$created[] = (int) $wpdb->insert_id;
			} else {
				$skipped[] = $ref_no; // already generated for this rule + period
			}
		}

		return [ 'created' => $created, 'skipped' => $skipped ];
	}

	// -- The profit and loss --------------------------------------------------

	/**
	 * $args: from, to ('Y-m-d', inclusive both ends), channel
	 * (pos|online|all, default all), location_id (0 = every location).
	 *
	 * Revenue/COGS/gross margin read `cntr_sales_daily` (P5.1) — never
	 * re-derived a second way. Expenses read `cntr_expenses` WHERE
	 * `status = 'confirmed'` only; a draft has not been looked at yet and
	 * must not move a number the owner is about to trust.
	 *
	 * Unallocated expenses are never split into the channel view: a
	 * channel-scoped call (`channel = 'pos'` or `'online'`) nets that
	 * channel's own gross margin against only that channel's own tagged
	 * expenses — never the other channel's, and never unallocated, which
	 * cannot honestly be assigned to one channel alone ("rather than being
	 * spread by a guess," Direction's own words). `channel = 'all'` is the
	 * only view whose `net` is a genuine bottom line: gross margin less
	 * every expense, whatever its own channel tag.
	 */
	public static function profit_and_loss( array $args = [] ): array {
		$from         = (string) ( $args['from'] ?? '' );
		$to           = (string) ( $args['to'] ?? '' );
		// B6 — the ternary's own TRUE branch used to read $args['channel']
		// directly rather than $channel_raw: harmless whenever a caller
		// passed a real channel, but a caller that omits the key entirely
		// (the DEFAULT, 'all', case) took the true branch anyway — 'all' is
		// in the allow-list — and then warned on the same undefined key the
		// null-coalesce just worked around. Found live: test_sale_documents()
		// is the first caller in this codebase to call profit_and_loss()
		// with no 'channel' key at all.
		$channel_raw  = $args['channel'] ?? 'all';
		$channel      = in_array( $channel_raw, [ 'pos', 'online', 'all' ], true ) ? $channel_raw : 'all';
		$location_id  = (int) ( $args['location_id'] ?? 0 );

		global $wpdb;
		$sales_table = Install::table( 'sales_daily' );

		$sd_where  = 'WHERE day >= %s AND day <= %s';
		$sd_params = [ $from, $to ];
		if ( 'all' !== $channel ) {
			$sd_where   .= ' AND channel = %s';
			$sd_params[] = $channel;
		}
		if ( $location_id > 0 ) {
			$sd_where   .= ' AND location_id = %d';
			$sd_params[] = $location_id;
		}
		$rev_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(net),0) AS revenue, COALESCE(SUM(cogs),0) AS cogs, COALESCE(SUM(margin),0) AS gross_margin
				 FROM {$sales_table} {$sd_where}",
				...$sd_params
			),
			ARRAY_A
		);

		$exp_table  = Install::table( 'expenses' );
		// Every reference qualified with the "e" alias, including inside
		// $exp_where — the by-category query below JOINs cntr_expense_categories,
		// which has its own "status" column, so an unqualified "status" is
		// genuinely ambiguous there (found live: it silently returned zero
		// rows for by_category, though the three single-table queries below
		// still worked, since only they had no second table in scope to be
		// ambiguous against). One alias, "e", used everywhere — including
		// the single-table queries, where it is not ambiguous but keeps the
		// WHERE clause identical text across all four queries.
		$exp_where  = 'WHERE e.status = %s AND e.spent_on >= %s AND e.spent_on <= %s';
		$exp_params = [ 'confirmed', $from, $to ];
		if ( $location_id > 0 ) {
			$exp_where   .= ' AND e.location_id = %d';
			$exp_params[] = $location_id;
		}

		// By category — always the full picture regardless of the channel
		// arg, since an owner reading a P&L needs to see where the money
		// actually went, not have it silently filtered away.
		$categories_table = Install::table( 'expense_categories' );
		$by_category       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.category_id, c.name AS category_name, COALESCE(SUM(e.amount),0) AS amount
				 FROM {$exp_table} e LEFT JOIN {$categories_table} c ON c.id = e.category_id
				 {$exp_where} GROUP BY e.category_id ORDER BY amount DESC",
				...$exp_params
			),
			ARRAY_A
		);
		foreach ( $by_category as &$row ) {
			$row['category_id'] = (int) $row['category_id'];
			$row['amount']      = wc_format_decimal( $row['amount'], 4 );
		}
		unset( $row );

		$expenses_pos         = wc_format_decimal( (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(e.amount),0) FROM {$exp_table} e {$exp_where} AND e.channel = 'pos'", ...$exp_params ) ), 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $exp_where itself built from %-placeholders only
		$expenses_online      = wc_format_decimal( (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(e.amount),0) FROM {$exp_table} e {$exp_where} AND e.channel = 'online'", ...$exp_params ) ), 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$expenses_unallocated = wc_format_decimal( (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(e.amount),0) FROM {$exp_table} e {$exp_where} AND e.channel = ''", ...$exp_params ) ), 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$expenses_total        = bcadd( bcadd( $expenses_pos, $expenses_online, 4 ), $expenses_unallocated, 4 );

		$expenses_for_net = match ( $channel ) {
			'pos'    => $expenses_pos,
			'online' => $expenses_online,
			default  => $expenses_total,
		};

		$result = [
			'from'                 => $from,
			'to'                   => $to,
			'channel'              => $channel,
			'location_id'          => $location_id,
			'revenue'              => wc_format_decimal( $rev_row['revenue'], 4 ),
			'cogs'                 => wc_format_decimal( $rev_row['cogs'], 4 ),
			'gross_margin'         => wc_format_decimal( $rev_row['gross_margin'], 4 ),
			'expenses_by_category' => $by_category,
			'expenses_channel'     => [ 'pos' => $expenses_pos, 'online' => $expenses_online ],
			'expenses_unallocated' => $expenses_unallocated,
			'expenses_total'       => $expenses_total,
			'net'                  => wc_format_decimal( bcsub( $rev_row['gross_margin'], $expenses_for_net, 4 ), 4 ),
		];

		// Cost/margin visibility is gated the same way every other Counter
		// money report gates it (§7.3 rule 5) — a user without cntr_view_cost
		// gets the keys genuinely absent, not zeroed.
		if ( ! current_user_can( 'cntr_view_cost' ) ) {
			unset( $result['cogs'], $result['gross_margin'], $result['net'] );
		}

		return $result;
	}
}
