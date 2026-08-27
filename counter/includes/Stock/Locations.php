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

	/** Case-insensitive exact match — teardown defect #10 is two locations named "Pharmacy", not a near-miss like Suppliers' own fuzzy check; an exact collision is the whole problem. */
	public static function name_exists( string $name, int $exclude_id = 0 ): bool {
		global $wpdb;
		$table = Install::table( 'locations' );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE LOWER(name) = LOWER(%s)";
		$args  = [ trim( $name ) ];
		if ( $exclude_id ) {
			$sql   .= ' AND id != %d';
			$args[] = $exclude_id;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) ) > 0;
	}

	/** True if this location currently holds any non-zero stock anywhere in cntr_stock — checked before deactivation (C5, teardown-adjacent: a location still holding goods must not silently vanish from the till/reports). */
	public static function has_stock( int $id ): bool {
		global $wpdb;
		$table = Install::table( 'stock' );
		$sum   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(qty),0) FROM {$table} WHERE location_id = %d", $id ) );
		return 0 !== bccomp( wc_format_decimal( $sum, 4 ), '0', 4 );
	}

	/**
	 * A new default demotes the current one — there is no UNIQUE constraint doing
	 * this at the schema level (unlike shifts.open_key), so the application layer
	 * is what stops two defaults existing at once. Demotion always runs first,
	 * inside the same call, so there's never a moment with zero defaults either.
	 *
	 * @return int|\WP_Error
	 */
	public static function create( array $data ) {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'cntr_location_no_name', __( 'A location name is required.', 'counter' ), [ 'status' => 400 ] );
		}
		if ( self::name_exists( $name ) ) {
			return new \WP_Error( 'cntr_location_duplicate_name', __( 'A location with this name already exists.', 'counter' ), [ 'status' => 409 ] );
		}

		global $wpdb;
		$table      = Install::table( 'locations' );
		$is_default = ! empty( $data['is_default'] );

		if ( $is_default ) {
			self::demote_current_default();
		}

		$wpdb->insert(
			$table,
			[
				'name'               => $name,
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

	/**
	 * @return true|\WP_Error
	 */
	public static function update( int $id, array $data ) {
		if ( isset( $data['name'] ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return new \WP_Error( 'cntr_location_no_name', __( 'A location name is required.', 'counter' ), [ 'status' => 400 ] );
			}
			if ( self::name_exists( $name, $id ) ) {
				return new \WP_Error( 'cntr_location_duplicate_name', __( 'A location with this name already exists.', 'counter' ), [ 'status' => 409 ] );
			}
			$data['name'] = $name;
		}
		// A location still holding stock must not silently disappear from
		// the till's own register picker or every stock report — refused,
		// not silently allowed, the same "the operator sees why, not a
		// blank screen" standard this whole plugin holds every refusal to.
		if ( isset( $data['status'] ) && 'active' !== $data['status'] && self::has_stock( $id ) ) {
			return new \WP_Error( 'cntr_location_has_stock', __( 'This location still holds stock and cannot be deactivated — transfer it out first.', 'counter' ), [ 'status' => 409 ] );
		}

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
		return true;
	}

	private static function demote_current_default(): void {
		global $wpdb;
		$table = Install::table( 'locations' );
		$wpdb->query( "UPDATE {$table} SET is_default = 0 WHERE is_default = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables, static SQL
	}
}
