<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * P6.1 — the mandatory five rules for a PIN that switches an operator
 * without becoming a back door (Direction's own numbering, kept here):
 *
 * 1. Hashed with password_hash(), verified server-side only — never
 *    compared in JavaScript, never sent to the client in any form, not
 *    even hashed. This class is the ONLY code that ever calls
 *    password_hash()/password_verify() on a PIN; every raw SQL query here
 *    touches `pin_hash` directly rather than going through
 *    People\Employees' own accessors, which strip that column
 *    unconditionally — the one column this plugin structurally refuses to
 *    let leave this file.
 * 2. Rate-limited to five attempts per register per minute, then a
 *    sixty-second lockout — WP transients keyed by register_id, no new
 *    table: a lockout is inherently ephemeral state, not a business record.
 * 3. A PIN grants the OPERATOR IDENTITY ONLY. switch_operator() never
 *    calls wp_set_current_user() or touches any WordPress capability —
 *    it writes an identity into a transient for AUDIT ATTRIBUTION, nothing
 *    more. The REST caller's own authentication (the terminal's
 *    application password, or a directly logged-in supervisor) is what
 *    Router::guard_any() already checks; the PIN layers "who is standing
 *    here" on top, never underneath.
 * 4. The terminal is authenticated separately (P0.4, cntr_terminal). This
 *    file never issues or checks that credential.
 * 5. (Overrides/void/no-sale/price-change carrying operator_id in their
 *    own audit rows is each of THOSE endpoints' own job, not this file's —
 *    current_operator() is the read side that lets them do it. See
 *    docs/decisions.md, P6.1: wiring every existing action's own audit
 *    call is explicitly out of this task's scope.)
 */
class Pin {

	const MIN_LEN         = 4;
	const MAX_LEN          = 6;
	const MAX_ATTEMPTS     = 5;
	const WINDOW_SECONDS   = 60;
	const LOCKOUT_SECONDS  = 60;
	const OPERATOR_TTL     = 12 * HOUR_IN_SECONDS; // longer than any real shift; a fresh switch always overwrites it anyway

	public static function init(): void {}

	public static function is_valid_format( string $pin ): bool {
		return 1 === preg_match( '/^\d{' . self::MIN_LEN . ',' . self::MAX_LEN . '}$/', $pin );
	}

	/**
	 * Format + "no other active employee at this location already has
	 * this exact PIN" — checked BEFORE any hash is written, so a rejected
	 * PIN never reaches storage. Hashes can't be compared directly
	 * (password_hash() salts), so this verifies the candidate plaintext
	 * against every OTHER active employee's existing hash at the same
	 * location — the set of people who could plausibly stand at the same
	 * register. $exclude_employee_id is the employee being changed (0 for
	 * a brand new one, which by definition excludes nothing extra).
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_new_pin( string $pin, int $location_id, int $exclude_employee_id = 0 ) {
		if ( ! self::is_valid_format( $pin ) ) {
			return new \WP_Error( 'cntr_pin_bad_format', __( 'A PIN must be 4 to 6 digits.', 'counter' ), [ 'status' => 422 ] );
		}
		global $wpdb;
		$table = Install::table( 'employees' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pin_hash FROM {$table} WHERE location_id = %d AND status = 'active' AND id != %d AND pin_hash != ''",
				$location_id,
				$exclude_employee_id
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( password_verify( $pin, $row['pin_hash'] ) ) {
				return new \WP_Error( 'cntr_pin_duplicate', __( 'This PIN is already in use by another employee at this location.', 'counter' ), [ 'status' => 409 ] );
			}
		}
		return true;
	}

	public static function hash( string $pin ): string {
		return password_hash( $pin, PASSWORD_DEFAULT );
	}

	/**
	 * Change (or set) an EXISTING employee's PIN. Audited unconditionally —
	 * "PIN change is audited" is not contingent on who did it or why.
	 *
	 * @return true|\WP_Error
	 */
	public static function set( int $employee_id, string $plain_pin ) {
		global $wpdb;
		$table    = Install::table( 'employees' );
		$employee = $wpdb->get_row( $wpdb->prepare( "SELECT id, location_id FROM {$table} WHERE id = %d", $employee_id ), ARRAY_A );
		if ( ! $employee ) {
			return new \WP_Error( 'cntr_pin_no_employee', __( 'Employee not found.', 'counter' ), [ 'status' => 404 ] );
		}
		$check = self::validate_new_pin( $plain_pin, (int) $employee['location_id'], $employee_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$wpdb->update( $table, [ 'pin_hash' => self::hash( $plain_pin ) ], [ 'id' => $employee_id ] );
		Audit::log( 'pin_change', 'employee', $employee_id, null, null, $employee_id );
		return true;
	}

	// -- Rate limiting, per register ---------------------------------------

	private static function attempts_key( int $register_id ): string {
		return 'cntr_pin_attempts_' . $register_id;
	}

	private static function lockout_key( int $register_id ): string {
		return 'cntr_pin_lockout_' . $register_id;
	}

	public static function is_locked_out( int $register_id ): bool {
		return (bool) get_transient( self::lockout_key( $register_id ) );
	}

	/** A failure past the 5 allowed within the window locks the register for 60s — "six rapid failures lock it," not five. */
	private static function record_failure( int $register_id ): void {
		$key   = self::attempts_key( $register_id );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, self::WINDOW_SECONDS );
		if ( $count > self::MAX_ATTEMPTS ) {
			set_transient( self::lockout_key( $register_id ), 1, self::LOCKOUT_SECONDS );
			delete_transient( $key );
		}
	}

	private static function clear_failures( int $register_id ): void {
		delete_transient( self::attempts_key( $register_id ) );
	}

	// -- The operator switch itself -----------------------------------------

	private static function operator_key( int $register_id ): string {
		return 'cntr_pin_operator_' . $register_id;
	}

	/** 0 if no one has switched in (or the last switch's own TTL expired). Never a WP user id — an operator id from cntr_employees. */
	public static function current_operator( int $register_id ): int {
		return (int) get_transient( self::operator_key( $register_id ) );
	}

	/**
	 * @return array{operator_id:int,code:string,designation:string}|\WP_Error
	 */
	public static function switch_operator( int $register_id, string $plain_pin ) {
		if ( self::is_locked_out( $register_id ) ) {
			return new \WP_Error( 'cntr_pin_locked', __( 'Too many attempts. Try again in a minute.', 'counter' ), [ 'status' => 429 ] );
		}

		$register = Registers::get( $register_id );
		if ( ! $register ) {
			return new \WP_Error( 'cntr_pin_bad_register', __( 'Register not found.', 'counter' ), [ 'status' => 404 ] );
		}
		$location_id = (int) $register['location_id'];

		global $wpdb;
		$table      = Install::table( 'employees' );
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, code, designation, pin_hash FROM {$table} WHERE location_id = %d AND status = 'active' AND pin_hash != ''",
				$location_id
			),
			ARRAY_A
		);

		$matched = null;
		foreach ( $candidates as $candidate ) {
			if ( password_verify( $plain_pin, $candidate['pin_hash'] ) ) {
				$matched = $candidate;
				break;
			}
		}

		if ( null === $matched ) {
			self::record_failure( $register_id );
			return new \WP_Error( 'cntr_pin_incorrect', __( 'Incorrect PIN.', 'counter' ), [ 'status' => 401 ] );
		}

		self::clear_failures( $register_id );
		$operator_id = (int) $matched['id'];
		set_transient( self::operator_key( $register_id ), $operator_id, self::OPERATOR_TTL );
		Audit::log( 'pin_switch', 'employee', $operator_id, null, [ 'register_id' => $register_id ], $operator_id );

		return [ 'operator_id' => $operator_id, 'code' => $matched['code'], 'designation' => $matched['designation'] ];
	}
}
