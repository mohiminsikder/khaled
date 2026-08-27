<?php
namespace Counter\Reports;

use Counter\Install;
use Counter\Pos\Accounts;

defined( 'ABSPATH' ) || exit;

/**
 * D4 — "the owner can see money move." No new table (Schema: none) — a
 * running ledger over the THREE places real money actually crosses a
 * cntr_payment_accounts row today: cntr_tenders (a sale's own payment, and
 * the change handed back out of it), cntr_expenses (status='confirmed'
 * only — a draft has not been looked at yet, same rule profit_and_loss()
 * already enforces), and cntr_supplier_ledger's own 'payment' rows
 * (SupplierLedger::record_payment() already refuses one with no account —
 * teardown defect #4/#9's own "1,168 payments linked to no account" is
 * therefore structurally impossible here, not merely tested for).
 *
 * Payroll is deliberately absent: People\Payroll has no "mark this run
 * actually paid, on this date, from this account" event yet — account_id
 * on cntr_payroll_runs designates an intended account, not a recorded
 * payment — so there is no real ledger row to read. Adding one would mean
 * fabricating a cash-flow event this plugin never actually recorded,
 * exactly what D4's own honesty requirement (teardown defect #15) exists
 * to refuse.
 */
class CashFlow {

	/**
	 * One row per money movement, oldest first, each carrying its own
	 * account_balance (that account's running total up to and including
	 * this row) and total_balance (every account's combined running total).
	 * Both are computed from EVERY row up to $args['to'] regardless of
	 * $args['from'] — a narrower window only trims which rows are
	 * RETURNED, never what the running balance is computed from, so
	 * changing the date range never breaks the carry-forward into it.
	 *
	 * @return array<int,array>
	 */
	public static function ledger( array $args = [] ): array {
		$from           = (string) ( $args['from'] ?? '' );
		$to             = (string) ( $args['to'] ?? '' );
		$account_filter = (int) ( $args['account_id'] ?? 0 );

		$rows = array_merge(
			self::tender_rows( $to ),
			self::expense_rows( $to ),
			self::supplier_payment_rows( $to )
		);

		usort(
			$rows,
			static function ( $a, $b ) {
				$cmp = strcmp( $a['created_at'], $b['created_at'] );
				return 0 !== $cmp ? $cmp : $a['_seq'] <=> $b['_seq'];
			}
		);

		$running_by_account = [];
		$running_total       = '0.0000';
		$from_boundary        = '' !== $from ? $from . ' 00:00:00' : null;
		$out                  = [];

		foreach ( $rows as $r ) {
			$acct = $r['account_id'];
			$running_by_account[ $acct ] = bcadd( bcsub( $running_by_account[ $acct ] ?? '0.0000', $r['debit'], 4 ), $r['credit'], 4 );
			$running_total                = bcadd( bcsub( $running_total, $r['debit'], 4 ), $r['credit'], 4 );

			if ( null !== $from_boundary && strcmp( $r['created_at'], $from_boundary ) < 0 ) {
				continue; // before the window — only its effect on the running totals matters
			}
			if ( $account_filter && $acct !== $account_filter ) {
				continue;
			}

			$row                    = $r;
			$row['account_balance'] = $running_by_account[ $acct ];
			$row['total_balance']   = $running_total;
			unset( $row['_seq'] );
			$out[] = $row;
		}

		return $out;
	}

	private static function tender_rows( string $to ): array {
		global $wpdb;
		$table  = Install::table( 'tenders' );
		$where  = 'WHERE account_id > 0';
		$params = [];
		if ( '' !== $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		$sql  = "SELECT id, order_id, method, amount, is_change, reference, account_id, user_id, created_at FROM {$table} {$where} ORDER BY created_at";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from constants and %-placeholders only

		$out = [];
		foreach ( $rows as $r ) {
			$amount    = wc_format_decimal( $r['amount'], 4 );
			$is_change = ! empty( $r['is_change'] );
			$order     = wc_get_order( (int) $r['order_id'] );
			$out[]     = [
				'created_at'   => $r['created_at'],
				'account_id'   => (int) $r['account_id'],
				'type'         => $is_change ? 'change' : 'sale',
				'counterparty' => $order ? ( '#' . $order->get_order_number() ) : ( '#' . $r['order_id'] ),
				'reference'    => (string) $r['reference'],
				'added_by'     => (int) $r['user_id'],
				'method'       => (string) $r['method'],
				'debit'        => $is_change ? $amount : '0.0000',
				'credit'       => $is_change ? '0.0000' : $amount,
				'_seq'         => 'tender-' . (int) $r['id'],
			];
		}
		return $out;
	}

	/** Confirmed only — a draft expense has not been looked at yet, the same rule Reports\Expenses::profit_and_loss() already enforces. */
	private static function expense_rows( string $to ): array {
		global $wpdb;
		$table  = Install::table( 'expenses' );
		$where  = "WHERE account_id > 0 AND status = 'confirmed'";
		$params = [];
		if ( '' !== $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		$sql  = "SELECT id, ref_no, category_id, account_id, amount, note, user_id, created_at FROM {$table} {$where} ORDER BY created_at";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from constants and %-placeholders only

		$out = [];
		foreach ( $rows as $r ) {
			$category    = $r['category_id'] ? \Counter\Reports\Expenses::category( (int) $r['category_id'] ) : null;
			$out[]       = [
				'created_at'   => $r['created_at'],
				'account_id'   => (int) $r['account_id'],
				'type'         => 'expense',
				'counterparty' => $category['name'] ?? __( 'Expense', 'counter' ),
				'reference'    => (string) $r['ref_no'],
				'added_by'     => (int) $r['user_id'],
				'method'       => '',
				'debit'        => wc_format_decimal( $r['amount'], 4 ),
				'credit'       => '0.0000',
				'_seq'         => 'expense-' . (int) $r['id'],
			];
		}
		return $out;
	}

	/** SupplierLedger::record_payment() writes ref_type='payment_account', ref_id=account_id, amount already negative (a debit against the supplier's own balance) — this reads the same rows for the account side. */
	private static function supplier_payment_rows( string $to ): array {
		global $wpdb;
		$table    = Install::table( 'supplier_ledger' );
		$where    = "WHERE type = 'payment' AND ref_type = 'payment_account'";
		$params   = [];
		if ( '' !== $to ) {
			$where   .= ' AND created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		$sql  = "SELECT id, supplier_id, amount, ref_id, note, user_id, created_at FROM {$table} {$where} ORDER BY created_at";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built from constants and %-placeholders only

		$out = [];
		foreach ( $rows as $r ) {
			$supplier = $r['supplier_id'] ? \Counter\Purchasing\Suppliers::get( (int) $r['supplier_id'] ) : null;
			$out[]    = [
				'created_at'   => $r['created_at'],
				'account_id'   => (int) $r['ref_id'],
				'type'         => 'supplier_payment',
				'counterparty' => $supplier ? $supplier['name'] : __( 'Supplier', 'counter' ),
				'reference'    => (string) ( $r['note'] ?: ( 'PAY-' . $r['id'] ) ),
				'added_by'     => (int) $r['user_id'],
				'method'       => '',
				// supplier_ledger's own amount is already negative for a
				// payment (a debit against what the shop owes) — abs() here
				// because THIS ledger's own debit/credit columns are
				// unsigned by convention, same as every other source above.
				'debit'        => wc_format_decimal( abs( (float) $r['amount'] ), 4 ),
				'credit'       => '0.0000',
				'_seq'         => 'supplier-' . (int) $r['id'],
			];
		}
		return $out;
	}

	/**
	 * Every distinct account referenced by anything ledger() would ever
	 * return, active or not — a payment made against a since-deactivated
	 * account must still be traceable, never silently excluded from "does
	 * every payment resolve to an account."
	 */
	public static function accounts_in_use(): array {
		global $wpdb;
		$ids = array_unique(
			array_merge(
				array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT account_id FROM ' . Install::table( 'tenders' ) . ' WHERE account_id > 0' ) ),
				array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT account_id FROM " . Install::table( 'expenses' ) . " WHERE account_id > 0 AND status = 'confirmed'" ) ),
				array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT ref_id FROM " . Install::table( 'supplier_ledger' ) . " WHERE type = 'payment' AND ref_type = 'payment_account'" ) )
			)
		);
		return array_values( array_filter( array_map( [ Accounts::class, 'get' ], $ids ) ) );
	}
}
