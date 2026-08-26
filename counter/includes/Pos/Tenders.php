<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Db;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce records one payment method per order; a Bangladeshi counter
 * routinely takes half cash and half bKash. cntr_tenders holds the real
 * breakdown; the order additionally carries a _cntr_tenders JSON meta for
 * portability and a plain-words order note.
 *
 * is_change marks money handed back — a negative cash movement, NEVER counted
 * as a payment. Validated server-side: sum(payments) - sum(change) must equal
 * the order total, or the difference is refused outright, UNLESS the current
 * user holds cntr_credit_sale, in which case the shortfall becomes a real
 * customer-ledger due (P2.6, Credit\CustomerLedger::record_credit_sale()).
 * The credit LIMIT itself is checked earlier, before the order exists at
 * all — see Orders\Builder's $before_save guard — never here.
 */
class Tenders {

	// 'change' is a real method value, not just a flag — matches P1.12's own
	// sale-endpoint example payload, which pairs method:'change' with
	// is_change:true rather than a negative 'cash' row.
	const VALID_METHODS = [ 'cash', 'card', 'bkash', 'nagad', 'rocket', 'bank', 'credit', 'change' ];

	/**
	 * @return true|\WP_Error
	 */
	public static function record( \WC_Order $order, array $tenders, int $shift_id, int $customer_id = 0 ) {
		if ( empty( $tenders ) ) {
			return new \WP_Error( 'cntr_tender_empty', __( 'At least one tender is required.', 'counter' ), [ 'status' => 422 ] );
		}

		foreach ( $tenders as $t ) {
			$method = (string) ( $t['method'] ?? '' );
			if ( ! in_array( $method, self::VALID_METHODS, true ) ) {
				return new \WP_Error(
					'cntr_tender_bad_method',
					sprintf( __( 'Unknown tender method: %s', 'counter' ), $method ),
					[ 'status' => 422 ]
				);
			}
		}

		// P4.6 — the blueprint's own sharpest operational finding: a live
		// system carrying 1,168 payments booked against no account. Every
		// method must resolve to a real, active account BEFORE anything is
		// written — refused whole, same "validate everything first" shape
		// as every other multi-row write in this codebase. 'credit' is
		// deliberately exempt: it is taka that has NOT arrived — a promise
		// tracked in cntr_customer_ledger, not money sitting in any real
		// drawer/bank/wallet — so it has nothing to book against an account
		// for in the first place.
		foreach ( $tenders as $t ) {
			$method = (string) ( $t['method'] ?? '' );
			if ( 'credit' === $method ) {
				continue;
			}
			if ( ! Accounts::is_active( self::account_for_method( $method ) ) ) {
				return new \WP_Error(
					'cntr_tender_no_account',
					sprintf( __( 'No active payment account is configured for "%s" — configure one before taking this tender.', 'counter' ), $method ),
					[ 'status' => 422, 'method' => $method ]
				);
			}
		}

		$order_total = wc_format_decimal( $order->get_total(), 4 );
		$net         = '0.0000';
		foreach ( $tenders as $t ) {
			$amount    = wc_format_decimal( $t['amount'] ?? 0, 4 );
			$is_change = ! empty( $t['is_change'] );
			$net       = $is_change ? bcsub( $net, $amount, 4 ) : bcadd( $net, $amount, 4 );
		}

		// Positive diff = shortfall (customer underpaid); negative = overpaid,
		// which is also refused — an overpayment recorded as-is would silently
		// hand the customer's own money to nobody's account.
		$diff = bcsub( $order_total, $net, 4 );

		if ( 0 !== bccomp( $diff, '0.0000', 4 ) ) {
			$is_shortfall = bccomp( $diff, '0', 4 ) > 0;
			// P2.6: credit requires an IDENTIFIED customer — $customer_id > 0
			// is not just a test-cleanliness nicety, it is the actual business
			// rule. A walk-in with no phone match is customer 0, and there is
			// no such thing as a বাকি nobody can ever be asked to pay back;
			// record_shortfall_as_due() would otherwise write a real,
			// permanent customer_ledger debit against an untrackable sentinel.
			if ( $is_shortfall && $customer_id > 0 && current_user_can( 'cntr_credit_sale' ) ) {
				self::record_shortfall_as_due( $order, $customer_id, $diff );
			} else {
				return new \WP_Error(
					'cntr_tender_mismatch',
					sprintf(
						/* translators: %s: signed decimal shortfall/overpayment */
						__( 'Payments do not match the order total (difference: %s).', 'counter' ),
						$diff
					),
					[ 'status' => 422 ]
				);
			}
		}

		global $wpdb;
		$table = Install::table( 'tenders' );
		foreach ( $tenders as $t ) {
			$method = (string) $t['method'];
			$wpdb->insert(
				$table,
				[
					'order_id'   => $order->get_id(),
					'refund_id'  => 0,
					'shift_id'   => $shift_id,
					'method'     => $method,
					'amount'     => wc_format_decimal( $t['amount'] ?? 0, 4 ),
					'is_change'  => empty( $t['is_change'] ) ? 0 : 1,
					'reference'  => (string) ( $t['reference'] ?? '' ),
					'account_id' => self::account_for_method( $method ),
					'user_id'    => get_current_user_id(),
					'created_at' => Db::now(),
				]
			);
		}

		$order->update_meta_data( '_cntr_tenders', wp_json_encode( $tenders ) );
		$order->add_order_note( self::tenders_note( $tenders ) );
		$order->save();

		return true;
	}

	/**
	 * The account a method defaults to — cash to the drawer, everything else
	 * to its matching wallet if one exists by that code, else unassigned (0).
	 * A real shop configures this per-method once it has real bank/MFS
	 * accounts (§10.1 question 8 is still unanswered); this is a sane default,
	 * not a hardcoded assumption that can't be corrected later.
	 */
	public static function account_for_method( string $method ): int {
		global $wpdb;
		$table = Install::table( 'payment_accounts' );
		// 'change' physically comes back out of the cash drawer, same as 'cash'.
		$code = in_array( $method, [ 'cash', 'change' ], true ) ? 'CASH' : strtoupper( $method );
		$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND status = 'active'", $code ) );
		return $id;
	}

	private static function tenders_note( array $tenders ): string {
		$parts = [];
		foreach ( $tenders as $t ) {
			if ( ! empty( $t['is_change'] ) ) {
				$parts[] = sprintf( __( 'Change: %s', 'counter' ), $t['amount'] ?? '0' );
			} else {
				$parts[] = sprintf( '%s: %s', ucfirst( (string) ( $t['method'] ?? '' ) ), $t['amount'] ?? '0' );
			}
		}
		return __( 'Counter tender breakdown — ', 'counter' ) . implode( ', ', $parts );
	}

	/**
	 * P2.6: writes the real cntr_customer_ledger debit for the shortfall. The
	 * credit LIMIT was already checked before this order ever existed
	 * (Orders\Builder's $before_save guard, wired in Rest\Sale::process()) —
	 * by the time this runs the sale is committed and the only job left is
	 * recording what is now owed.
	 */
	private static function record_shortfall_as_due( \WC_Order $order, int $customer_id, string $shortfall ): void {
		\Counter\Credit\CustomerLedger::record_credit_sale(
			$customer_id,
			$shortfall,
			'order',
			$order->get_id(),
			sprintf( __( 'Credit sale — order #%d', 'counter' ), $order->get_id() )
		);

		Audit::log(
			'credit_sale_shortfall',
			'order',
			$order->get_id(),
			null,
			[ 'customer_id' => $customer_id, 'shortfall' => $shortfall ],
			get_current_user_id()
		);
	}
}
