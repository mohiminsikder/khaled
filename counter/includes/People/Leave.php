<?php
namespace Counter\People;

use Counter\Install;
use Counter\Db;
use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * P6.3 — types with annual caps, and the three-way split Direction names:
 * View / Request / Approve. `cntr_leave` was already pre-scaffolded in
 * full (`days decimal(7,2)` already supports a half-day) — no
 * `CNTR_DB_VER` bump.
 *
 * Types live in Settings (`people.leave_types`, JSON) — the same map-
 * shaped-setting idiom as `payments.gateway_accounts` (P4.6) and
 * `expenses.recurring_rules` (P5.4) — since the plan's own Schema line
 * names `cntr_leave` alone, no separate types table.
 *
 * REQUEST needs no new capability: any employee with a linked WP account
 * can request their own leave — "so a junior can raise leave" is
 * Direction's own reason this is not capability-gated at all, only
 * scoped to the CALLER's own employee record at the REST layer (never a
 * client-supplied employee_id — that would let anyone request leave on
 * anyone else's behalf). APPROVE needs `cntr_approve_leave` (already
 * scaffolded). VIEW (someone else's requests, not your own — you can
 * always see your own) needs the new `cntr_view_leave`.
 *
 * The "annual cap" and "overlap" checks both count `requested` AND
 * `approved` rows, never `approved` alone — a cap or an overlap that only
 * blocked once something was already approved would let an employee
 * stack ten simultaneous pending requests that each individually look
 * fine and only collectively bust the cap, or physically double-book the
 * same day twice. `cancelled`/`rejected` rows never count toward either —
 * cancelling an approved request "restores the balance" for exactly this
 * reason: the balance was never stored anywhere, it is always summed live
 * from what is actually still requested-or-approved right now.
 */
class Leave {

	public static function init(): void {}

	public static function types(): array {
		$decoded = json_decode( (string) Settings::get( 'people.leave_types' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public static function type_by_code( string $code ): ?array {
		foreach ( self::types() as $t ) {
			if ( ( $t['code'] ?? '' ) === $code ) {
				return $t;
			}
		}
		return null;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'leave' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/** Days already requested-or-approved for this employee/type/calendar-year — the live number a new request's own cap check is measured against. */
	public static function committed_days( int $employee_id, string $type, int $year, ?int $exclude_leave_id = null ): string {
		global $wpdb;
		$table  = Install::table( 'leave' );
		$where  = "employee_id = %d AND type = %s AND status IN ('requested','approved') AND YEAR(from_date) = %d";
		$params = [ $employee_id, $type, $year ];
		if ( null !== $exclude_leave_id ) {
			$where   .= ' AND id != %d';
			$params[] = $exclude_leave_id;
		}
		$sum = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(days),0) FROM {$table} WHERE {$where}", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where built from %-placeholders only
		return wc_format_decimal( $sum, 2 );
	}

	private static function has_overlap( int $employee_id, string $from, string $to, ?int $exclude_id = null ): bool {
		global $wpdb;
		$table  = Install::table( 'leave' );
		$where  = "employee_id = %d AND status IN ('requested','approved') AND from_date <= %s AND to_date >= %s";
		$params = [ $employee_id, $to, $from ];
		if ( null !== $exclude_id ) {
			$where   .= ' AND id != %d';
			$params[] = $exclude_id;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where built from %-placeholders only
		return $count > 0;
	}

	private static function compute_days( string $from, string $to, bool $half_day ): string {
		if ( $half_day ) {
			return '0.50';
		}
		$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
		return wc_format_decimal( (string) $days, 2 );
	}

	/**
	 * $args: employee_id, type (a code from types()), from_date, to_date
	 * (both 'Y-m-d'), half_day (bool — from_date must equal to_date),
	 * reason.
	 *
	 * @return int|\WP_Error
	 */
	public static function request( array $args ) {
		$employee_id = (int) ( $args['employee_id'] ?? 0 );
		$type        = sanitize_key( (string) ( $args['type'] ?? '' ) );
		$from        = (string) ( $args['from_date'] ?? '' );
		$to          = (string) ( $args['to_date'] ?? '' );
		$half_day    = ! empty( $args['half_day'] );
		$reason      = (string) ( $args['reason'] ?? '' );

		if ( null === Employees::get( $employee_id ) ) {
			return new \WP_Error( 'cntr_leave_no_employee', __( 'Employee not found.', 'counter' ), [ 'status' => 404 ] );
		}
		$type_def = self::type_by_code( $type );
		if ( null === $type_def ) {
			return new \WP_Error( 'cntr_leave_bad_type', __( 'Unknown leave type.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			return new \WP_Error( 'cntr_leave_bad_dates', __( 'Invalid date range.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( $half_day && $from !== $to ) {
			return new \WP_Error( 'cntr_leave_bad_dates', __( 'A half-day request must be a single date.', 'counter' ), [ 'status' => 422 ] );
		}

		if ( self::has_overlap( $employee_id, $from, $to ) ) {
			return new \WP_Error( 'cntr_leave_overlap', __( 'This overlaps a request already on file.', 'counter' ), [ 'status' => 409 ] );
		}

		$days      = self::compute_days( $from, $to, $half_day );
		$year      = (int) substr( $from, 0, 4 );
		$committed = self::committed_days( $employee_id, $type, $year );
		$cap       = wc_format_decimal( $type_def['annual_days'] ?? 0, 2 );
		if ( bccomp( bcadd( $committed, $days, 2 ), $cap, 2 ) > 0 ) {
			return new \WP_Error( 'cntr_leave_over_cap', __( 'This request would exceed the annual cap for this leave type.', 'counter' ), [ 'status' => 422 ] );
		}

		global $wpdb;
		$table = Install::table( 'leave' );
		$wpdb->insert(
			$table,
			[
				'employee_id' => $employee_id,
				'type'        => $type,
				'from_date'   => $from,
				'to_date'     => $to,
				'days'        => $days,
				'status'      => 'requested',
				'approver_id' => 0,
				'reason'      => $reason,
				'created_at'  => Db::now(),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/** @return true|\WP_Error */
	public static function approve( int $leave_id, int $approver_user_id ) {
		$row = self::get( $leave_id );
		if ( null === $row ) {
			return new \WP_Error( 'cntr_leave_missing', __( 'Leave request not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'requested' !== $row['status'] ) {
			return new \WP_Error( 'cntr_leave_not_pending', __( 'This request is not awaiting a decision.', 'counter' ), [ 'status' => 409 ] );
		}
		global $wpdb;
		$table = Install::table( 'leave' );
		$wpdb->update( $table, [ 'status' => 'approved', 'approver_id' => $approver_user_id, 'decided_at' => Db::now() ], [ 'id' => $leave_id ] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function reject( int $leave_id, int $approver_user_id, string $reason = '' ) {
		$row = self::get( $leave_id );
		if ( null === $row ) {
			return new \WP_Error( 'cntr_leave_missing', __( 'Leave request not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'requested' !== $row['status'] ) {
			return new \WP_Error( 'cntr_leave_not_pending', __( 'This request is not awaiting a decision.', 'counter' ), [ 'status' => 409 ] );
		}
		global $wpdb;
		$table = Install::table( 'leave' );
		$note  = '' !== $reason ? trim( $row['reason'] . ' | rejected: ' . $reason ) : $row['reason'];
		$wpdb->update( $table, [ 'status' => 'rejected', 'approver_id' => $approver_user_id, 'decided_at' => Db::now(), 'reason' => $note ], [ 'id' => $leave_id ] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function cancel( int $leave_id ) {
		$row = self::get( $leave_id );
		if ( null === $row ) {
			return new \WP_Error( 'cntr_leave_missing', __( 'Leave request not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $row['status'], [ 'requested', 'approved' ], true ) ) {
			return new \WP_Error( 'cntr_leave_not_cancellable', __( 'Only a pending or approved request can be cancelled.', 'counter' ), [ 'status' => 409 ] );
		}
		global $wpdb;
		$table = Install::table( 'leave' );
		$wpdb->update( $table, [ 'status' => 'cancelled', 'decided_at' => Db::now() ], [ 'id' => $leave_id ] );
		return true;
	}

	public static function for_employee( int $employee_id ): array {
		global $wpdb;
		$table = Install::table( 'leave' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE employee_id = %d ORDER BY from_date DESC", $employee_id ), ARRAY_A );
	}

	/**
	 * Pending requests an approver may act on, scoped to THEIR OWN
	 * employee record's location — an approver with no employee record of
	 * their own (a pure wp-admin account) sees every location, matching
	 * `location_id = 0` meaning "no filter" everywhere else in this
	 * plugin (Reports.php, P5.2).
	 */
	public static function pending_for_approver( int $approver_user_id ): array {
		global $wpdb;
		$leave_table     = Install::table( 'leave' );
		$employees_table = Install::table( 'employees' );

		$approver_employee = Employees::get_by_user( $approver_user_id );
		$location_id       = $approver_employee ? (int) $approver_employee['location_id'] : 0;

		if ( $location_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT l.* FROM {$leave_table} l INNER JOIN {$employees_table} e ON e.id = l.employee_id
					 WHERE l.status = 'requested' AND e.location_id = %d ORDER BY l.from_date",
					$location_id
				),
				ARRAY_A
			);
		}
		return $wpdb->get_results( "SELECT * FROM {$leave_table} WHERE status = 'requested' ORDER BY from_date", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}

	public static function all(): array {
		global $wpdb;
		$table = Install::table( 'leave' );
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY from_date DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
	}
}
