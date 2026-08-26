<?php
namespace Counter\People;

use Counter\Install;
use Counter\Db;
use Counter\Settings;
use Counter\Pos\Accounts;
use Counter\Reports\Expenses;

defined( 'ABSPATH' ) || exit;

/**
 * P6.4 — a draft a human approves, never an automatic payment. Drawn from
 * attendance, with allowances (the employee's own `allowances_json`) and
 * deductions (per-run, ad hoc — a loan repayment, an advance — passed in
 * at `create_run()` time, since nothing durable stores a standing
 * deduction anywhere in this schema). `cntr_payroll_runs`/`cntr_payroll_lines`
 * were both already pre-scaffolded in full (`UNIQUE KEY period` on runs,
 * `UNIQUE KEY run_emp` on lines) — no `CNTR_DB_VER` bump.
 *
 * `starting_salary` and `basic` stay two separate columns on
 * `cntr_employees` (already pre-scaffolded that way) — `basic` is what
 * payroll actually pays; `starting_salary` is never read here, a
 * historical reference only.
 *
 * "Approval writes to the ledger against a named account (same rule as
 * P2.5)" — P2.5's own rule was "payment requires a named account,
 * mandatory at the API level, not just in the form." Reusing
 * `Reports\Expenses::create()` (P5.4, already enforces exactly that rule)
 * for the approved total — booked once, against the 'wages' category — IS
 * that ledger write, not a second mechanism built to duplicate it.
 */
class Payroll {

	public static function init(): void {}

	private static function days_in_period( string $period ): int {
		return (int) gmdate( 't', strtotime( $period . '-01' ) );
	}

	public static function holidays(): array {
		$decoded = json_decode( (string) Settings::get( 'people.holidays' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private static function attendance_days( int $employee_id, string $period ): string {
		global $wpdb;
		$table = Install::table( 'attendance' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE employee_id = %d AND work_date LIKE %s", $employee_id, $wpdb->esc_like( $period ) . '-%' ) );
		return wc_format_decimal( (string) $count, 2 );
	}

	private static function leave_days( int $employee_id, string $period ): string {
		global $wpdb;
		$table = Install::table( 'leave' );
		$sum   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(days),0) FROM {$table} WHERE employee_id = %d AND status = 'approved' AND from_date LIKE %s", $employee_id, $wpdb->esc_like( $period ) . '-%' ) );
		return wc_format_decimal( $sum, 2 );
	}

	/** Configured holidays in this period the employee did not ALSO clock attendance for (avoids double-paying a day counted both ways). */
	private static function holiday_days( int $employee_id, string $period ): string {
		$in_period = array_values( array_filter( self::holidays(), static fn( $d ) => 0 === strpos( (string) $d, $period ) ) );
		if ( empty( $in_period ) ) {
			return '0.00';
		}
		global $wpdb;
		$table         = Install::table( 'attendance' );
		$placeholders  = implode( ',', array_fill( 0, count( $in_period ), '%s' ) );
		$already       = $wpdb->get_col(
			$wpdb->prepare( "SELECT work_date FROM {$table} WHERE employee_id = %d AND work_date IN ({$placeholders})", $employee_id, ...$in_period ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $placeholders built from %-placeholders only
		);
		$uncovered = array_diff( $in_period, $already );
		return wc_format_decimal( (string) count( $uncovered ), 2 );
	}

	private static function allowances_for( array $employee ): string {
		$decoded = json_decode( (string) ( $employee['allowances_json'] ?? '' ), true );
		$total   = '0.0000';
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $amount ) {
				$total = bcadd( $total, wc_format_decimal( (string) $amount, 4 ), 4 );
			}
		}
		return $total;
	}

	/**
	 * One employee's own draft line for a period — paid days capped at
	 * the period's own real length, never more than 100% of a month
	 * however attendance/leave/holidays happen to overlap.
	 */
	public static function compute_line( int $employee_id, string $period, string $extra_deduction = '0.0000' ): array {
		$employee   = Employees::get( $employee_id );
		$total_days = self::days_in_period( $period );

		$attended = self::attendance_days( $employee_id, $period );
		$leave    = self::leave_days( $employee_id, $period );
		$holiday  = self::holiday_days( $employee_id, $period );

		$paid_days = bcadd( bcadd( $attended, $leave, 2 ), $holiday, 2 );
		if ( bccomp( $paid_days, (string) $total_days, 2 ) > 0 ) {
			$paid_days = wc_format_decimal( (string) $total_days, 2 );
		}

		$basic  = wc_format_decimal( $employee['basic'] ?? 0, 4 );
		$earned = 0 === $total_days ? '0.0000' : bcdiv( bcmul( $basic, $paid_days, 6 ), (string) $total_days, 2 );

		$allowances = self::allowances_for( (array) $employee );
		$deductions = wc_format_decimal( $extra_deduction, 4 );
		$net        = bcsub( bcadd( $earned, $allowances, 4 ), $deductions, 4 );

		return [
			'employee_id' => $employee_id,
			'days_worked' => $paid_days,
			'earned'      => $earned,
			'allowances'  => $allowances,
			'deductions'  => $deductions,
			'net'         => $net,
		];
	}

	/**
	 * $deductions: employee_id => amount, ad hoc per-run only. $employee_ids
	 * null = every active employee (the real production default); a
	 * specific list scopes the run to exactly those employees.
	 *
	 * @return int|\WP_Error the new run's own id.
	 */
	public static function create_run( string $period, int $account_id, int $user_id, array $deductions = [], ?array $employee_ids = null ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $period ) ) {
			return new \WP_Error( 'cntr_payroll_bad_period', __( 'Period must be Y-m.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( $account_id <= 0 || ! Accounts::is_active( $account_id ) ) {
			return new \WP_Error( 'cntr_payroll_no_account', __( 'A payroll run must name an active payment account.', 'counter' ), [ 'status' => 422 ] );
		}

		global $wpdb;
		$runs_table = Install::table( 'payroll_runs' );
		$ok         = $wpdb->insert(
			$runs_table,
			[ 'period' => $period, 'status' => 'draft', 'total' => '0.0000', 'account_id' => $account_id, 'user_id' => $user_id, 'created_at' => Db::now() ]
		);
		if ( ! $ok ) {
			return new \WP_Error( 'cntr_payroll_period_exists', __( 'A payroll run for this period already exists.', 'counter' ), [ 'status' => 409 ] );
		}
		$run_id = (int) $wpdb->insert_id;

		$employees = null === $employee_ids
			? Employees::all()
			: array_values( array_filter( array_map( [ Employees::class, 'get' ], $employee_ids ) ) );

		$lines_table = Install::table( 'payroll_lines' );
		$total       = '0.0000';
		foreach ( $employees as $employee ) {
			$extra = wc_format_decimal( (string) ( $deductions[ $employee['id'] ] ?? 0 ), 4 );
			$line  = self::compute_line( $employee['id'], $period, $extra );

			$wpdb->insert(
				$lines_table,
				[
					'run_id'          => $run_id,
					'employee_id'     => $employee['id'],
					'days_worked'     => $line['days_worked'],
					'earned'          => $line['earned'],
					'allowances'      => $line['allowances'],
					'deductions_json' => bccomp( $extra, '0', 4 ) > 0 ? wp_json_encode( [ 'ad_hoc' => $extra ] ) : null,
					'deductions'      => $line['deductions'],
					'net'             => $line['net'],
				]
			);
			$total = bcadd( $total, $line['net'], 4 );
		}

		$wpdb->update( $runs_table, [ 'total' => $total ], [ 'id' => $run_id ] );
		return $run_id;
	}

	public static function get_run( int $run_id ): ?array {
		global $wpdb;
		$table = Install::table( 'payroll_runs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function lines_for_run( int $run_id ): array {
		global $wpdb;
		$table = Install::table( 'payroll_lines' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY employee_id", $run_id ), ARRAY_A );
	}

	/** @return true|\WP_Error */
	public static function approve_run( int $run_id ) {
		$run = self::get_run( $run_id );
		if ( null === $run ) {
			return new \WP_Error( 'cntr_payroll_missing', __( 'Payroll run not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( 'draft' !== $run['status'] ) {
			return new \WP_Error( 'cntr_payroll_not_draft', __( 'Only a draft run can be approved.', 'counter' ), [ 'status' => 409 ] );
		}

		if ( bccomp( $run['total'], '0', 4 ) > 0 ) {
			$wages_category = Expenses::category_by_code( 'wages' );
			if ( $wages_category ) {
				$expense_id = Expenses::create(
					[
						'category_id' => $wages_category['id'],
						'location_id' => \Counter\Stock\Locations::default_id(),
						'account_id'  => (int) $run['account_id'],
						'amount'      => $run['total'],
						'spent_on'    => $run['period'] . '-01',
						'note'        => sprintf( 'Payroll run #%d (%s)', $run_id, $run['period'] ),
					]
				);
				if ( is_wp_error( $expense_id ) ) {
					return $expense_id;
				}
			}
		}

		global $wpdb;
		$table = Install::table( 'payroll_runs' );
		$wpdb->update( $table, [ 'status' => 'approved', 'approved_at' => Db::now() ], [ 'id' => $run_id ] );
		return true;
	}
}
