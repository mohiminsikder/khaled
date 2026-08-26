<?php
namespace Counter\Credit;

use Counter\Install;
use Counter\Db;
use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * P2.6 — Audit finding G1. WooCommerce has no partly-paid order, so credit
 * (বাকি) lives entirely in this table: a credit sale writes a debit, each
 * payment writes a credit, a refund against a credit sale writes another
 * credit, and the open balance is the difference. Same INSERT-only,
 * balance_after-chained discipline as Purchasing\SupplierLedger — the two
 * are kept as separate classes over separate tables (customer vs supplier,
 * receivable vs payable) rather than merged behind one generic ledger, same
 * as Stock\Ledger and Stock\Batches stay separate despite a similar shape.
 *
 * The credit LIMIT is checked at TENDER, before the sale exists at all —
 * see Orders\Builder's $before_save guard and CreditLimitExceeded — never
 * as a post-hoc rollback. A limit lives as WordPress user meta
 * (_cntr_credit_limit): WooCommerce is the system of record for customers
 * (non-negotiable 1), and a limit is a property of the customer, not a new
 * table Counter would have to keep in sync with one.
 */
class CustomerLedger {

	const AGING_BUCKETS = [ 'current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus' ];

	public static function credit_limit( int $customer_id ): string {
		$v = get_user_meta( $customer_id, '_cntr_credit_limit', true );
		return '' === $v || false === $v ? '0.0000' : wc_format_decimal( $v, 4 );
	}

	public static function set_credit_limit( int $customer_id, string $limit ): void {
		update_user_meta( $customer_id, '_cntr_credit_limit', wc_format_decimal( $limit, 4 ) );
	}

	/**
	 * Would adding $would_be_debit to this customer's current balance exceed
	 * their limit? Pure decision function — writes nothing, called from
	 * Orders\Builder's guard, which guarantees no order is left behind even
	 * if a veto happens after WooCommerce has already assigned one an id —
	 * see that class's own docblock.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_limit( int $customer_id, string $would_be_debit ): bool|\WP_Error {
		$limit       = self::credit_limit( $customer_id );
		$current     = self::balance( $customer_id );
		$new_balance = bcadd( $current, $would_be_debit, 4 );

		if ( bccomp( $new_balance, $limit, 4 ) > 0 ) {
			return new \WP_Error(
				'cntr_credit_limit_exceeded',
				sprintf(
					/* translators: 1: credit limit, 2: what the balance would become */
					__( 'This sale would exceed the credit limit (limit ৳%1$s, would owe ৳%2$s).', 'counter' ),
					$limit,
					$new_balance
				),
				[ 'status' => 422, 'limit' => $limit, 'would_owe' => $new_balance ]
			);
		}
		return true;
	}

	/** A credit sale — the unpaid portion of an order, written as a debit. */
	public static function record_credit_sale( int $customer_id, string $amount, string $ref_type, int $ref_id, string $note = '', int $user_id = 0 ): int {
		$amount   = wc_format_decimal( $amount, 4 );
		$days     = (int) Settings::get( 'credit.default_due_days', 30 );
		$due_date = gmdate( 'Y-m-d', strtotime( Db::now() ) + ( $days * DAY_IN_SECONDS ) );
		return self::write_row( $customer_id, 'credit_sale', $amount, $ref_type, $ref_id, $note, $due_date, $user_id );
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function record_payment( int $customer_id, string $amount, string $note = '', int $account_id = 0, int $ref_id = 0, int $user_id = 0 ) {
		$amount = wc_format_decimal( $amount, 4 );
		if ( bccomp( $amount, '0', 4 ) <= 0 ) {
			return new \WP_Error( 'cntr_customer_payment_invalid_amount', __( 'Payment amount must be greater than zero.', 'counter' ), [ 'status' => 400 ] );
		}
		$ref_type = $account_id ? 'payment_account' : 'payment';
		$ref      = $account_id ?: $ref_id;
		return self::write_row( $customer_id, 'payment', bcmul( $amount, '-1', 4 ), $ref_type, $ref, $note, null, $user_id );
	}

	/** A refund against a credit sale — reduces what the customer owes, same sign as a payment. */
	public static function record_refund_credit( int $customer_id, string $amount, string $ref_type, int $ref_id, string $note = '', int $user_id = 0 ): int {
		$amount = wc_format_decimal( $amount, 4 );
		return self::write_row( $customer_id, 'refund', bcmul( $amount, '-1', 4 ), $ref_type, $ref_id, $note, null, $user_id );
	}

	public static function balance( int $customer_id ): string {
		global $wpdb;
		$table = Install::table( 'customer_ledger' );
		$bal   = $wpdb->get_var( $wpdb->prepare( "SELECT balance_after FROM {$table} WHERE customer_id = %d ORDER BY id DESC LIMIT 1", $customer_id ) );
		return null === $bal ? '0.0000' : (string) $bal;
	}

	public static function ledger( int $customer_id ): array {
		global $wpdb;
		$table = Install::table( 'customer_ledger' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE customer_id = %d ORDER BY id", $customer_id ), ARRAY_A );
	}

	/**
	 * FIFO-matches every payment/credit against the oldest still-open debit
	 * first, then buckets whatever remains by days past due. Same
	 * conservation guarantee as SupplierLedger::aging() — see that class's
	 * docblock for the full reasoning; the two are independent
	 * implementations over independent tables, not a shared helper, same as
	 * the rest of this pair of classes.
	 */
	public static function aging( ?int $customer_id = null, ?string $as_of = null ): array {
		global $wpdb;
		$table    = Install::table( 'customer_ledger' );
		$as_of_ts = $as_of ? strtotime( $as_of ) : strtotime( Db::now() );

		if ( $customer_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE customer_id = %d ORDER BY customer_id, id", $customer_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY customer_id, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
		}

		$open_debits = [];
		foreach ( $rows as $row ) {
			$cid = (int) $row['customer_id'];
			$amt = $row['amount'];
			if ( ! isset( $open_debits[ $cid ] ) ) {
				$open_debits[ $cid ] = [];
			}

			if ( bccomp( $amt, '0', 4 ) > 0 ) {
				$open_debits[ $cid ][] = [
					'remaining' => $amt,
					'due_date'  => $row['due_date'] ?: substr( $row['created_at'], 0, 10 ),
				];
				continue;
			}

			$to_consume = bcmul( $amt, '-1', 4 );
			foreach ( $open_debits[ $cid ] as &$debit ) {
				if ( bccomp( $to_consume, '0', 4 ) <= 0 ) {
					break;
				}
				if ( bccomp( $debit['remaining'], '0', 4 ) <= 0 ) {
					continue;
				}
				$take               = bccomp( $debit['remaining'], $to_consume, 4 ) <= 0 ? $debit['remaining'] : $to_consume;
				$debit['remaining'] = bcsub( $debit['remaining'], $take, 4 );
				$to_consume          = bcsub( $to_consume, $take, 4 );
			}
			unset( $debit );

			if ( bccomp( $to_consume, '0', 4 ) > 0 ) {
				$open_debits[ $cid ][] = [
					'remaining' => bcmul( $to_consume, '-1', 4 ),
					'due_date'  => substr( $row['created_at'], 0, 10 ),
				];
			}
		}

		$out = [];
		foreach ( $open_debits as $cid => $debits ) {
			$buckets = array_fill_keys( self::AGING_BUCKETS, '0.0000' );
			foreach ( $debits as $d ) {
				if ( 0 === bccomp( $d['remaining'], '0', 4 ) ) {
					continue;
				}
				$days_overdue       = (int) floor( ( $as_of_ts - strtotime( $d['due_date'] ) ) / DAY_IN_SECONDS );
				$bucket             = self::bucket_for( $days_overdue );
				$buckets[ $bucket ] = bcadd( $buckets[ $bucket ], $d['remaining'], 4 );
			}
			$total = '0.0000';
			foreach ( $buckets as $b ) {
				$total = bcadd( $total, $b, 4 );
			}
			if ( 0 === bccomp( $total, '0', 4 ) ) {
				continue; // a zero-balance customer does not appear in aging
			}
			$out[ $cid ] = $buckets + [ 'total' => $total ];
		}
		return $out;
	}

	/**
	 * Every document for one customer, oldest first, with the running
	 * balance — the print-ready statement the Direction asks for. Unlike
	 * aging() (open amounts re-attributed into buckets), a statement shows
	 * the real chronological history, because that is what settles an
	 * argument at the counter: balance_after IS the running balance, already
	 * stored on every row, never recomputed here.
	 */
	public static function statement( int $customer_id ): array {
		$rows = self::ledger( $customer_id );
		$user = get_userdata( $customer_id );
		return [
			'customer_id'   => $customer_id,
			'customer_name' => $user ? trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name : '',
			'rows'          => $rows,
			'open_balance'  => self::balance( $customer_id ),
			'generated_at'  => Db::now(),
		];
	}

	private static function bucket_for( int $days_overdue ): string {
		if ( $days_overdue <= 0 ) {
			return 'current';
		}
		if ( $days_overdue <= 30 ) {
			return 'd1_30';
		}
		if ( $days_overdue <= 60 ) {
			return 'd31_60';
		}
		if ( $days_overdue <= 90 ) {
			return 'd61_90';
		}
		return 'd90_plus';
	}

	/** INSERT-only, same discipline as Suppliers::write_ledger_row() — no UPDATE, no DELETE, ever. */
	private static function write_row( int $customer_id, string $type, string $amount, string $ref_type, int $ref_id, string $note, ?string $due_date, int $user_id ): int {
		global $wpdb;
		$table = Install::table( 'customer_ledger' );

		$prior = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT balance_after FROM {$table} WHERE customer_id = %d ORDER BY id DESC LIMIT 1", $customer_id )
		);
		$prior         = '' === $prior ? '0.0000' : $prior;
		$balance_after = bcadd( $prior, $amount, 4 );

		$wpdb->insert(
			$table,
			[
				'customer_id'   => $customer_id,
				'type'          => $type,
				'amount'        => $amount,
				'balance_after' => $balance_after,
				'ref_type'      => $ref_type,
				'ref_id'        => $ref_id,
				'due_date'      => $due_date,
				'note'          => $note,
				'user_id'       => $user_id ?: get_current_user_id(),
				'created_at'    => Db::now(),
			]
		);
		return (int) $wpdb->insert_id;
	}
}
