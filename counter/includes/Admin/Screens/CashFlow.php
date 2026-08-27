<?php
namespace Counter\Admin\Screens;

use Counter\Reports\CashFlow as CashFlowModel;
use Counter\Reports\BalanceSheet as BalanceSheetModel;
use Counter\Pos\Accounts;

defined( 'ABSPATH' ) || exit;

/**
 * D4 — "the owner can see money move." Two views over the two new Reports
 * classes: a running ledger (CashFlow::ledger()) and a balance sheet
 * (BalanceSheet::summary()) — one screen, not two, since a shop owner
 * reads them together and the balance sheet's own account balances are
 * literally the ledger's last row per account.
 *
 * Gated on cntr_view_cost, not the broader cntr_view_reports — the same
 * capability Reports::valuation()/margin_by_product() already require for
 * this exact class of figure (real money positions, not a sales count).
 */
class CashFlow {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Cash Flow', 'counter' ),
			__( 'Cash Flow', 'counter' ),
			'cntr_view_cost',
			'counter-cash-flow',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_cost' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'ledger'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Cash Flow', 'counter' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( [ 'ledger' => __( 'Ledger', 'counter' ), 'balance_sheet' => __( 'Balance Sheet', 'counter' ) ] as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( [ 'page' => 'counter-cash-flow', 'view' => $key ], admin_url( 'admin.php' ) ) ),
				$key === $view ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( 'balance_sheet' === $view ) {
			self::render_balance_sheet();
		} else {
			self::render_ledger();
		}

		echo '</div>';
	}

	private static function render_ledger(): void {
		$args = [
			'from'       => isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-01' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
			'to'         => isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'account_id' => isset( $_GET['account_id'] ) ? absint( $_GET['account_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		];

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="counter-cash-flow"><input type="hidden" name="view" value="ledger">';
		printf( '<input type="date" name="from" value="%s"> ', esc_attr( $args['from'] ) );
		printf( '<input type="date" name="to" value="%s"> ', esc_attr( $args['to'] ) );
		echo '<select name="account_id">';
		printf( '<option value="0">%s</option>', esc_html__( 'All accounts', 'counter' ) );
		foreach ( Accounts::all( '' ) as $a ) {
			printf( '<option value="%d"%s>%s</option>', (int) $a['id'], selected( $args['account_id'], (int) $a['id'], false ), esc_html( $a['name'] ) );
		}
		echo '</select>';
		echo ' <button type="submit" class="button button-primary">' . esc_html__( 'Filter', 'counter' ) . '</button>';
		echo '</form>';

		$rows = CashFlowModel::ledger( $args );

		$headers = [
			__( 'Date', 'counter' ),
			__( 'Account', 'counter' ),
			__( 'Type', 'counter' ),
			__( 'Counterparty', 'counter' ),
			__( 'Reference', 'counter' ),
			__( 'Added by', 'counter' ),
			__( 'Method', 'counter' ),
			__( 'Debit', 'counter' ),
			__( 'Credit', 'counter' ),
			__( 'Account balance', 'counter' ),
			__( 'Total balance', 'counter' ),
		];
		echo '<table class="widefat striped" style="margin-top:16px;"><thead><tr>';
		foreach ( $headers as $h ) {
			printf( '<th>%s</th>', esc_html( $h ) );
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="11">' . esc_html__( 'No money movement in this range.', 'counter' ) . '</td></tr>';
		}

		foreach ( $rows as $r ) {
			$account = Accounts::get( $r['account_id'] );
			$user    = $r['added_by'] ? get_userdata( $r['added_by'] ) : false;
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $r['created_at'] ) );
			printf( '<td>%s</td>', esc_html( $account['name'] ?? ( '#' . $r['account_id'] ) ) );
			printf( '<td>%s</td>', esc_html( str_replace( '_', ' ', $r['type'] ) ) );
			printf( '<td>%s</td>', esc_html( $r['counterparty'] ) );
			printf( '<td>%s</td>', esc_html( $r['reference'] ) );
			printf( '<td>%s</td>', esc_html( $user ? $user->display_name : __( 'System', 'counter' ) ) );
			printf( '<td>%s</td>', esc_html( $r['method'] ) );
			printf( '<td>%s</td>', 0 === bccomp( $r['debit'], '0', 4 ) ? '' : wp_kses_post( wc_price( $r['debit'] ) ) );
			printf( '<td>%s</td>', 0 === bccomp( $r['credit'], '0', 4 ) ? '' : wp_kses_post( wc_price( $r['credit'] ) ) );
			printf( '<td>%s</td>', wp_kses_post( wc_price( $r['account_balance'] ) ) );
			printf( '<td>%s</td>', wp_kses_post( wc_price( $r['total_balance'] ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private static function render_balance_sheet(): void {
		$to      = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : gmdate( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no state change
		$summary = BalanceSheetModel::summary( [ 'to' => $to ] );

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="counter-cash-flow"><input type="hidden" name="view" value="balance_sheet">';
		printf( '<label>%s <input type="date" name="to" value="%s"></label> ', esc_html__( 'As of', 'counter' ), esc_attr( $summary['as_of'] ) );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Update', 'counter' ) . '</button>';
		echo '</form>';

		echo '<div class="notice notice-warning" style="margin-top:16px;"><p>' . esc_html__( 'This is a summary of independently-sourced figures, not a double-entry balance sheet — it does not track equity or fixed assets, and it makes no claim that assets equal liabilities.', 'counter' ) . '</p></div>';

		echo '<table class="widefat" style="max-width:640px;margin-top:16px;"><tbody>';
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Supplier due (money owed to suppliers)', 'counter' ), wp_kses_post( wc_price( $summary['supplier_due'] ) ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Customer due (money owed by customers)', 'counter' ), wp_kses_post( wc_price( $summary['customer_due'] ) ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Closing stock (FIFO cost)', 'counter' ), wp_kses_post( wc_price( $summary['closing_stock'] ) ) );
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'COGS (period to date)', 'counter' ),
			$summary['cogs_anomaly']
				? '<strong style="color:#b32d2e;">' . esc_html__( 'Anomaly — reported as negative, not shown as a figure. Check for a stock or opening-balance data issue.', 'counter' ) . '</strong>'
				: wp_kses_post( wc_price( $summary['cogs'] ) )
		);
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Account balances', 'counter' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:640px;"><thead><tr><th>' . esc_html__( 'Account', 'counter' ) . '</th><th>' . esc_html__( 'Balance', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $summary['accounts'] as $a ) {
			printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $a['account_name'] ), wp_kses_post( wc_price( $a['balance'] ) ) );
		}
		echo '</tbody></table>';
	}
}
