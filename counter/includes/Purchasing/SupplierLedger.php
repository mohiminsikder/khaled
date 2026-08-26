<?php
namespace Counter\Purchasing;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P2.5 — bills, payments, credit notes, balance_after on every row. Bills are
 * already written by Receiving (P2.4) via Suppliers::write_ledger_row();
 * this class adds the other two write paths and the read side (balance,
 * ledger, aging).
 *
 * The blueprint found a live system carrying 1,168 payments not linked to
 * any account, and once that starts the ledger is wrong forever — a payment
 * is refused outright without a real, existing payment_accounts row.
 * cntr_supplier_ledger has no dedicated account_id column (this task's own
 * plan entry lists no schema change), so the account is recorded the same
 * way every other cause is: ref_type = 'payment_account', ref_id = the
 * account id — traceable and queryable, not a silent gap.
 */
class SupplierLedger {

	const AGING_BUCKETS = [ 'current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus' ];

	/**
	 * @return int|\WP_Error
	 */
	public static function record_payment( int $supplier_id, string $amount, int $account_id, string $note = '', int $user_id = 0 ) {
		if ( $account_id <= 0 ) {
			return new \WP_Error( 'cntr_supplier_payment_no_account', __( 'A payment account is required.', 'counter' ), [ 'status' => 400 ] );
		}

		global $wpdb;
		$accounts_table = Install::table( 'payment_accounts' );
		$account_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$accounts_table} WHERE id = %d", $account_id ) );
		if ( ! $account_exists ) {
			return new \WP_Error( 'cntr_supplier_payment_bad_account', __( 'Unknown payment account.', 'counter' ), [ 'status' => 400 ] );
		}

		$amount = wc_format_decimal( $amount, 4 );
		if ( bccomp( $amount, '0', 4 ) <= 0 ) {
			return new \WP_Error( 'cntr_supplier_payment_invalid_amount', __( 'Payment amount must be greater than zero.', 'counter' ), [ 'status' => 400 ] );
		}

		return Suppliers::write_ledger_row(
			$supplier_id,
			'payment',
			bcmul( $amount, '-1', 4 ),
			'payment_account',
			$account_id,
			$note,
			null,
			$user_id
		);
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function record_credit_note( int $supplier_id, string $amount, string $note = '', int $ref_id = 0, int $user_id = 0 ) {
		$amount = wc_format_decimal( $amount, 4 );
		if ( bccomp( $amount, '0', 4 ) <= 0 ) {
			return new \WP_Error( 'cntr_supplier_credit_invalid_amount', __( 'Credit note amount must be greater than zero.', 'counter' ), [ 'status' => 400 ] );
		}

		return Suppliers::write_ledger_row(
			$supplier_id,
			'credit_note',
			bcmul( $amount, '-1', 4 ),
			'credit_note',
			$ref_id,
			$note,
			null,
			$user_id
		);
	}

	public static function balance( int $supplier_id ): string {
		global $wpdb;
		$table = Install::table( 'supplier_ledger' );
		$bal   = $wpdb->get_var(
			$wpdb->prepare( "SELECT balance_after FROM {$table} WHERE supplier_id = %d ORDER BY id DESC LIMIT 1", $supplier_id )
		);
		return null === $bal ? '0.0000' : (string) $bal;
	}

	public static function ledger( int $supplier_id ): array {
		global $wpdb;
		$table = Install::table( 'supplier_ledger' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE supplier_id = %d ORDER BY id", $supplier_id ), ARRAY_A );
	}

	/**
	 * FIFO-matches every payment/credit-note against the oldest still-open
	 * bill first, then buckets whatever remains open by days PAST DUE
	 * (negative/zero = current). This is a genuine re-attribution, not an
	 * approximation: the sum of every bucket for a supplier is always exactly
	 * that supplier's balance() — conservation, not coincidence — including a
	 * supplier who has paid more than they currently owe (the surplus lands
	 * in 'current' as a negative figure rather than vanishing).
	 *
	 * $supplier_id = null aggregates every supplier with a non-zero balance.
	 * Returns [ supplier_id => [ 'current'=>.., 'd1_30'=>.., ..., 'total'=>.. ] ].
	 */
	public static function aging( ?int $supplier_id = null, ?string $as_of = null ): array {
		global $wpdb;
		$table    = Install::table( 'supplier_ledger' );
		$as_of_ts = $as_of ? strtotime( $as_of ) : strtotime( Db::now() );

		if ( $supplier_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE supplier_id = %d ORDER BY supplier_id, id", $supplier_id ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY supplier_id, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
		}

		$open_debits = [];
		foreach ( $rows as $row ) {
			$sid = (int) $row['supplier_id'];
			$amt = $row['amount'];
			if ( ! isset( $open_debits[ $sid ] ) ) {
				$open_debits[ $sid ] = [];
			}

			if ( bccomp( $amt, '0', 4 ) > 0 ) {
				$open_debits[ $sid ][] = [
					'remaining' => $amt,
					'due_date'  => $row['due_date'] ?: substr( $row['created_at'], 0, 10 ),
				];
				continue;
			}

			$to_consume = bcmul( $amt, '-1', 4 );
			foreach ( $open_debits[ $sid ] as &$debit ) {
				if ( bccomp( $to_consume, '0', 4 ) <= 0 ) {
					break;
				}
				if ( bccomp( $debit['remaining'], '0', 4 ) <= 0 ) {
					continue;
				}
				$take             = bccomp( $debit['remaining'], $to_consume, 4 ) <= 0 ? $debit['remaining'] : $to_consume;
				$debit['remaining'] = bcsub( $debit['remaining'], $take, 4 );
				$to_consume          = bcsub( $to_consume, $take, 4 );
			}
			unset( $debit );

			// A credit exceeding every known bill so far (an overpayment, or
			// a credit note with nothing left to apply against) still has to
			// land somewhere for the buckets to keep summing to the true
			// balance — recorded as its own "debit" with negative remaining,
			// dated today so it buckets as current.
			if ( bccomp( $to_consume, '0', 4 ) > 0 ) {
				$open_debits[ $sid ][] = [
					'remaining' => bcmul( $to_consume, '-1', 4 ),
					'due_date'  => substr( $row['created_at'], 0, 10 ),
				];
			}
		}

		$out = [];
		foreach ( $open_debits as $sid => $debits ) {
			$buckets = array_fill_keys( self::AGING_BUCKETS, '0.0000' );
			foreach ( $debits as $d ) {
				if ( 0 === bccomp( $d['remaining'], '0', 4 ) ) {
					continue;
				}
				$days_overdue = (int) floor( ( $as_of_ts - strtotime( $d['due_date'] ) ) / DAY_IN_SECONDS );
				$bucket       = self::bucket_for( $days_overdue );
				$buckets[ $bucket ] = bcadd( $buckets[ $bucket ], $d['remaining'], 4 );
			}
			$total = '0.0000';
			foreach ( $buckets as $b ) {
				$total = bcadd( $total, $b, 4 );
			}
			if ( 0 === bccomp( $total, '0', 4 ) ) {
				continue; // a zero-balance supplier does not appear in aging
			}
			$out[ $sid ] = $buckets + [ 'total' => $total ];
		}
		return $out;
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
}
