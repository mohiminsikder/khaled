<?php
namespace Counter\Stock;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * A default location exists and every ledger row carries one. Multi-location
 * depth (transfers, per-location reporting) lands in P2.1; this task only
 * guarantees no ledger row is ever written with location_id = 0.
 */
class Locations {

	/** @var array<int>|null Cached per request. */
	private static ?array $online_sellable_cache = null;

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'locations' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function all( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'locations' );
		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id", $status ), ARRAY_A );
	}

	public static function default_id(): int {
		global $wpdb;
		$table = Install::table( 'locations' );
		return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 LIMIT 1" );
	}

	/** Locations whose stock feeds the number published to WooCommerce. Cached per request. */
	public static function online_sellable_ids(): array {
		if ( null !== self::$online_sellable_cache ) {
			return self::$online_sellable_cache;
		}
		global $wpdb;
		$table = Install::table( 'locations' );
		$ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE is_online_sellable = 1 AND status = 'active'" );

		self::$online_sellable_cache = array_map( 'intval', $ids );
		return self::$online_sellable_cache;
	}

	public static function for_register( int $register_id ): ?array {
		global $wpdb;
		$registers   = Install::table( 'registers' );
		$location_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM {$registers} WHERE id = %d", $register_id ) );
		return $location_id ? self::get( $location_id ) : null;
	}

	/**
	 * A new default demotes the current one — there is no UNIQUE constraint doing
	 * this at the schema level (unlike shifts.open_key), so the application layer
	 * is what stops two defaults existing at once. Demotion always runs first,
	 * inside the same call, so there's never a moment with zero defaults either.
	 */
	public static function create( array $data ): int {
		global $wpdb;
		$table      = Install::table( 'locations' );
		$is_default = ! empty( $data['is_default'] );

		if ( $is_default ) {
			self::demote_current_default();
		}

		$wpdb->insert(
			$table,
			[
				'name'               => $data['name'] ?? '',
				'code'               => $data['code'] ?? '',
				'is_online_sellable' => empty( $data['is_online_sellable'] ) ? 0 : 1,
				'is_default'         => $is_default ? 1 : 0,
				'address_json'       => isset( $data['address'] ) ? wp_json_encode( $data['address'] ) : null,
				'status'             => $data['status'] ?? 'active',
				'created_at'         => Db::now(),
			]
		);

		self::$online_sellable_cache = null;
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$table = Install::table( 'locations' );

		if ( ! empty( $data['is_default'] ) ) {
			self::demote_current_default();
		}

		$allowed = [ 'name', 'code', 'is_online_sellable', 'is_default', 'address_json', 'status' ];
		$fields  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( ! empty( $fields ) ) {
			$wpdb->update( $table, $fields, [ 'id' => $id ] );
		}

		self::$online_sellable_cache = null;
	}

	private static function demote_current_default(): void {
		global $wpdb;
		$table = Install::table( 'locations' );
		$wpdb->query( "UPDATE {$table} SET is_default = 0 WHERE is_default = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables, static SQL
	}
}
