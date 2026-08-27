<?php
namespace Counter\Reports;

use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * P5.3 — the three questions an owner actually asks: what did we make
 * yesterday, what is running out, and did the drawer balance last night.
 *
 * "Yesterday" reads `cntr_sales_daily` only — one SUM() over a table already
 * keyed by (day, channel, location_id), never a scan over orders. The other
 * two panels are not rollup rows (no table aggregates stock levels or shift
 * variance by day) and read their own small, purpose-built queries instead —
 * each a single indexed lookup, never Reports::reorder_list()'s own
 * wc_get_product() per row, which would blow the 800 ms / <15 query budget
 * on any shop with more than a handful of low-stock lines. Documented in
 * docs/decisions.md under P5.3.
 */
class Dashboard {

	/** Shop-local 'Y-m-d' for the calendar day immediately before today — pure date arithmetic, no timezone conversion needed since both ends are already shop-local. */
	public static function yesterday_local(): string {
		return gmdate( 'Y-m-d', strtotime( wp_date( 'Y-m-d' ) . ' -1 day' ) );
	}

	public static function data( int $location_id = 0 ): array {
		return [
			'day'       => self::yesterday_local(),
			'yesterday' => self::yesterday( $location_id ),
			'low_stock' => self::low_stock( $location_id ),
			'shifts'    => self::shifts_last_night( $location_id ),
			'trending'  => self::trending( $location_id ),
		];
	}

	/**
	 * D2 — "what sells and when," over the last 7 shop-local days (today
	 * inclusive): a single day is too small a sample for "trending" to mean
	 * anything, unlike yesterday()'s own single-day question above.
	 * Reports::margin_by_product() already does the units/revenue/margin
	 * computation this needs (documented there as a deliberate approximation
	 * — good enough for "what's worth the shelf space," not a P&L); this
	 * just asks for it twice, sorted two ways, rather than adding a second
	 * query that would inevitably drift from the first.
	 */
	private static function trending( int $location_id, int $days = 7, int $limit = 5 ): array {
		$to   = wp_date( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', strtotime( $to . " -{$days} days" ) );

		$products = Reports::margin_by_product(
			[
				'location_id' => $location_id,
				'date_from'   => Rollup::local_day_start_utc( $from ),
				'date_to'     => Rollup::local_day_end_utc( $to ),
			]
		);

		$by_units = $products;
		usort( $by_units, static fn( $a, $b ) => bccomp( $b['qty_sold'], $a['qty_sold'], 4 ) );
		$by_revenue = $products;
		usort( $by_revenue, static fn( $a, $b ) => bccomp( $b['revenue'] ?? '0', $a['revenue'] ?? '0', 4 ) );

		$peak_hours = Reports::run( 'sales_by_hour', [ 'from' => $from, 'to' => $to, 'location_id' => $location_id ] );
		$peak_hours = is_wp_error( $peak_hours ) ? [] : $peak_hours;
		usort( $peak_hours, static fn( $a, $b ) => bccomp( $b['qty_sold'], $a['qty_sold'], 4 ) );

		return [
			'from'        => $from,
			'to'          => $to,
			'top_units'   => array_slice( $by_units, 0, $limit ),
			'top_revenue' => array_slice( $by_revenue, 0, $limit ),
			'peak_hours'  => array_slice( $peak_hours, 0, 3 ),
		];
	}

	/** Sums cntr_sales_daily for yesterday (shop-local), across channels. One query. */
	private static function yesterday( int $location_id ): array {
		global $wpdb;
		$table = Install::table( 'sales_daily' );
		$day   = self::yesterday_local();

		$where  = 'WHERE day = %s';
		$params = [ $day ];
		if ( $location_id > 0 ) {
			$where   .= ' AND location_id = %d';
			$params[] = $location_id;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(orders_count),0) AS orders_count, COALESCE(SUM(gross),0) AS gross,
				        COALESCE(SUM(net),0) AS net, COALESCE(SUM(refunds),0) AS refunds,
				        COALESCE(SUM(cogs),0) AS cogs, COALESCE(SUM(margin),0) AS margin,
				        COALESCE(SUM(items_count),0) AS items_count
				 FROM {$table} {$where}",
				...$params
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$row = [ 'orders_count' => 0, 'gross' => '0.0000', 'net' => '0.0000', 'refunds' => '0.0000', 'cogs' => '0.0000', 'margin' => '0.0000', 'items_count' => '0.0000' ];
		}
		$row['orders_count'] = (int) $row['orders_count'];

		// A percentage needs its own guard — a day with no sales has gross
		// 0.0000, and margin ÷ gross on that day is a division by zero, not
		// a 0% margin. Zero sales is not the same fact as zero margin.
		$row['margin_pct'] = 0 === bccomp( $row['gross'], '0', 4 ) ? '0.0000' : bcmul( bcdiv( $row['margin'], $row['gross'], 6 ), '100', 2 );

		if ( ! current_user_can( 'cntr_view_cost' ) ) {
			unset( $row['cogs'], $row['margin'], $row['margin_pct'] );
		}

		return $row;
	}

	/**
	 * Stock at or below the product's own WooCommerce low-stock amount, in
	 * one indexed query — no wc_get_product() per row (Reports::reorder_list()'s
	 * own approach, correct for a dedicated stock report but too slow for a
	 * screen with an 800 ms budget on a shop with many low-stock lines). A
	 * product with no threshold set never appears, matching reorder_list()'s
	 * own rule: "alert quantity left unset means don't alert."
	 */
	private static function low_stock( int $location_id, int $limit = 8 ): array {
		global $wpdb;
		$stock = Install::table( 'stock' );

		$where  = '';
		$params = [];
		if ( $location_id > 0 ) {
			$where    = 'AND s.location_id = %d';
			$params[] = $location_id;
		}
		$params[] = $limit;

		$sql = "SELECT s.product_id, s.variation_id, s.location_id, s.qty, p.post_title AS name, pm.meta_value AS threshold
		         FROM {$stock} s
		         INNER JOIN {$wpdb->posts} p ON p.ID = IF(s.variation_id > 0, s.variation_id, s.product_id)
		         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_low_stock_amount' AND pm.meta_value != ''
		         WHERE CAST(s.qty AS DECIMAL(14,4)) <= CAST(pm.meta_value AS DECIMAL(14,4)) {$where}
		         ORDER BY (CAST(pm.meta_value AS DECIMAL(14,4)) - CAST(s.qty AS DECIMAL(14,4))) DESC
		         LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from constants and %-placeholders only

		foreach ( $rows as &$row ) {
			$row['product_id']   = (int) $row['product_id'];
			$row['variation_id'] = (int) $row['variation_id'];
			$row['location_id']  = (int) $row['location_id'];
			$row['qty']          = wc_format_decimal( $row['qty'], 4 );
			$row['threshold']    = wc_format_decimal( $row['threshold'], 4 );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Shifts closed inside "last night" — yesterday's shop-local calendar
	 * day, converted to the UTC window closed_at is actually stored in
	 * (Rollup::local_day_start_utc()/local_day_end_utc(), the same
	 * conversion the rollup itself uses, not a second hand-rolled one).
	 */
	private static function shifts_last_night( int $location_id ): array {
		global $wpdb;
		$shifts    = Install::table( 'shifts' );
		$registers = Install::table( 'registers' );
		$day       = self::yesterday_local();

		$where  = 'WHERE sh.closed_at >= %s AND sh.closed_at <= %s';
		$params = [ Rollup::local_day_start_utc( $day ), Rollup::local_day_end_utc( $day ) ];
		if ( $location_id > 0 ) {
			$where   .= ' AND r.location_id = %d';
			$params[] = $location_id;
		}

		$sql = "SELECT sh.id, sh.register_id, r.name AS register_name, sh.closed_at,
		               sh.expected_cash, sh.counted_cash, sh.variance
		         FROM {$shifts} sh LEFT JOIN {$registers} r ON r.id = sh.register_id
		         {$where} ORDER BY sh.closed_at";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from constants and %-placeholders only

		foreach ( $rows as &$row ) {
			$row['id']            = (int) $row['id'];
			$row['register_id']   = (int) $row['register_id'];
			$row['balanced']      = 0 === bccomp( $row['variance'], '0', 4 );
		}
		unset( $row );

		return $rows;
	}
}
