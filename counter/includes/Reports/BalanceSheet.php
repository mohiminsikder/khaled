<?php
namespace Counter\Reports;

use Counter\Install;
use Counter\Pos\Accounts;

defined( 'ABSPATH' ) || exit;

/**
 * D4 — teardown defect #15: SuperShop's own balance sheet showed ৳4.84M
 * liability against ৳606.89M assets and made no attempt to balance,
 * presented as if it were a real double-entry statement. This is
 * deliberately NOT one — no ledger anywhere in this plugin tracks equity,
 * fixed assets, or accrued liabilities, so a figure claiming to "balance"
 * would be fabricated. summary() returns four honest, independently-
 * sourced facts (supplier due, customer due, closing stock, account
 * balances) and IS_SUMMARY_ONLY is a permanent, structural part of its own
 * shape — Screens\BalanceSheet renders the disclaimer FROM this key, not
 * as a caption someone could forget to add.
 *
 * Defect #9's own guard lives here too: a P&L COGS figure that comes out
 * negative (closing stock exceeding opening plus purchases — impossible
 * under Counter's own bottom-up, frozen-unit_cost COGS, but a real
 * consequence of bad or missing data, e.g. an opening balance imported
 * without its own batches) is reported as an anomaly, never silently
 * printed as if it were a real number.
 */
class BalanceSheet {

	/**
	 * $args['location_id'] scopes only the COGS/closing-stock half —
	 * supplier due, customer due, and account balances are shop-wide
	 * concepts with no location dimension of their own, same as every
	 * other Reports method's own location_id filter only ever narrows
	 * what has one.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary( array $args = [] ): array {
		$to          = (string) ( $args['to'] ?? gmdate( 'Y-m-d' ) );
		$from        = (string) ( $args['from'] ?? '2000-01-01' );
		$location_id = (int) ( $args['location_id'] ?? 0 );

		$pnl_args = [ 'from' => $from, 'to' => $to ];
		if ( $location_id ) {
			$pnl_args['location_id'] = $location_id;
		}
		$pnl          = Expenses::profit_and_loss( $pnl_args );
		$cogs         = (string) ( $pnl['cogs'] ?? '0.0000' );
		$cogs_anomaly = bccomp( $cogs, '0', 4 ) < 0;

		return [
			'as_of'           => $to,
			'is_summary_only' => true,
			'supplier_due'    => self::supplier_due(),
			'customer_due'    => self::customer_due(),
			'closing_stock'   => self::closing_stock( $location_id ),
			'accounts'        => self::account_balances( $to ),
			'cogs'            => $cogs_anomaly ? null : $cogs,
			'cogs_anomaly'    => $cogs_anomaly,
		];
	}

	/** Sum of every supplier's own latest balance_after where positive (money the shop still owes). */
	private static function supplier_due(): string {
		global $wpdb;
		$table = Install::table( 'supplier_ledger' );
		$sql   = "SELECT COALESCE(SUM(t.balance_after),0) FROM {$table} t
		           INNER JOIN (SELECT supplier_id, MAX(id) AS max_id FROM {$table} GROUP BY supplier_id) latest
		             ON latest.supplier_id = t.supplier_id AND latest.max_id = t.id
		           WHERE t.balance_after > 0";
		return wc_format_decimal( (string) $wpdb->get_var( $sql ), 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	/** Same shape as supplier_due(), the customer_ledger side (money customers still owe the shop). */
	private static function customer_due(): string {
		global $wpdb;
		$table = Install::table( 'customer_ledger' );
		$sql   = "SELECT COALESCE(SUM(t.balance_after),0) FROM {$table} t
		           INNER JOIN (SELECT customer_id, MAX(id) AS max_id FROM {$table} GROUP BY customer_id) latest
		             ON latest.customer_id = t.customer_id AND latest.max_id = t.id
		           WHERE t.balance_after > 0";
		return wc_format_decimal( (string) $wpdb->get_var( $sql ), 4 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	/** FIFO cost of every batch still holding qty right now — Reports::valuation() itself, summed. */
	private static function closing_stock( int $location_id = 0 ): string {
		$total = '0.0000';
		$args  = $location_id ? [ 'location_id' => $location_id ] : [];
		foreach ( Reports::valuation( $args ) as $row ) {
			if ( isset( $row['value'] ) ) {
				$total = bcadd( $total, $row['value'], 4 );
			}
		}
		return $total;
	}

	/**
	 * Every active account, plus any account CashFlow::ledger() ever
	 * touched even if since deactivated — the same "traceable, never
	 * silently excluded" rule CashFlow::accounts_in_use() itself documents.
	 * Balance is that account's own running total as of $as_of (the last
	 * ledger row at or before it), zero for an account nothing has ever
	 * moved through.
	 */
	private static function account_balances( string $as_of ): array {
		$known_ids = array_unique(
			array_merge(
				array_map( static fn( $a ) => (int) $a['id'], Accounts::all( '' ) ),
				array_map( static fn( $a ) => (int) $a['id'], CashFlow::accounts_in_use() )
			)
		);

		$rows        = CashFlow::ledger( [ 'to' => $as_of ] );
		$last_seen   = [];
		foreach ( $rows as $r ) {
			$last_seen[ $r['account_id'] ] = $r['account_balance'];
		}

		$out = [];
		foreach ( $known_ids as $id ) {
			$account = Accounts::get( $id );
			$out[]   = [
				'account_id'   => $id,
				'account_name' => $account['name'] ?? ( '#' . $id ),
				'balance'      => $last_seen[ $id ] ?? '0.0000',
			];
		}
		return $out;
	}
}
