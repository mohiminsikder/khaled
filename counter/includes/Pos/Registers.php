<?php
namespace Counter\Pos;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

class Registers {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'registers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function by_prefix( string $prefix ): ?array {
		global $wpdb;
		$table = Install::table( 'registers' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE prefix = %s", $prefix ), ARRAY_A );
		return $row ?: null;
	}

	public static function all( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'registers' );
		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id", $status ), ARRAY_A );
	}

	/**
	 * Backs Rest\Router::guard()'s register-binding check (the X-CNTR-Register
	 * header, keyed by register id) — a terminal request that claims a register
	 * that doesn't exist or has been deactivated is rejected before it reaches
	 * any endpoint logic.
	 */
	public static function valid_for_user( $header_value ): bool {
		if ( empty( $header_value ) ) {
			return false;
		}
		$register = self::get( (int) $header_value );
		return $register && 'active' === $register['status'];
	}

	/** Case-insensitive exact match — same "an exact collision is the whole problem" reasoning as Stock\Locations::name_exists(), teardown defect #10's other half. */
	public static function name_exists( string $name, int $exclude_id = 0 ): bool {
		global $wpdb;
		$table = Install::table( 'registers' );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE LOWER(name) = LOWER(%s)";
		$args  = [ trim( $name ) ];
		if ( $exclude_id ) {
			$sql   .= ' AND id != %d';
			$args[] = $exclude_id;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) ) > 0;
	}

	/**
	 * A register's prefix names every receipt it ever issues (nextReceiptNo()'s
	 * own {prefix}-{shift}-{seq} shape) — two registers sharing one would
	 * produce colliding receipt numbers forever, so this is generated, never
	 * left to chance or operator typo. Db::next_seq() (the same globally-
	 * unique counter po_no/expense_ref/transfer_ref already use) guarantees
	 * this counter's OWN numbers are never issued twice, but it can't see
	 * 'R1' — Install::activate() seeds that prefix straight into the table
	 * for the site's default register, outside next_seq() entirely. Found
	 * live in C5's self-test: on a fresh counter, the very first call here
	 * generates 'R1', collides with that seeded row's UNIQUE KEY, and the
	 * insert silently fails. Re-checked against by_prefix() and re-drawn on
	 * collision, same as an explicitly-supplied prefix already is below.
	 */
	public static function generate_unique_prefix(): string {
		do {
			$prefix = 'R' . Db::next_seq( 'register_prefix' );
		} while ( self::by_prefix( $prefix ) );
		return $prefix;
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function create( array $data ) {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'cntr_register_no_name', __( 'A register name is required.', 'counter' ), [ 'status' => 400 ] );
		}
		if ( self::name_exists( $name ) ) {
			return new \WP_Error( 'cntr_register_duplicate_name', __( 'A register with this name already exists.', 'counter' ), [ 'status' => 409 ] );
		}

		$prefix = trim( (string) ( $data['prefix'] ?? '' ) );
		if ( '' === $prefix ) {
			$prefix = self::generate_unique_prefix();
		} elseif ( self::by_prefix( $prefix ) ) {
			return new \WP_Error( 'cntr_register_duplicate_prefix', __( 'A register with this prefix already exists.', 'counter' ), [ 'status' => 409 ] );
		}

		global $wpdb;
		$table = Install::table( 'registers' );

		$inserted = $wpdb->insert(
			$table,
			[
				'name'          => $name,
				'location_id'   => (int) ( $data['location_id'] ?? 0 ),
				'prefix'        => $prefix,
				'settings_json' => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
				'status'        => $data['status'] ?? 'active',
				'created_at'    => Db::now(),
			]
		);

		// Found live in C5's self-test alongside the 'R1' collision above:
		// a failed insert() left this returning $wpdb->insert_id stale from
		// whatever the LAST successful insert in the request happened to be
		// (a different table's row, on the evidence that surfaced this) —
		// silently handing back a wrong-but-valid-looking id instead of
		// reporting the failure.
		if ( ! $inserted ) {
			return new \WP_Error( 'cntr_register_create_failed', __( 'Could not create the register.', 'counter' ), [ 'status' => 500 ] );
		}

		return (int) $wpdb->insert_id;
	}
}
