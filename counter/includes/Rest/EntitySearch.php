<?php
namespace Counter\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * U4 (COUNTERFRONTEND.md) — the shared entity picker's only server-side
 * piece: read-only name search. Products can run into the thousands (P8.1's
 * own load test used 14,000) — too many to ever embed in a page the way a
 * handful of locations already are — this exists only for the entity types
 * large enough that typing-to-search is genuinely needed. Locations stay a
 * small, page-embedded list (Admin\EntityPicker's own "local" mode); this
 * route never sees a location request.
 */
class EntitySearch {

	const TYPES = [ 'product', 'variation' ];

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/entities/search',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'handle' ],
				// Adjust Stock (cntr_adjust_stock) was the only screen this route
				// served originally; Products (C2, cntr_manage_stock) is the
				// second, extending the list exactly as the original comment
				// here said a future consumer would.
				'permission_callback' => Router::guard_any( [ 'cntr_adjust_stock', 'cntr_manage_stock' ] ),
				'args'                => [
					'type'      => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => in_array( $v, self::TYPES, true ),
					],
					'q'         => [
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'parent_id' => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public static function handle( \WP_REST_Request $req ) {
		$type = (string) $req->get_param( 'type' );
		$q    = trim( (string) $req->get_param( 'q' ) );

		if ( 'variation' === $type ) {
			$parent_id = (int) $req->get_param( 'parent_id' );
			if ( $parent_id <= 0 ) {
				return new \WP_Error(
					'cntr_entity_search_no_parent',
					__( 'A parent product id is required to search variations.', 'counter' ),
					[ 'status' => 400 ]
				);
			}
			return rest_ensure_response( self::search_variations( $parent_id, $q ) );
		}

		return rest_ensure_response( self::search_products( $q ) );
	}

	/**
	 * Title match via get_posts()'s own 's' param plus a direct SKU LIKE —
	 * not wc_get_products( [ 'meta_query' => ... ] ), which F7 already found
	 * silently drops meta filters instead of applying them.
	 *
	 * @return array<int,array{id:int,label:string,sublabel:string}>
	 */
	public static function search_products( string $q, int $limit = 20 ): array {
		global $wpdb;

		$ids = [];

		if ( '' !== $q ) {
			$title_ids = get_posts(
				[
					'post_type'      => 'product',
					'post_status'    => [ 'publish', 'private' ],
					's'              => $q,
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
				]
			);

			$sku_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
					 WHERE p.post_type = 'product' AND p.post_status IN ('publish','private')
					 AND m.meta_value LIKE %s
					 ORDER BY p.post_title ASC LIMIT %d",
					'%' . $wpdb->esc_like( $q ) . '%',
					$limit
				)
			);

			foreach ( array_merge( $title_ids, $sku_ids ) as $id ) {
				$ids[ (int) $id ] = true;
			}
			$ids = array_slice( array_keys( $ids ), 0, $limit );
		} else {
			$ids = get_posts(
				[
					'post_type'      => 'product',
					'post_status'    => [ 'publish', 'private' ],
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
				]
			);
		}

		$out = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$out[] = [
				'id'       => (int) $id,
				'label'    => $product->get_name(),
				'sublabel' => $product->get_sku() ? $product->get_sku() : ( $product->is_type( 'variable' ) ? __( 'Has variations', 'counter' ) : '' ),
			];
		}
		return $out;
	}

	/**
	 * A variable product's own children list is never large enough to need
	 * paging (a handful to a few dozen), so this filters in PHP rather than
	 * adding a second SQL path — get_children() already loads them all.
	 *
	 * @return array<int,array{id:int,label:string,sublabel:string}>
	 */
	public static function search_variations( int $parent_id, string $q, int $limit = 50 ): array {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return [];
		}

		$needle = strtolower( $q );
		$out    = [];
		foreach ( $parent->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}
			$label = wc_get_formatted_variation( $variation, true, false );
			if ( '' === $label ) {
				$label = $variation->get_name();
			}
			$sku = (string) $variation->get_sku();

			if ( '' !== $needle && false === strpos( strtolower( $label . ' ' . $sku ), $needle ) ) {
				continue;
			}

			$out[] = [
				'id'       => (int) $variation_id,
				'label'    => $label,
				'sublabel' => $sku,
			];
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
