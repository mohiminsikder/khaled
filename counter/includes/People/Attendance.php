<?php
namespace Counter\People;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P6.2 — clock in and out from the terminal itself, no second device.
 * `cntr_attendance` was already pre-scaffolded (`UNIQUE KEY emp_day
 * (employee_id, work_date)` included) — no `CNTR_DB_VER` bump.
 *
 * A shift is "open" from the moment `clock_in()` writes a row until
 * `clock_out()` closes it — found by `clock_out IS NULL`, never by
 * today's date, since an open shift that crossed midnight still needs to
 * be found and closed the next calendar day. `work_date` is fixed at
 * clock-in time (shop-local, `wp_date()`) and never recomputed at
 * clock-out — the classic off-by-one-day this task's own self-test names
 * is exactly what pinning the date to the START of the shift avoids.
 */
class Attendance {

	public static function init(): void {}

	public static function open_for( int $employee_id ): ?array {
		global $wpdb;
		$table = Install::table( 'attendance' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE employee_id = %d AND clock_out IS NULL ORDER BY id DESC LIMIT 1", $employee_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** @return int|\WP_Error the new attendance row's own id. */
	public static function clock_in( int $employee_id, ?string $at = null ) {
		if ( null === Employees::get( $employee_id ) ) {
			return new \WP_Error( 'cntr_attendance_no_employee', __( 'Employee not found.', 'counter' ), [ 'status' => 404 ] );
		}
		if ( null !== self::open_for( $employee_id ) ) {
			return new \WP_Error( 'cntr_attendance_already_open', __( 'This employee is already clocked in.', 'counter' ), [ 'status' => 409 ] );
		}

		$at        = $at ?? Db::now();
		$work_date = wp_date( 'Y-m-d', strtotime( $at . ' UTC' ) );

		global $wpdb;
		$table = Install::table( 'attendance' );
		$ok    = $wpdb->insert(
			$table,
			[
				'employee_id' => $employee_id,
				'work_date'   => $work_date,
				'clock_in'    => $at,
				'clock_out'   => null,
				'minutes'     => 0,
				'source'      => 'terminal',
				'status'      => 'present',
			]
		);
		// The UNIQUE KEY (employee_id, work_date) is what actually enforces
		// "one attendance record per employee per day" — a duplicate insert
		// fails here, not via a second application-level SELECT that could
		// race it.
		if ( ! $ok ) {
			return new \WP_Error( 'cntr_attendance_duplicate', __( 'An attendance record already exists for this employee and date.', 'counter' ), [ 'status' => 409 ] );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return true|\WP_Error */
	public static function clock_out( int $employee_id, ?string $at = null ) {
		$open = self::open_for( $employee_id );
		if ( null === $open ) {
			return new \WP_Error( 'cntr_attendance_not_open', __( 'This employee has not clocked in.', 'counter' ), [ 'status' => 409 ] );
		}

		$at      = $at ?? Db::now();
		$minutes = (int) floor( ( strtotime( $at . ' UTC' ) - strtotime( $open['clock_in'] . ' UTC' ) ) / 60 );
		if ( $minutes < 0 ) {
			return new \WP_Error( 'cntr_attendance_bad_order', __( 'A clock-out cannot be before its own clock-in.', 'counter' ), [ 'status' => 422 ] );
		}

		global $wpdb;
		$table = Install::table( 'attendance' );
		$wpdb->update( $table, [ 'clock_out' => $at, 'minutes' => $minutes ], [ 'id' => $open['id'] ] );
		return true;
	}

	public static function for_employee( int $employee_id, string $from = '', string $to = '' ): array {
		global $wpdb;
		$table  = Install::table( 'attendance' );
		$where  = 'WHERE employee_id = %d';
		$params = [ $employee_id ];
		if ( '' !== $from ) {
			$where   .= ' AND work_date >= %s';
			$params[] = $from;
		}
		if ( '' !== $to ) {
			$where   .= ' AND work_date <= %s';
			$params[] = $to;
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY work_date DESC", ...$params ), ARRAY_A );
	}

	/**
	 * CSV only — "Excel" here means the shop's spreadsheet exported to
	 * CSV, not a binary .xlsx parser; every other export in this plugin
	 * (VAT register, reports) is CSV for the same reason, and a parser
	 * dependency is real surface this plugin's own architecture avoids.
	 *
	 * Header row required: employee_code, work_date (Y-m-d), clock_in
	 * (H:i, shop-local), clock_out (H:i, shop-local). A bad row is
	 * reported and skipped — never aborts the rest of the file.
	 *
	 * @return array{imported:int,rejected:array<array{row:int,reason:string}>}
	 */
	public static function import_csv( string $csv_text ): array {
		$lines    = preg_split( '/\r\n|\n|\r/', trim( $csv_text ) );
		$header   = null;
		$imported = 0;
		$rejected = [];

		global $wpdb;
		$table = Install::table( 'attendance' );

		foreach ( $lines as $i => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols = str_getcsv( $line );

			if ( null === $header ) {
				$header = array_map( 'trim', $cols );
				continue;
			}

			$row_num = $i + 1;
			if ( count( $cols ) !== count( $header ) ) {
				$rejected[] = [ 'row' => $row_num, 'reason' => __( 'column count does not match the header', 'counter' ) ];
				continue;
			}
			$data = array_combine( $header, $cols );

			$employee = Employees::get_by_code( trim( (string) ( $data['employee_code'] ?? '' ) ) );
			if ( null === $employee ) {
				$rejected[] = [ 'row' => $row_num, 'reason' => __( 'unknown employee code', 'counter' ) ];
				continue;
			}

			$work_date = trim( (string) ( $data['work_date'] ?? '' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $work_date ) ) {
				$rejected[] = [ 'row' => $row_num, 'reason' => __( 'invalid work_date (need Y-m-d)', 'counter' ) ];
				continue;
			}

			$clock_in_time  = trim( (string) ( $data['clock_in'] ?? '' ) );
			$clock_out_time = trim( (string) ( $data['clock_out'] ?? '' ) );
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $clock_in_time ) || ! preg_match( '/^\d{2}:\d{2}$/', $clock_out_time ) ) {
				$rejected[] = [ 'row' => $row_num, 'reason' => __( 'invalid clock_in/clock_out (need H:i)', 'counter' ) ];
				continue;
			}

			$in_utc  = self::local_to_utc( $work_date . ' ' . $clock_in_time . ':00' );
			$out_utc = self::local_to_utc( $work_date . ' ' . $clock_out_time . ':00' );
			$minutes = (int) floor( ( strtotime( $out_utc . ' UTC' ) - strtotime( $in_utc . ' UTC' ) ) / 60 );
			if ( $minutes < 0 ) {
				$rejected[] = [ 'row' => $row_num, 'reason' => __( 'clock_out before clock_in', 'counter' ) ];
				continue;
			}

			$ok = $wpdb->insert(
				$table,
				[
					'employee_id' => $employee['id'],
					'work_date'   => $work_date,
					'clock_in'    => $in_utc,
					'clock_out'   => $out_utc,
					'minutes'     => $minutes,
					'source'      => 'import',
					'status'      => 'present',
				]
			);
			if ( ! $ok ) {
				$rejected[] = [ 'row' => $row_num, 'reason' => __( 'a record already exists for this employee and date', 'counter' ) ];
				continue;
			}
			$imported++;
		}

		return [ 'imported' => $imported, 'rejected' => $rejected ];
	}

	/** Shop-local 'Y-m-d H:i:s' -> UTC 'Y-m-d H:i:s', real DateTime + wp_timezone() conversion — same pattern as Reports\Rollup's own local_day_start_utc(). */
	private static function local_to_utc( string $local_datetime ): string {
		$dt = new \DateTime( $local_datetime, wp_timezone() );
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}
}
