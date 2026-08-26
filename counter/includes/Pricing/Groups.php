<?php
namespace Counter\Pricing;

use Counter\Install;
use Counter\Db;
use Counter\Stock\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * P4.3 — Direction's own words: "one override table on top of WooCommerce
 * prices, not a pricing engine." `cntr_price_groups`/`cntr_price_group_prices`
 * were already seeded by Install::seed() (retail/wholesale/online, retail
 * is_default) before this task existed — this class is what reads and
 * writes them.
 *
 * §10.1 question 5 ("same prices online and in the shop?") answered
 * 2026-08-25: build all three groups; 'online' starts with NO overrides, so
 * online prices equal shop prices until someone explicitly sets one —
 * reversible per product, never a whole-catalogue re-price. See
 * docs/decisions.md.
 *
 * The 'online' group's overrides reach the storefront via
 * woocommerce_product_get_price — but that filter is GLOBAL, and
 * Stock\Catalog::reindex() ALSO calls $product->get_price() to build the
 * shared catalogue payload every register's terminal downloads. Left
 * unguarded, an online override would leak into the till's own catalogue.
 * without_online_override() is how reindex() opts itself out of the filter
 * for the one call that must never see it — the same suppression shape
 * Orders\Channel::without_refund_hook() already established in P4.1, for
 * the same reason: a global hook needs an explicit escape hatch for the
 * ONE caller it must never affect.
 */
class Groups {

	private static bool $suppress_online_override = false;

	public static function init(): void {
		add_filter( 'woocommerce_product_get_price', [ self::class, 'filter_online_price' ], 20, 2 );
		add_filter( 'woocommerce_product_variation_get_price', [ self::class, 'filter_online_price' ], 20, 2 );
	}

	public static function without_online_override( callable $fn ) {
		self::$suppress_online_override = true;
		try {
			return $fn();
		} finally {
			self::$suppress_online_override = false;
		}
	}

	/**
	 * Sale prices still win where WooCommerce says they should: is_on_sale()
	 * is WooCommerce's own answer to "is a discounted price active right
	 * now", computed from _regular_price/_sale_price/_date_on_sale_*
	 * independently of this filter — deferred to unconditionally, never
	 * second-guessed here.
	 */
	public static function filter_online_price( $price, $product ) {
		if ( self::$suppress_online_override || ! $product instanceof \WC_Product ) {
			return $price;
		}
		if ( $product->is_on_sale() ) {
			return $price;
		}
		$online = self::get_by_code( 'online' );
		if ( ! $online ) {
			return $price;
		}
		$is_variation = $product->is_type( 'variation' );
		$product_id   = $is_variation ? $product->get_parent_id() : $product->get_id();
		$variation_id = $is_variation ? $product->get_id() : 0;

		$override = self::price_for( $product_id, $variation_id, (int) $online['id'] );
		return null !== $override ? $override : $price;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'price_groups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$table = Install::table( 'price_groups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return $row ?: null;
	}

	public static function all( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'price_groups' );
		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id", $status ), ARRAY_A );
	}

	public static function default_group_id(): int {
		global $wpdb;
		$table = Install::table( 'price_groups' );
		return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 LIMIT 1" );
	}

	/**
	 * "The terminal's catalogue carries the register's group" — a register's
	 * own group lives in registers.settings_json (no dedicated column; this
	 * task's own Schema line lists only the two price_group tables), falling
	 * back to the default group for a register with none set.
	 */
	public static function group_for_register( int $register_id ): int {
		global $wpdb;
		$table    = Install::table( 'registers' );
		$settings = $wpdb->get_var( $wpdb->prepare( "SELECT settings_json FROM {$table} WHERE id = %d", $register_id ) );
		$decoded  = $settings ? json_decode( (string) $settings, true ) : null;
		$group_id = (int) ( $decoded['price_group_id'] ?? 0 );
		if ( $group_id > 0 && self::get( $group_id ) ) {
			return $group_id;
		}
		return self::default_group_id();
	}

	/** null = no override for this exact (group, product, variation). */
	public static function price_for( int $product_id, int $variation_id, int $group_id ): ?string {
		global $wpdb;
		$table = Install::table( 'price_group_prices' );
		$price = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT price FROM {$table} WHERE group_id = %d AND product_id = %d AND variation_id = %d",
				$group_id,
				$product_id,
				$variation_id
			)
		);
		return null !== $price ? wc_format_decimal( $price, 4 ) : null;
	}

	/**
	 * The price that applies AT THE TILL for a given register (and,
	 * optionally, an attached customer): the CUSTOMER's own group wins when
	 * they have one set — the plan's §2 decision, recorded in
	 * docs/decisions.md ("bill a standing customer as per their need"
	 * includes their own pricing, not just credit) — else the register's
	 * own group, else WooCommerce's own price with the online filter
	 * suppressed. The till must never see the online group's price bleed in
	 * just because no override exists for whichever group actually applies.
	 */
	public static function price_at_register( int $register_id, int $product_id, int $variation_id, int $customer_id = 0 ): string {
		$group_id = self::group_for_customer( $customer_id ) ?: self::group_for_register( $register_id );
		$override = self::price_for( $product_id, $variation_id, $group_id );
		if ( null !== $override ) {
			return $override;
		}
		$product = wc_get_product( $variation_id ?: $product_id );
		return $product ? self::without_online_override( static fn() => (string) $product->get_price() ) : '0.0000';
	}

	/**
	 * A standing customer's OWN price group, when one is set on them
	 * (`_cntr_price_group_id` user meta — same "WordPress user meta, not a
	 * new table" pattern `CustomerLedger::credit_limit()` already uses for
	 * `_cntr_credit_limit`). 0 if none is set, or the stored id no longer
	 * names a real group — price_at_register() then falls through to the
	 * register's own group exactly as it did before this existed.
	 */
	public static function group_for_customer( int $customer_id ): int {
		if ( $customer_id <= 0 ) {
			return 0;
		}
		$group_id = (int) get_user_meta( $customer_id, '_cntr_price_group_id', true );
		return $group_id > 0 && self::get( $group_id ) ? $group_id : 0;
	}

	/**
	 * Writing (or clearing) an override bumps the catalogue revision for
	 * this product — the terminal's own delta poll is what actually
	 * notices, whichever register's group needed it.
	 */
	public static function set_price( int $group_id, int $product_id, int $variation_id, string $price ): void {
		global $wpdb;
		$table = Install::table( 'price_group_prices' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (group_id, product_id, variation_id, price, updated_at) VALUES (%d, %d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE price = VALUES(price), updated_at = VALUES(updated_at)",
				$group_id,
				$product_id,
				$variation_id,
				wc_format_decimal( $price, 4 ),
				Db::now()
			)
		);
		self::bump_catalog( $product_id, $variation_id );
	}

	/**
	 * F4 (COUNTERFRONTEND.md) — every override row a group carries, for the
	 * till to apply client-side when a customer with that group attaches
	 * mid-cart. Bounded by the group's own override count (P4.3's "one
	 * override table on top of WooCommerce prices, not a pricing engine" —
	 * a shop hand-curates these, never thousands of rows), so one query with
	 * no paging is the right shape here, unlike Stock\Catalog's delta/cursor
	 * design for the whole product catalogue.
	 *
	 * @return array<int, array{product_id:int, variation_id:int, price:string}>
	 */
	public static function overrides_for_group( int $group_id ): array {
		if ( $group_id <= 0 ) {
			return [];
		}
		global $wpdb;
		$table = Install::table( 'price_group_prices' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT product_id, variation_id, price FROM {$table} WHERE group_id = %d", $group_id ),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => [
				'product_id'   => (int) $r['product_id'],
				'variation_id' => (int) $r['variation_id'],
				'price'        => wc_format_decimal( $r['price'], 4 ),
			],
			$rows
		);
	}

	public static function remove_price( int $group_id, int $product_id, int $variation_id ): void {
		global $wpdb;
		$table = Install::table( 'price_group_prices' );
		$wpdb->delete( $table, [ 'group_id' => $group_id, 'product_id' => $product_id, 'variation_id' => $variation_id ] );
		self::bump_catalog( $product_id, $variation_id );
	}

	private static function bump_catalog( int $product_id, int $variation_id ): void {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( $product ) {
			Catalog::reindex( $product );
		}
	}

	/**
	 * @return array{id:int}|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$name = trim( (string) ( $data['name'] ?? '' ) );
		$code = sanitize_key( (string) ( $data['code'] ?? '' ) );
		if ( '' === $name || '' === $code ) {
			return new \WP_Error( 'cntr_price_group_invalid', __( 'A price group needs both a name and a code.', 'counter' ), [ 'status' => 400 ] );
		}
		$table  = Install::table( 'price_groups' );
		$result = $wpdb->insert(
			$table,
			[
				'name'       => $name,
				'code'       => $code,
				'is_default' => ! empty( $data['is_default'] ) ? 1 : 0,
				'status'     => (string) ( $data['status'] ?? 'active' ),
			]
		);
		if ( false === $result || ! $wpdb->insert_id ) {
			return new \WP_Error( 'cntr_price_group_save_failed', $wpdb->last_error ?: __( 'Could not save this price group.', 'counter' ), [ 'status' => 500 ] );
		}
		return [ 'id' => (int) $wpdb->insert_id ];
	}
}
