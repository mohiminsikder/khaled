<?php
namespace Counter\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * GET /counter/v1/catalog?since=<cursor>&limit=500
 * -> { cursor, changed: [...], removed: [...], resnapshot: false }
 *
 * GET /counter/v1/catalog?since=<cursor>&limit=500&snapshot=1
 * -> the uncapped page a client pages through after a resnapshot response —
 * see Stock\Catalog::snapshot()'s own docblock.
 */
class Catalog {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/catalog',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_delta' ],
				'permission_callback' => Router::guard_any( [ 'cntr_use_pos', 'cntr_terminal_access' ] ),
				'args'                => [
					'since' => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v >= 0,
						'sanitize_callback' => 'absint',
					],
					'limit' => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 500,
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && $v > 0 && $v <= 2000,
						'sanitize_callback' => 'absint',
					],
					'snapshot' => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);
	}

	public static function get_delta( \WP_REST_Request $req ) {
		$since    = (int) $req->get_param( 'since' );
		$limit    = (int) $req->get_param( 'limit' );
		$snapshot = (bool) $req->get_param( 'snapshot' );

		$result = $snapshot
			? \Counter\Stock\Catalog::snapshot( $since, $limit )
			: \Counter\Stock\Catalog::delta( $since, $limit );

		// Pricing\Groups gap fix — Pricing\Groups::price_at_register() was
		// built and unit-tested (P4.3, test_price_groups()) but never
		// actually reached a real till: the shared catalogue index this
		// endpoint serves stores ONE global price per product (deliberately
		// — one row per product, not per register, keeps the cache and its
		// invalidation simple), so a register's configured price group has
		// never applied to anything a cashier actually saw or charged. This
		// endpoint is where that gets fixed, not the index itself: the
		// register's override is applied here, at delivery, on top of the
		// already-built rows, register_id read from the SAME X-CNTR-Register
		// header every other register-scoped route already uses. No header
		// (a request predating this fix, or a non-till caller) -> unchanged
		// behaviour, exactly as before.
		$register_id = (int) $req->get_header( 'X-CNTR-Register' );
		if ( $register_id > 0 && ! empty( $result['changed'] ) ) {
			$result['changed'] = self::apply_register_prices( $result['changed'], $register_id );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Overrides each row's 'price' with that register's own price-group
	 * price when one exists for that exact product/variation — leaves the
	 * row's already-computed base price alone otherwise, the same
	 * "null = no override" contract Pricing\Groups::price_for() already
	 * defines. One price_for() lookup per row, never a wc_get_product()
	 * hydration — the base price is already sitting in the row from
	 * Stock\Catalog::reindex(), so a register with no overrides at all
	 * costs nothing beyond the lookups themselves.
	 */
	private static function apply_register_prices( array $rows, int $register_id ): array {
		$group_id = \Counter\Pricing\Groups::group_for_register( $register_id );
		foreach ( $rows as &$row ) {
			$product_id   = (int) ( $row['parent_id'] ?? 0 ) ?: (int) ( $row['id'] ?? 0 );
			$variation_id = (int) ( $row['parent_id'] ?? 0 ) ? (int) ( $row['id'] ?? 0 ) : 0;
			$override     = \Counter\Pricing\Groups::price_for( $product_id, $variation_id, $group_id );
			if ( null !== $override ) {
				$row['price'] = $override;
			}
		}
		unset( $row );
		return $rows;
	}
}
