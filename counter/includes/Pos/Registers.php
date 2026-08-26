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

	public static function create( array $data ): int {
		global $wpdb;
		$table = Install::table( 'registers' );

		$wpdb->insert(
			$table,
			[
				'name'          => $data['name'] ?? '',
				'location_id'   => (int) ( $data['location_id'] ?? 0 ),
				'prefix'        => $data['prefix'] ?? '',
				'settings_json' => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
				'status'        => $data['status'] ?? 'active',
				'created_at'    => Db::now(),
			]
		);

		return (int) $wpdb->insert_id;
	}
}
