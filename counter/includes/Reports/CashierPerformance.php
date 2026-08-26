<?php
namespace Counter\Reports;

use Counter\Install;
use Counter\People\Employees;

defined( 'ABSPATH' ) || exit;

/**
 * P6.5 — sales by cashier, average basket, discount given, voids,
 * no-sales, variance history, gated on `cntr_view_all_sales`. A separate
 * class, not one more `Reports::REPORT_NAMES` entry: its own row shape is
 * VIEWER-SCOPED (a caller without `cntr_view_all_sales` gets only their
 * own row, never the full list filtered client-side), which
 * `test_reports_channel()`'s own "channel=all equals pos+online for every
 * report" loop cannot honestly participate in — the same reasoning
 * `Expenses::profit_and_loss()` (P5.4) was kept out for.
 *
 * Grouped by `_cntr_operator_id` order meta — the PIN-derived identity
 * `Rest\Sale::process()` now writes there (P6.5's own fix to that file:
 * it read `get_current_user_id()` unconditionally before this task),
 * never `cntr_tenders.user_id` (the WordPress account that happened to be
 * authenticated, which `report_sales_by_cashier`, P5.2, already reports
 * on its own terms and is left untouched here).
 */
class CashierPerformance {

	/**
	 * $args: from, to ('Y-m-d', inclusive). $viewer_user_id decides scope:
	 * holding `cntr_view_all_sales` sees every operator; otherwise only
	 * the viewer's OWN operator row (resolved via their own linked
	 * employee record) — never someone else's, and never filtered away
	 * silently if they have no employee record at all (they simply see
	 * nothing, the same "absence, not an error" pattern used everywhere
	 * else cost/scope is gated in this plugin).
	 */
	public static function report( array $args, int $viewer_user_id ): array {
		$from = (string) ( $args['from'] ?? '' );
		$to   = (string) ( $args['to'] ?? '' );

		global $wpdb;
		$orders_table = $wpdb->prefix . 'wc_orders';
		$orders_meta  = $wpdb->prefix . 'wc_orders_meta';

		$where  = "WHERE o.status = 'wc-completed'";
		$params = [];
		if ( '' !== $from ) {
			$where   .= ' AND o.date_created_gmt >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where   .= ' AND o.date_created_gmt <= %s';
			$params[] = $to . ' 23:59:59';
		}

		$sql = "SELECT o.id, o.total_amount, COALESCE(op.meta_value,0) AS operator_id
		         FROM {$orders_table} o
		         LEFT JOIN {$orders_meta} op ON op.order_id = o.id AND op.meta_key = '_cntr_operator_id'
		         {$where}";
		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no unescaped variables when $params is empty
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where built from %-placeholders only

		$by_operator = [];
		foreach ( $rows as $r ) {
			$op_id = (int) $r['operator_id'];
			if ( ! isset( $by_operator[ $op_id ] ) ) {
				$by_operator[ $op_id ] = [ 'operator_id' => $op_id, 'orders_count' => 0, 'sales' => '0.0000', 'discount_given' => '0.0000' ];
			}
			++$by_operator[ $op_id ]['orders_count'];
			$by_operator[ $op_id ]['sales'] = bcadd( $by_operator[ $op_id ]['sales'], wc_format_decimal( $r['total_amount'], 4 ), 4 );

			$order = wc_get_order( (int) $r['id'] );
			if ( $order ) {
				$by_operator[ $op_id ]['discount_given'] = bcadd( $by_operator[ $op_id ]['discount_given'], wc_format_decimal( $order->get_total_discount(), 4 ), 4 );
			}
		}

		foreach ( $by_operator as $op_id => &$row ) {
			$row['avg_basket']       = $row['orders_count'] > 0 ? bcdiv( $row['sales'], (string) $row['orders_count'], 4 ) : '0.0000';
			$row['voids']            = self::audit_count( 'void_line', $op_id, $from, $to );
			$row['no_sales']         = self::audit_count( 'no_sale', $op_id, $from, $to );
			$row['name']             = self::name_for_operator( $op_id );
			$row['variance_history'] = self::variance_history_for_operator( $op_id, $from, $to );
		}
		unset( $row );

		$result = array_values( $by_operator );

		if ( ! user_can( $viewer_user_id, 'cntr_view_all_sales' ) ) {
			$viewer_employee = Employees::get_by_user( $viewer_user_id );
			$own_id          = $viewer_employee ? (int) $viewer_employee['id'] : -1;
			$result          = array_values( array_filter( $result, static fn( $r ) => (int) $r['operator_id'] === $own_id ) );
		}

		return $result;
	}

	private static function audit_count( string $action, int $operator_id, string $from, string $to ): int {
		global $wpdb;
		$table  = Install::table( 'audit_log' );
		$where  = 'WHERE action = %s AND operator_id = %d';
		$params = [ $action, $operator_id ];
		if ( '' !== $from ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where built from %-placeholders only
	}

	/**
	 * A shift is opened/closed by an authenticated WordPress account, not
	 * a PIN (P6.1 only ever identifies who is ringing an individual sale
	 * mid-shift) — so "variance history per operator" reads as the close
	 * variances of shifts THIS operator's own linked account personally
	 * opened, not every shift they may have rung a sale on mid-way through
	 * someone else's.
	 */
	private static function variance_history_for_operator( int $operator_id, string $from, string $to ): array {
		$employee = Employees::get( $operator_id );
		if ( null === $employee ) {
			return [];
		}
		global $wpdb;
		$table  = Install::table( 'shifts' );
		$where  = 'WHERE user_id = %d AND closed_at IS NOT NULL';
		$params = [ (int) $employee['user_id'] ];
		if ( '' !== $from ) {
			$where   .= ' AND closed_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( '' !== $to ) {
			$where   .= ' AND closed_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, closed_at, variance FROM {$table} {$where} ORDER BY closed_at", ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where built from %-placeholders only
	}

	private static function name_for_operator( int $operator_id ): string {
		if ( 0 === $operator_id ) {
			return __( 'Unknown (no PIN switch on record)', 'counter' );
		}
		$employee = Employees::get( $operator_id );
		return $employee['code'] ?? ( '#' . $operator_id );
	}
}
