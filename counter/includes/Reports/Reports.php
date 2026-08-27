<?php
namespace Counter\Reports;

use Counter\Install;
use Counter\Db;
use Counter\Settings;
use Counter\Stock\Batches;

defined( 'ABSPATH' ) || exit;

/**
 * P2.11 — valuation, margin, dead stock, reorder, near expiry, movement
 * history. Every one takes a `location_id` filter and a `date_from`/`date_to`
 * range in $args where the report is inherently time-series (movement
 * history, dead stock's own window, margin); valuation and reorder are
 * point-in-time snapshots and accept the same $args shape for a consistent
 * interface even though a date range has nothing to filter there.
 *
 * cntr_view_cost gates anything showing cost or margin — enforced HERE, in
 * gate_cost(), before a row ever reaches a template or a REST response. A
 * user without it gets a row with the cost keys genuinely ABSENT, never
 * present-and-zero — zero is a real valuation a product can legitimately
 * have; absence is the only unambiguous "you may not see this."
 */
class Reports {

	/**
	 * P5.2 — one contract, applied without exception:
	 * `Reports::run( string $report, array $args )` — $args always accepts
	 * from/to ('Y-m-d', inclusive both ends), channel (pos|online|all,
	 * default all), location_id (0 = every location), group_by (report-
	 * specific, ignored where not applicable).
	 *
	 * Reads from cntr_sales_daily and the order/ledger/tender tables
	 * directly — never WooCommerce Analytics' own lookup tables. Adding a
	 * channel column there would mean extending its REST controllers and
	 * rebuilding its React admin bundle, which contradicts this plugin's
	 * no-build-step rule and pins it to Analytics' internals; Analytics
	 * keeps doing what it already does well (the combined view) and this
	 * class does the channel-split half it cannot.
	 */
	const REPORT_NAMES = [
		'sales_summary', 'sales_by_product', 'sales_by_category', 'sales_by_cashier',
		'sales_by_hour', 'tender_mix', 'discounts', 'refunds', 'margin', 'tax_collected',
		'register_history',
	];

	/**
	 * @return array|\WP_Error
	 */
	public static function run( string $report, array $args = [] ) {
		if ( ! in_array( $report, self::REPORT_NAMES, true ) ) {
			return new \WP_Error( 'cntr_report_unknown', sprintf( __( 'Unknown report: %s', 'counter' ), $report ), [ 'status' => 404 ] );
		}
		$args = self::normalize_args( $args );

		return match ( $report ) {
			'sales_summary'     => self::report_sales_summary( $args ),
			'sales_by_product'  => self::report_sales_by_product( $args ),
			'sales_by_category' => self::report_sales_by_category( $args ),
			'sales_by_cashier'  => self::report_sales_by_cashier( $args ),
			'sales_by_hour'     => self::report_sales_by_hour( $args ),
			'tender_mix'        => self::report_tender_mix( $args ),
			'discounts'         => self::report_discounts( $args ),
			'refunds'           => self::report_refunds( $args ),
			'margin'            => self::report_margin( $args ),
			'tax_collected'     => self::report_tax_collected( $args ),
			'register_history'  => self::report_register_history( $args ),
		};
	}

	/**
	 * P5.4 — a thin delegator, not a second computation: `Expenses::profit_and_loss()`
	 * owns the real logic (its own row shape is one object, not a list of
	 * rows, so it does not fit `run()`'s own REPORT_NAMES contract without
	 * distorting every consumer of that contract — `test_reports_channel()`'s
	 * own check 1 sums one representative column across N rows for every
	 * name in REPORT_NAMES, which a single-object report cannot honestly
	 * participate in). This exists so a P&L is reachable from the same
	 * place every other report is, for any caller that already depends on
	 * `Reports::`.
	 *
	 * @return array|\WP_Error
	 */
	public static function profit_and_loss( array $args = [] ) {
		return \Counter\Reports\Expenses::profit_and_loss( $args );
	}

	/**
	 * @return string|\WP_Error CSV text — the SAME rows run() would return
	 * for identical $args, through the SAME export_csv() every P2.11 report
	 * already uses. A report that exports different figures from what it
	 * displays is worse than no export at all — this is what makes that
	 * structurally impossible rather than merely tested for.
	 */
	public static function export( string $report, array $args = [] ) {
		$rows = self::run( $report, $args );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return self::export_csv( $rows );
	}

	private static function normalize_args( array $args ): array {
		$channel = (string) ( $args['channel'] ?? 'all' );
		return [
			'from'        => (string) ( $args['from'] ?? '' ),
			'to'          => (string) ( $args['to'] ?? '' ),
			'channel'     => in_array( $channel, [ 'pos', 'online', 'all' ], true ) ? $channel : 'all',
			'location_id' => (int) ( $args['location_id'] ?? 0 ),
			'group_by'    => (string) ( $args['group_by'] ?? '' ),
		];
	}

	/**
	 * cntr_sales_daily already has BOTH a day column and a channel column —
	 * every report built on it composes its WHERE from these two building
	 * blocks plus an optional location_id equality, never duplicating the
	 * date/channel logic per report.
	 */
	private static function sales_daily_where( array $args ): array {
		$where  = 'WHERE 1=1';
		$params = [];
		if ( '' !== $args['from'] ) {
			$where   .= ' AND day >= %s';
			$params[] = $args['from'];
		}
		if ( '' !== $args['to'] ) {
			$where   .= ' AND day <= %s';
			$params[] = $args['to'];
		}
		if ( 'all' !== $args['channel'] ) {
			$where   .= ' AND channel = %s';
			$params[] = $args['channel'];
		}
		if ( $args['location_id'] > 0 ) {
			$where   .= ' AND location_id = %d';
			$params[] = $args['location_id'];
		}
		return [ $where, $params ];
	}

	private static function report_sales_summary( array $args ): array {
		global $wpdb;
		$table = Install::table( 'sales_daily' );
		[ $where, $params ] = self::sales_daily_where( $args );
		$sql = "SELECT COALESCE(SUM(orders_count),0) orders_count, COALESCE(SUM(gross),0) gross,
		               COALESCE(SUM(discount),0) discount, COALESCE(SUM(tax),0) tax,
		               COALESCE(SUM(shipping),0) shipping, COALESCE(SUM(rounding),0) rounding,
		               COALESCE(SUM(refunds),0) refunds, COALESCE(SUM(net),0) net,
		               COALESCE(SUM(cogs),0) cogs, COALESCE(SUM(margin),0) margin,
		               COALESCE(SUM(items_count),0) items_count
		        FROM {$table} {$where}";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built entirely from this method's own literals
		return self::gate_cost( [ $row ], [ 'cogs', 'margin' ] );
	}

	private static function report_tax_collected( array $args ): array {
		global $wpdb;
		$table = Install::table( 'sales_daily' );
		[ $where, $params ] = self::sales_daily_where( $args );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT day, channel, location_id, SUM(tax) AS tax FROM {$table} {$where} GROUP BY day, channel, location_id ORDER BY day", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	private static function report_discounts( array $args ): array {
		global $wpdb;
		$table = Install::table( 'sales_daily' );
		[ $where, $params ] = self::sales_daily_where( $args );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT day, channel, location_id, SUM(discount) AS discount FROM {$table} {$where} GROUP BY day, channel, location_id ORDER BY day", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	private static function report_refunds( array $args ): array {
		global $wpdb;
		$table = Install::table( 'sales_daily' );
		[ $where, $params ] = self::sales_daily_where( $args );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT day, channel, location_id, SUM(refunds) AS refunds FROM {$table} {$where} GROUP BY day, channel, location_id ORDER BY day", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
	}

	private static function report_margin( array $args ): array {
		global $wpdb;
		$table = Install::table( 'sales_daily' );
		[ $where, $params ] = self::sales_daily_where( $args );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT day, channel, location_id, SUM(cogs) AS cogs, SUM(margin) AS margin, SUM(net) AS net FROM {$table} {$where} GROUP BY day, channel, location_id ORDER BY day", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return self::gate_cost( $rows, [ 'cogs', 'margin' ] );
	}

	/** stock_moves already carries channel and location per row — the finer-grained reports (product/category/hour) read it directly, never sales_daily's own day-level aggregate. */
	private static function report_sales_by_product( array $args ): array {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		[ $where, $params ] = self::moves_where( $args );
		$sql  = "SELECT product_id, variation_id, SUM(qty_delta * -1) AS qty_sold, SUM(qty_delta * -1 * unit_cost) AS cogs
		          FROM {$moves_table} {$where} GROUP BY product_id, variation_id ORDER BY qty_sold DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as &$row ) {
			$row['product_id']   = (int) $row['product_id'];
			$row['variation_id'] = (int) $row['variation_id'];
			$row['qty_sold']     = wc_format_decimal( $row['qty_sold'], 4 );
			$row['cogs']         = wc_format_decimal( $row['cogs'], 4 );
			$product              = wc_get_product( $row['variation_id'] ?: $row['product_id'] );
			$row['name']          = $product ? $product->get_name() : '';
		}
		unset( $row );
		return self::gate_cost( $rows, [ 'cogs' ] );
	}

	private static function report_sales_by_category( array $args ): array {
		$by_product = self::report_sales_by_product( $args );
		$by_cat     = [];
		foreach ( $by_product as $row ) {
			$product = wc_get_product( $row['product_id'] );
			$cats    = $product ? $product->get_category_ids() : [];
			if ( empty( $cats ) ) {
				$cats = [ 0 ]; // "Uncategorised" bucket — still counted, never silently dropped
			}
			foreach ( $cats as $cat_id ) {
				if ( ! isset( $by_cat[ $cat_id ] ) ) {
					$term               = $cat_id ? get_term( $cat_id, 'product_cat' ) : null;
					$by_cat[ $cat_id ]  = [
						'category_id'   => (int) $cat_id,
						'category_name' => ( $term && ! is_wp_error( $term ) ) ? $term->name : __( 'Uncategorised', 'counter' ),
						'qty_sold'      => '0.0000',
						'cogs'          => '0.0000',
					];
				}
				$by_cat[ $cat_id ]['qty_sold'] = bcadd( $by_cat[ $cat_id ]['qty_sold'], $row['qty_sold'], 4 );
				if ( isset( $row['cogs'] ) ) {
					$by_cat[ $cat_id ]['cogs'] = bcadd( $by_cat[ $cat_id ]['cogs'], $row['cogs'], 4 );
				} else {
					unset( $by_cat[ $cat_id ]['cogs'] );
				}
			}
		}
		return array_values( $by_cat );
	}

	/** Hour-of-day (0-23, shop-local) bucketing — done in PHP, never in SQL, so it goes through the exact same wp_date() conversion as Reports\Rollup's own day boundary, not a second, potentially-diverging one. */
	private static function report_sales_by_hour( array $args ): array {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );
		[ $where, $params ] = self::moves_where( $args );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT qty_delta, created_at FROM {$moves_table} {$where}", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$hours = [];
		for ( $h = 0; $h < 24; $h++ ) {
			$hours[ $h ] = [ 'hour' => $h, 'qty_sold' => '0.0000' ];
		}
		foreach ( $rows as $row ) {
			$hour = (int) wp_date( 'G', strtotime( $row['created_at'] . ' UTC' ) );
			$hours[ $hour ]['qty_sold'] = bcadd( $hours[ $hour ]['qty_sold'], (string) abs( (float) $row['qty_delta'] ), 4 );
		}
		return array_values( $hours );
	}

	/** WHERE/params shared by the three stock_moves-based reports above — sale-only, plus channel/location/date. */
	private static function moves_where( array $args ): array {
		$where  = "WHERE reason IN ('sale','sale_online')";
		$params = [];
		if ( '' !== $args['from'] ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( '' !== $args['to'] ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		if ( 'all' !== $args['channel'] ) {
			$where   .= ' AND channel = %s';
			$params[] = $args['channel'];
		}
		if ( $args['location_id'] > 0 ) {
			$where   .= ' AND location_id = %d';
			$params[] = $args['location_id'];
		}
		return [ $where, $params ];
	}

	/**
	 * cntr_tenders has no channel/location column of its own — both reports
	 * below join to the order it belongs to (wc_orders_meta for channel,
	 * cntr_shifts -> cntr_registers for location) rather than duplicating
	 * either anywhere.
	 */
	private static function tenders_where_and_joins( array $args ): array {
		global $wpdb;
		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		$shifts      = Install::table( 'shifts' );
		$registers   = Install::table( 'registers' );

		$joins  = "LEFT JOIN {$orders_meta} om ON om.order_id = t.order_id AND om.meta_key = '_cntr_channel'
		            LEFT JOIN {$shifts} sh ON sh.id = t.shift_id
		            LEFT JOIN {$registers} r ON r.id = sh.register_id";
		$where  = 'WHERE t.is_change = 0';
		$params = [];
		if ( '' !== $args['from'] ) {
			$where   .= ' AND t.created_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( '' !== $args['to'] ) {
			$where   .= ' AND t.created_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		if ( 'all' !== $args['channel'] ) {
			// A POS order is always explicitly tagged; anything else (NULL
			// included — an order this old or malformed enough to carry no
			// tag at all) reads as 'online', same fallback Channel.php itself
			// uses everywhere.
			$where   .= 'pos' === $args['channel'] ? " AND om.meta_value = 'pos'" : " AND COALESCE(om.meta_value,'online') != 'pos'";
		}
		if ( $args['location_id'] > 0 ) {
			$where   .= ' AND r.location_id = %d';
			$params[] = $args['location_id'];
		}
		return [ $joins, $where, $params ];
	}

	private static function report_tender_mix( array $args ): array {
		global $wpdb;
		$table = Install::table( 'tenders' );
		[ $joins, $where, $params ] = self::tenders_where_and_joins( $args );
		$sql = "SELECT t.method, SUM(t.amount) AS amount, COUNT(*) AS count
		         FROM {$table} t {$joins} {$where} GROUP BY t.method ORDER BY amount DESC";
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function report_sales_by_cashier( array $args ): array {
		global $wpdb;
		$table = Install::table( 'tenders' );
		[ $joins, $where, $params ] = self::tenders_where_and_joins( $args );
		$sql = "SELECT t.user_id, SUM(t.amount) AS amount, COUNT(DISTINCT t.order_id) AS orders_count
		         FROM {$table} t {$joins} {$where} GROUP BY t.user_id ORDER BY amount DESC";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows as &$row ) {
			$row['user_id'] = (int) $row['user_id'];
			$user             = $row['user_id'] ? get_userdata( $row['user_id'] ) : false;
			$row['name']      = $user ? $user->display_name : __( 'Unknown', 'counter' );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Register/Z history. Channel is inapplicable — a shift close is
	 * inherently a POS/till concept, no online equivalent exists — so
	 * 'online' returns nothing and 'pos'/'all' both return the same full
	 * history, which is what keeps "channel=all equals pos plus online"
	 * true here too, honestly, rather than as a special case.
	 */
	private static function report_register_history( array $args ): array {
		if ( 'online' === $args['channel'] ) {
			return [];
		}
		global $wpdb;
		$shifts    = Install::table( 'shifts' );
		$registers = Install::table( 'registers' );

		$where  = 'WHERE sh.closed_at IS NOT NULL';
		$params = [];
		if ( '' !== $args['from'] ) {
			$where   .= ' AND sh.closed_at >= %s';
			$params[] = $args['from'] . ' 00:00:00';
		}
		if ( '' !== $args['to'] ) {
			$where   .= ' AND sh.closed_at <= %s';
			$params[] = $args['to'] . ' 23:59:59';
		}
		if ( $args['location_id'] > 0 ) {
			$where   .= ' AND r.location_id = %d';
			$params[] = $args['location_id'];
		}

		$sql = "SELECT sh.id, sh.register_id, sh.user_id, sh.opened_at, sh.closed_at,
		               sh.opening_float, sh.expected_cash, sh.counted_cash, sh.variance
		         FROM {$shifts} sh LEFT JOIN {$registers} r ON r.id = sh.register_id
		         {$where} ORDER BY sh.closed_at";
		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no unescaped variables when $params is empty
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $rows as &$row ) {
			$row['id']          = (int) $row['id'];
			$row['register_id'] = (int) $row['register_id'];
			$row['user_id']     = (int) $row['user_id'];
		}
		unset( $row );
		return $rows;
	}

	/** Valuation at FIFO cost — SUM(qty_remaining × unit_cost) per product, from the batches actually on hand right now. */
	public static function valuation( array $args = [] ): array {
		global $wpdb;
		$table = Install::table( 'batches' );

		$where  = 'WHERE qty_remaining > 0';
		$params = [];
		if ( ! empty( $args['location_id'] ) ) {
			$where   .= ' AND location_id = %d';
			$params[] = (int) $args['location_id'];
		}

		$sql  = "SELECT product_id, variation_id, SUM(qty_remaining) AS qty, SUM(qty_remaining * unit_cost) AS value
		          FROM {$table} {$where} GROUP BY product_id, variation_id";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql has no unescaped variables when $params is empty

		foreach ( $rows as &$row ) {
			// $wpdb always returns these as PHP strings regardless of the
			// underlying bigint column — cast explicitly so a caller doing
			// $row['product_id'] === $product->get_id() (a real int) isn't
			// silently comparing "123" to 123. Found live: test_stock_reports()
			// itself failed this exact way before this cast existed.
			$row['product_id']    = (int) $row['product_id'];
			$row['variation_id']  = (int) $row['variation_id'];
			$row['qty']            = wc_format_decimal( $row['qty'], 4 );
			$row['value']          = wc_format_decimal( $row['value'], 4 );
			$row['avg_unit_cost']  = bccomp( $row['qty'], '0', 4 ) > 0 ? bcdiv( $row['value'], $row['qty'], 4 ) : '0.0000';
		}
		unset( $row );

		return self::gate_cost( $rows, [ 'value', 'avg_unit_cost' ] );
	}

	/**
	 * Margin per product over a date range — COGS from the ledger's own
	 * sale-reason moves (real FIFO cost, P2.8), revenue from each product's
	 * CURRENT price. That is a deliberate, documented approximation: exact
	 * historical revenue lives on each order's own line items, not
	 * anywhere the ledger tracks — a price change since the sale would
	 * make this figure wrong for that period. Good enough for "which
	 * products are worth the shelf space," not a P&L statement.
	 */
	public static function margin_by_product( array $args = [] ): array {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );

		[ $where, $params ] = self::sale_where( $args );

		$sql  = "SELECT product_id, variation_id, SUM(qty_delta * -1) AS qty_sold, SUM(qty_delta * -1 * unit_cost) AS cogs
		          FROM {$moves_table} {$where} GROUP BY product_id, variation_id";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql has no unescaped variables when $params is empty

		foreach ( $rows as &$row ) {
			$row['product_id']   = (int) $row['product_id'];
			$row['variation_id'] = (int) $row['variation_id'];
			$row['qty_sold'] = wc_format_decimal( $row['qty_sold'], 4 );
			$row['cogs']     = wc_format_decimal( $row['cogs'], 4 );

			$product        = wc_get_product( $row['variation_id'] ?: $row['product_id'] );
			$price          = $product ? wc_format_decimal( $product->get_price(), 4 ) : '0.0000';
			$row['revenue'] = bcmul( $row['qty_sold'], $price, 4 );
			$row['margin']  = bcsub( $row['revenue'], $row['cogs'], 4 );
			// D2 — found live: every sibling report on this same query shape
			// (report_sales_by_product() above) already resolves this;
			// margin_by_product() never had, leaving any caller that
			// displays a product name (Dashboard's own trending panel) with
			// a silently-absent key instead.
			$row['name']    = $product ? $product->get_name() : '';
		}
		unset( $row );

		return self::gate_cost( $rows, [ 'cogs', 'revenue', 'margin' ] );
	}

	/**
	 * Still holding stock, but no sale (either channel) inside the
	 * configured window (Settings 'reports.dead_stock_days'). A product
	 * that has NEVER sold is dead regardless of the window's length — no
	 * sale row at all, not merely an old one.
	 */
	public static function dead_stock( array $args = [] ): array {
		global $wpdb;
		$stock_table = Install::table( 'stock' );
		$moves_table = Install::table( 'stock_moves' );

		$days     = (int) Settings::get( 'reports.dead_stock_days', 90 );
		$boundary = gmdate( 'Y-m-d H:i:s', strtotime( Db::now() ) - ( $days * DAY_IN_SECONDS ) );

		$where  = 'WHERE s.qty > 0';
		$params = [];
		if ( ! empty( $args['location_id'] ) ) {
			$where   .= ' AND s.location_id = %d';
			$params[] = (int) $args['location_id'];
		}

		$sql = "SELECT s.product_id, s.variation_id, s.location_id, s.qty,
		               ( SELECT MAX(m.created_at) FROM {$moves_table} m
		                 WHERE m.product_id = s.product_id AND m.variation_id = s.variation_id
		                 AND m.reason IN ('sale','sale_online') ) AS last_sale_at
		        FROM {$stock_table} s {$where}";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql has no unescaped variables when $params is empty

		$dead = [];
		foreach ( $rows as $row ) {
			$never_sold  = null === $row['last_sale_at'];
			$stale       = ! $never_sold && strtotime( $row['last_sale_at'] ) < strtotime( $boundary );
			if ( $never_sold || $stale ) {
				$dead[] = [
					'product_id'   => (int) $row['product_id'],
					'variation_id' => (int) $row['variation_id'],
					'location_id'  => (int) $row['location_id'],
					'qty'          => wc_format_decimal( $row['qty'], 4 ),
					'last_sale_at' => $row['last_sale_at'],
					'days'         => $days,
				];
			}
		}
		return $dead;
	}

	/**
	 * Balance at or below each product's own WooCommerce low-stock amount
	 * (get_low_stock_amount() — the native per-product alert quantity,
	 * falling back to the store-wide default itself; non-negotiable 1, this
	 * is what WooCommerce already has, not a new field to keep in sync). A
	 * product with no threshold configured at all (empty string) never
	 * appears — "alert quantity" left unset means "don't alert."
	 */
	public static function reorder_list( array $args = [] ): array {
		global $wpdb;
		$stock_table = Install::table( 'stock' );

		$where  = '';
		$params = [];
		if ( ! empty( $args['location_id'] ) ) {
			$where    = 'WHERE location_id = %d';
			$params[] = (int) $args['location_id'];
		}

		$sql  = "SELECT product_id, variation_id, location_id, qty FROM {$stock_table} {$where}";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql has no unescaped variables when $params is empty

		$out = [];
		foreach ( $rows as $row ) {
			$product = wc_get_product( $row['variation_id'] ?: $row['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$threshold = $product->get_low_stock_amount();
			if ( '' === $threshold || null === $threshold ) {
				continue;
			}
			$qty = wc_format_decimal( $row['qty'], 4 );
			if ( bccomp( $qty, wc_format_decimal( $threshold, 4 ), 4 ) <= 0 ) {
				$out[] = [
					'product_id'   => (int) $row['product_id'],
					'variation_id' => (int) $row['variation_id'],
					'location_id'  => (int) $row['location_id'],
					'qty'          => $qty,
					'threshold'    => wc_format_decimal( $threshold, 4 ),
				];
			}
		}
		return $out;
	}

	/** Delegates to Batches — the report layer never re-implements the query, only presents it. */
	public static function near_expiry( int $days, ?int $location_id = null ): array {
		return Batches::near_expiry( $days, $location_id );
	}

	/** Every ledger row for one product, reconciling exactly to cntr_stock_moves. */
	public static function movement_history( int $product_id, int $variation_id = 0, array $args = [] ): array {
		global $wpdb;
		$moves_table = Install::table( 'stock_moves' );

		$where  = 'WHERE product_id = %d AND variation_id = %d';
		$params = [ $product_id, $variation_id ];
		if ( ! empty( $args['location_id'] ) ) {
			$where   .= ' AND location_id = %d';
			$params[] = (int) $args['location_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = (string) $args['date_to'];
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$moves_table} {$where} ORDER BY id", ...$params ), ARRAY_A );
		return self::gate_cost( $rows, [ 'unit_cost' ] );
	}

	/**
	 * @return string|\WP_Error CSV text, or a WP_Error if the current user lacks cntr_export.
	 */
	public static function export_csv( array $rows ) {
		if ( ! current_user_can( 'cntr_export' ) ) {
			return new \WP_Error( 'cntr_export_forbidden', __( 'You do not have permission to export.', 'counter' ), [ 'status' => 403 ] );
		}
		if ( empty( $rows ) ) {
			return '';
		}
		$fh = fopen( 'php://temp', 'w+' );
		fputcsv( $fh, array_keys( $rows[0] ) );
		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/** WHERE/params shared by margin_by_product() — reason IN sale/sale_online, plus location/date filters. */
	private static function sale_where( array $args ): array {
		$where  = "WHERE reason IN ('sale','sale_online')";
		$params = [];
		if ( ! empty( $args['location_id'] ) ) {
			$where   .= ' AND location_id = %d';
			$params[] = (int) $args['location_id'];
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where   .= ' AND created_at <= %s';
			$params[] = (string) $args['date_to'];
		}
		return [ $where, $params ];
	}

	/**
	 * Strips $cost_keys from every row when the current user lacks
	 * cntr_view_cost — unset(), never a zeroed value. The one gate every
	 * report method routes cost/margin data through, so "enforced in the
	 * query, not the template" stays true even as more reports get added.
	 */
	private static function gate_cost( array $rows, array $cost_keys ): array {
		if ( current_user_can( 'cntr_view_cost' ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			foreach ( $cost_keys as $key ) {
				unset( $row[ $key ] );
			}
		}
		unset( $row );
		return $rows;
	}
}
