<?php
namespace Counter\Stock;

use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * P2.12 — the same product sells as Piece, Dozen or Box, and the ledger stays
 * in base units throughout. "Missing from WooCommerce entirely... not a
 * display nicety" (Direction).
 *
 * cntr_units holds every unit, base and sub, in one table: a base unit has
 * base_unit_id = 0 and multiplier = 1.0000; a sub-unit's base_unit_id points
 * at its base unit's own row and multiplier says how many base units it is
 * worth (Dozen -> Piece, multiplier 12). allow_decimal distinguishes কেজি
 * (yes) from পিছ (no).
 *
 * THE one rule that makes this safe: Entity, Ledger and Channel never see a
 * sub-unit at all. This class's only two writers of that fact are
 * to_base_qty() (converts and refuses a fractional quantity a whole-count
 * unit can't represent) and resolve_price() (NULL product_units.price means
 * base price × multiplier, an explicit price overrides it) — both called
 * exactly once, from Orders\Builder, before an order line's quantity is set
 * to anything the rest of the system will ever read.
 */
class Units {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'units' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function all( string $status = 'active' ): array {
		global $wpdb;
		$table = Install::table( 'units' );
		if ( '' === $status ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name", $status ), ARRAY_A );
	}

	/**
	 * @return int|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$code = trim( (string) ( $data['code'] ?? '' ) );
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $code || '' === $name ) {
			return new \WP_Error( 'cntr_unit_missing_fields', __( 'A unit needs at least a code and a name.', 'counter' ), [ 'status' => 400 ] );
		}

		$table = Install::table( 'units' );
		$wpdb->insert(
			$table,
			[
				'name'          => $name,
				'name_bn'       => (string) ( $data['name_bn'] ?? '' ),
				'code'          => $code,
				'base_unit_id'  => (int) ( $data['base_unit_id'] ?? 0 ),
				'multiplier'    => wc_format_decimal( $data['multiplier'] ?? '1', 4 ),
				'allow_decimal' => empty( $data['allow_decimal'] ) ? 0 : 1,
				'status'        => $data['status'] ?? 'active',
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Every unit a product sells in, joined with cntr_units, price resolved
	 * (see resolve_price()). This is what feeds the catalogue payload's
	 * 'units' list (Stock\Catalog::reindex()) and the terminal's own unit
	 * selector.
	 */
	public static function for_product( int $product_id, int $variation_id = 0 ): array {
		global $wpdb;
		$pu_table = Install::table( 'product_units' );
		$u_table  = Install::table( 'units' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pu.unit_id, pu.price, pu.is_default, u.name, u.name_bn, u.code, u.multiplier, u.allow_decimal
				 FROM {$pu_table} pu JOIN {$u_table} u ON u.id = pu.unit_id
				 WHERE pu.product_id = %d AND pu.variation_id = %d
				 ORDER BY pu.is_default DESC, u.multiplier ASC",
				$product_id,
				$variation_id
			),
			ARRAY_A
		);

		$product = wc_get_product( $variation_id ?: $product_id );
		$out     = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'unit_id'       => (int) $row['unit_id'],
				'code'          => $row['code'],
				'name'          => $row['name'],
				'name_bn'       => $row['name_bn'],
				'multiplier'    => wc_format_decimal( $row['multiplier'], 4 ),
				'allow_decimal' => (bool) $row['allow_decimal'],
				'is_default'    => (bool) $row['is_default'],
				'price'         => self::resolve_price( $product_id, $variation_id, (int) $row['unit_id'], $row['price'], $product ),
			];
		}
		return $out;
	}

	/** Replaces the full set of units a product sells in with exactly $units: [ [unit_id, price, is_default], ... ]. */
	public static function set_product_units( int $product_id, int $variation_id, array $units ): void {
		global $wpdb;
		$table = Install::table( 'product_units' );

		$wpdb->delete( $table, [ 'product_id' => $product_id, 'variation_id' => $variation_id ] );
		foreach ( $units as $u ) {
			$wpdb->insert(
				$table,
				[
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'unit_id'      => (int) $u['unit_id'],
					'price'        => isset( $u['price'] ) && '' !== $u['price'] ? wc_format_decimal( $u['price'], 4 ) : null,
					'is_default'   => empty( $u['is_default'] ) ? 0 : 1,
				]
			);
		}
	}

	/**
	 * A NULL/absent explicit price resolves to base price × multiplier — how
	 * a product sold at ৳10/Piece prices a Dozen at ৳120 with nobody having
	 * to type that in. An explicit price overrides it outright: how a shop
	 * discounts a whole box rather than just multiplying the piece price.
	 */
	public static function resolve_price( int $product_id, int $variation_id, int $unit_id, $explicit_price = null, ?\WC_Product $product = null ): string {
		if ( null !== $explicit_price && '' !== $explicit_price ) {
			return wc_format_decimal( $explicit_price, 4 );
		}
		$product = $product ?: wc_get_product( $variation_id ?: $product_id );
		$unit    = self::get( $unit_id );
		$base    = $product ? wc_format_decimal( $product->get_price(), 4 ) : '0.0000';
		$mult    = $unit ? wc_format_decimal( $unit['multiplier'], 4 ) : '1.0000';
		return bcmul( $base, $mult, 4 );
	}

	/**
	 * Converts a quantity ENTERED in $unit_id to base units — qty × the
	 * unit's own multiplier. Refuses (WP_Error, converts nothing) a
	 * fractional quantity against a unit with allow_decimal = 0: a পিছ
	 * unit has no meaning for "1.5 of them."
	 *
	 * @return string|\WP_Error
	 */
	public static function to_base_qty( int $unit_id, string $qty ) {
		$unit = self::get( $unit_id );
		if ( ! $unit ) {
			return new \WP_Error( 'cntr_unit_missing', __( 'Unknown unit.', 'counter' ), [ 'status' => 400 ] );
		}

		$qty = wc_format_decimal( $qty, 4 );
		if ( bccomp( $qty, '0', 4 ) <= 0 ) {
			return new \WP_Error( 'cntr_unit_bad_qty', __( 'Quantity must be greater than zero.', 'counter' ), [ 'status' => 400 ] );
		}

		if ( ! (int) $unit['allow_decimal'] ) {
			$truncated = bcadd( $qty, '0', 0 ); // integer part only
			if ( 0 !== bccomp( $qty, $truncated, 4 ) ) {
				return new \WP_Error(
					'cntr_unit_no_decimal',
					sprintf(
						/* translators: %s: the unit's own name */
						__( '%s cannot be sold in a fractional quantity.', 'counter' ),
						$unit['name']
					),
					[ 'status' => 400 ]
				);
			}
		}

		return bcmul( $qty, wc_format_decimal( $unit['multiplier'], 4 ), 4 );
	}
}
