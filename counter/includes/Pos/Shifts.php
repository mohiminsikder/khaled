<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Db;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Audit finding G6. open_key = register_id while the shift is open, NULL once
 * closed, with a UNIQUE index on it (see §5.3's cntr_shifts DDL). MySQL permits
 * many NULLs in a unique index, so closed shifts never collide with each other
 * or with a fresh open — "one open shift per register" is a database fact, not
 * a PHP check that races.
 */
class Shifts {

	/**
	 * A duplicate-key error here is the expected, correct outcome, not a bug —
	 * it IS the enforcement mechanism.
	 *
	 * @return int|\WP_Error New shift id, or a 409 WP_Error if the register
	 *                       already has one open.
	 */
	public static function open( int $register_id, int $user_id, string $opening_float = '0.0000' ) {
		global $wpdb;
		$table = Install::table( 'shifts' );

		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (register_id, open_key, user_id, opened_at, opening_float)
				 VALUES (%d, %d, %d, %s, %s)",
				$register_id,
				$register_id,
				$user_id,
				Db::now(),
				wc_format_decimal( $opening_float, 4 )
			)
		);

		if ( false === $ok ) {
			return new \WP_Error(
				'cntr_shift_open',
				__( 'This register already has an open shift.', 'counter' ),
				[ 'status' => 409 ]
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Denomination values Counter recognises when validating a close's
	 * denoms_json — Bangladeshi taka notes and coins in circulation. Keys are
	 * strings because JSON object keys always decode that way in PHP.
	 */
	const DENOMINATIONS = [ '1000', '500', '200', '100', '50', '20', '10', '5', '2', '1' ];

	/**
	 * expected_cash (audit finding B4/P1.16) is computed fresh at close time,
	 * never maintained incrementally — the arithmetic:
	 *
	 *   opening_float
	 *   + SUM(tenders.amount WHERE method='cash' AND is_change=0 AND refund_id=0)
	 *   − SUM(tenders.amount WHERE is_change=1 AND refund_id=0)
	 *   + SUM(shift_events WHERE type='cash_in')
	 *   − SUM(shift_events WHERE type='cash_out')
	 *   − SUM(shift_events WHERE type='drop')
	 *   − SUM(shift_events WHERE type='refund')
	 *
	 * refund_id=0 on the tender terms is deliberate: a refund's own payout
	 * ALSO writes a cntr_tenders row (Orders\Refunds::record_refund_tenders(),
	 * P1.15) so the per-method breakdown can show how refunds were paid out —
	 * but that cash left the drawer, and shift_events.refund already accounts
	 * for it. Summing both would cancel to zero instead of subtracting.
	 * Non-cash tenders never enter this arithmetic at all — 'cash' is
	 * explicit on the method filter, and is_change rows aren't filtered by
	 * method because P1.12's own sale payload pairs method:'change' with
	 * is_change:true rather than a negative 'cash' row.
	 */
	public static function compute_expected_cash( int $shift_id, string $opening_float ): string {
		global $wpdb;
		$tenders_table = Install::table( 'tenders' );
		$events_table  = Install::table( 'shift_events' );

		$cash_in = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$tenders_table} WHERE shift_id = %d AND method = 'cash' AND is_change = 0 AND refund_id = 0",
				$shift_id
			)
		);
		$change_out = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$tenders_table} WHERE shift_id = %d AND is_change = 1 AND refund_id = 0",
				$shift_id
			)
		);

		$by_type = [ 'cash_in' => '0', 'cash_out' => '0', 'drop' => '0', 'refund' => '0' ];
		$events  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, COALESCE(SUM(amount),0) AS total FROM {$events_table} WHERE shift_id = %d AND type IN ('cash_in','cash_out','drop','refund') GROUP BY type",
				$shift_id
			),
			ARRAY_A
		);
		foreach ( $events as $e ) {
			$by_type[ $e['type'] ] = (string) $e['total'];
		}

		$expected = $opening_float;
		$expected = bcadd( $expected, $cash_in, 4 );
		$expected = bcsub( $expected, $change_out, 4 );
		$expected = bcadd( $expected, $by_type['cash_in'], 4 );
		$expected = bcsub( $expected, $by_type['cash_out'], 4 );
		$expected = bcsub( $expected, $by_type['drop'], 4 );
		$expected = bcsub( $expected, $by_type['refund'], 4 );

		return wc_format_decimal( $expected, 4 );
	}

	/**
	 * cash_in / cash_out / drop / no_sale — manual drawer movements. No REST
	 * route calls this yet (no cash-management screen exists this task), but
	 * compute_expected_cash()'s arithmetic already depends on these types
	 * existing, so the single writer belongs here rather than being
	 * reinvented ad hoc by whichever future caller needs one.
	 */
	public static function record_event( int $shift_id, string $type, string $amount, string $note = '', int $user_id = 0 ): int {
		global $wpdb;
		$table = Install::table( 'shift_events' );
		$wpdb->insert(
			$table,
			[
				'shift_id'   => $shift_id,
				'type'       => $type,
				'amount'     => wc_format_decimal( $amount, 4 ),
				'method'     => 'cash',
				'ref_type'   => '',
				'ref_id'     => 0,
				'note'       => $note,
				'user_id'    => $user_id ?: get_current_user_id(),
				'created_at' => Db::now(),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * A sale still in flight for this shift — queued, processing, or
	 * failed_retry in cntr_sale_queue — is money the drawer count cannot
	 * possibly reconcile against yet. This is the SAME table P7's offline
	 * outbox will use (it is already described as "the offline inbox" in
	 * §5.3's schema comment); today every sale resolves synchronously within
	 * one request (P1.12), so this guard is normally vacuous, but it becomes
	 * the real offline-outbox check the moment P7 starts leaving rows queued
	 * across requests, with no code change required here.
	 */
	public static function has_pending_queue( int $shift_id ): bool {
		global $wpdb;
		$table = Install::table( 'sale_queue' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE shift_id = %d AND status NOT IN ('done','failed_permanent')",
				$shift_id
			)
		);
		return $count > 0;
	}

	/**
	 * Null return means "no denominations supplied" (skip validation) —
	 * distinct from 0.0000, which is a real declared total from an empty
	 * drawer. An unrecognised key or a non-numeric count is rejected outright
	 * rather than silently ignored, since a typo'd denomination would
	 * otherwise quietly undercount the drawer.
	 */
	private static function sum_denominations( ?string $denoms_json ): ?string {
		if ( null === $denoms_json || '' === $denoms_json ) {
			return null;
		}
		$decoded = json_decode( $denoms_json, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return null;
		}

		$sum = '0.0000';
		foreach ( $decoded as $denom => $count ) {
			if ( ! in_array( (string) $denom, self::DENOMINATIONS, true ) || ! is_numeric( $count ) || (int) $count < 0 ) {
				return null;
			}
			$sum = bcadd( $sum, bcmul( (string) $denom, (string) (int) $count, 4 ), 4 );
		}
		return $sum;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function close( int $shift_id, string $counted_cash = '0.0000', ?string $denoms_json = null, ?string $note = null ) {
		global $wpdb;
		$table = Install::table( 'shifts' );

		$shift = self::get( $shift_id );
		if ( ! $shift ) {
			return new \WP_Error( 'cntr_shift_missing', __( 'Shift not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( null === $shift['open_key'] ) {
			return new \WP_Error( 'cntr_shift_closed', __( 'This shift is already closed.', 'counter' ), [ 'status' => 409 ] );
		}

		if ( self::has_pending_queue( $shift_id ) ) {
			return new \WP_Error(
				'cntr_shift_unsynced',
				__( 'This register has sales still syncing. Wait for them to finish before closing.', 'counter' ),
				[ 'status' => 409 ]
			);
		}

		$counted_cash = wc_format_decimal( $counted_cash, 4 );

		if ( null !== $denoms_json && '' !== $denoms_json ) {
			$denom_sum = self::sum_denominations( $denoms_json );
			if ( null === $denom_sum || 0 !== bccomp( $denom_sum, $counted_cash, 4 ) ) {
				return new \WP_Error(
					'cntr_shift_denom_mismatch',
					sprintf(
						/* translators: %1$s: denomination total, %2$s: counted cash */
						__( 'The denominations counted (%1$s) do not sum to the counted cash entered (%2$s).', 'counter' ),
						null === $denom_sum ? __( 'invalid', 'counter' ) : $denom_sum,
						$counted_cash
					),
					[ 'status' => 422 ]
				);
			}
		}

		$expected_cash = self::compute_expected_cash( $shift_id, (string) $shift['opening_float'] );
		$variance      = wc_format_decimal( bcsub( $counted_cash, $expected_cash, 4 ), 4 );

		$wpdb->update(
			$table,
			[
				'open_key'      => null,
				'closed_at'     => Db::now(),
				'expected_cash' => $expected_cash,
				'counted_cash'  => $counted_cash,
				'variance'      => $variance,
				'denoms_json'   => $denoms_json,
				'note'          => $note,
			],
			[ 'id' => $shift_id ]
		);

		Audit::log(
			'shift_close',
			'shift',
			$shift_id,
			[ 'expected_cash' => $expected_cash ],
			[ 'counted_cash' => $counted_cash, 'variance' => $variance ],
			get_current_user_id()
		);

		return true;
	}

	public static function get( int $shift_id ): ?array {
		global $wpdb;
		$table = Install::table( 'shifts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $shift_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function open_for_register( int $register_id ): ?array {
		global $wpdb;
		$table = Install::table( 'shifts' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE open_key = %d", $register_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * The sale endpoint calls this first and refuses with 409 if there is no
	 * open shift. A sale with no shift is a sale whose cash nobody is
	 * accountable for.
	 *
	 * @return int|\WP_Error
	 */
	public static function require_open( int $register_id ) {
		$shift = self::open_for_register( $register_id );
		if ( ! $shift ) {
			return new \WP_Error(
				'cntr_shift_required',
				__( 'No open shift on this register.', 'counter' ),
				[ 'status' => 409 ]
			);
		}
		return (int) $shift['id'];
	}
}
