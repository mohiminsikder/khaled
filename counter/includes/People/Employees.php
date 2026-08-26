<?php
namespace Counter\People;

use Counter\Install;
use Counter\Audit;
use Counter\Pos\Pin;

defined( 'ABSPATH' ) || exit;

/**
 * P6.1 — an employee record extends a WordPress user: one `user_id` per
 * employee (`UNIQUE KEY user_id`), the WP account is the real identity,
 * `cntr_employees` is the operational record layered on top (PIN,
 * designation, location, pay).
 *
 * Every accessor here strips `pin_hash` unconditionally, even internally —
 * the only code in this plugin that ever touches a real hash value is
 * `Pos\Pin`'s own SQL, which never routes through these methods.
 * `test_pin()` check 5 ("the PIN hash never appears in any REST response")
 * is true structurally because of this, not because every future caller
 * happens to remember to unset it.
 */
class Employees {

	public static function init(): void {}

	private static function strip( ?array $row ): ?array {
		if ( null === $row ) {
			return null;
		}
		unset( $row['pin_hash'] );
		return $row;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'employees' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return self::strip( $row ?: null );
	}

	public static function get_by_user( int $user_id ): ?array {
		global $wpdb;
		$table = Install::table( 'employees' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
		return self::strip( $row ?: null );
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$table = Install::table( 'employees' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return self::strip( $row ?: null );
	}

	public static function all( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'employees' );
		$rows  = '' === $status
			? $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id", $status ), ARRAY_A );
		return array_values( array_map( [ self::class, 'strip' ], $rows ) );
	}

	/**
	 * $args: user_id (required, a real WP user not already an employee),
	 * code (required, unique), designation, location_id, join_date,
	 * starting_salary, basic, allowances (array), note, pin (optional
	 * plaintext — validated and hashed BEFORE the row is inserted, so a
	 * rejected PIN never leaves a half-created employee record behind).
	 *
	 * @return int|\WP_Error
	 */
	public static function create( array $args ) {
		$user_id = (int) ( $args['user_id'] ?? 0 );
		$code    = sanitize_key( (string) ( $args['code'] ?? '' ) );

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'cntr_employee_bad_user', __( 'An employee needs a real WordPress user.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( null !== self::get_by_user( $user_id ) ) {
			return new \WP_Error( 'cntr_employee_exists', __( 'This user already has an employee record.', 'counter' ), [ 'status' => 409 ] );
		}
		if ( '' === $code ) {
			return new \WP_Error( 'cntr_employee_bad_code', __( 'An employee needs a code.', 'counter' ), [ 'status' => 422 ] );
		}
		if ( null !== self::get_by_code( $code ) ) {
			return new \WP_Error( 'cntr_employee_code_taken', __( 'This employee code is already in use.', 'counter' ), [ 'status' => 409 ] );
		}

		$location_id = (int) ( $args['location_id'] ?? 0 );
		$pin         = isset( $args['pin'] ) ? (string) $args['pin'] : '';
		$pin_hash    = '';
		if ( '' !== $pin ) {
			$check = Pin::validate_new_pin( $pin, $location_id, 0 );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$pin_hash = Pin::hash( $pin );
		}

		global $wpdb;
		$table = Install::table( 'employees' );
		$wpdb->insert(
			$table,
			[
				'user_id'         => $user_id,
				'code'            => $code,
				'pin_hash'        => $pin_hash,
				'designation'     => sanitize_text_field( (string) ( $args['designation'] ?? '' ) ),
				'location_id'     => $location_id,
				'join_date'       => ! empty( $args['join_date'] ) ? (string) $args['join_date'] : null,
				'status'          => 'active',
				'starting_salary' => wc_format_decimal( $args['starting_salary'] ?? 0, 4 ),
				'basic'           => wc_format_decimal( $args['basic'] ?? 0, 4 ),
				'allowances_json' => isset( $args['allowances'] ) ? wp_json_encode( $args['allowances'] ) : null,
				'note'            => (string) ( $args['note'] ?? '' ),
			]
		);
		$employee_id = (int) $wpdb->insert_id;

		if ( '' !== $pin ) {
			Audit::log( 'pin_change', 'employee', $employee_id, null, null, $employee_id );
		}

		return $employee_id;
	}

	public static function deactivate( int $id ): void {
		global $wpdb;
		$table = Install::table( 'employees' );
		$wpdb->update( $table, [ 'status' => 'inactive' ], [ 'id' => $id ] );
	}
}
