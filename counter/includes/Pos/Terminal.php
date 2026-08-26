<?php
namespace Counter\Pos;

use Counter\Db;
use Counter\Stock\Locations;
use Counter\Stock\Units;

defined( 'ABSPATH' ) || exit;

/**
 * /pos/ — its own rewrite rule and its own template, no theme, no admin bar.
 * A full-screen till that finds a product in under 5 ms and never waits on
 * the network to build a cart.
 */
class Terminal {

	public static function init(): void {
		add_action( 'init', [ self::class, 'add_rewrite' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_var' ] );
		add_action( 'template_redirect', [ self::class, 'maybe_render' ] );
		add_filter( 'cntr_is_pos_screen', [ self::class, 'is_pos_screen' ] );
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar' ] );
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/quick-add',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_quick_add' ],
				'permission_callback' => \Counter\Rest\Router::guard( 'cntr_manage_stock' ),
				'args'                => [
					'name'        => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_string( $v ) && '' !== trim( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'price'       => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (float) $v > 0,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'barcode'     => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'qty'         => [
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => static fn( $v ) => is_numeric( $v ),
						'sanitize_callback' => 'sanitize_text_field',
					],
					'location_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					// F7 (COUNTERFRONTEND.md) — the P2.12 multi-unit system
					// (Stock\Units) already existed with nothing that ever
					// wired a QUICK-ADDED product into it. Optional and
					// backward compatible: a caller that omits it (any
					// existing caller) gets exactly today's behaviour, no
					// unit configured.
					'unit_id'     => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public static function handle_quick_add( \WP_REST_Request $req ) {
		$location_id = (int) $req->get_param( 'location_id' ) ?: Locations::default_id();
		$result      = self::quick_add(
			[
				'name'    => (string) $req->get_param( 'name' ),
				'price'   => (string) $req->get_param( 'price' ),
				'barcode' => (string) $req->get_param( 'barcode' ),
				'qty'     => (string) $req->get_param( 'qty' ),
				'unit_id' => (int) $req->get_param( 'unit_id' ),
			],
			$location_id,
			get_current_user_id()
		);

		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'cntr_quick_add_failed', $result['error'], [ 'status' => 422 ] );
		}
		return rest_ensure_response( $result );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^pos/?$', 'index.php?cntr_pos=1', 'top' );
	}

	public static function add_query_var( array $vars ): array {
		$vars[] = 'cntr_pos';
		return $vars;
	}

	public static function is_pos_screen( $default = false ) {
		return (bool) get_query_var( 'cntr_pos', false ) ?: $default;
	}

	public static function hide_admin_bar( $show ) {
		return self::is_pos_screen() ? false : $show;
	}

	public static function maybe_render(): void {
		if ( ! get_query_var( 'cntr_pos' ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! ( current_user_can( 'cntr_use_pos' ) || current_user_can( 'cntr_terminal_access' ) ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ), '', [ 'response' => 403 ] );
		}

		require CNTR_DIR . 'templates/pos.php';
		exit;
	}

	/**
	 * F5/F7 quick-add. Creates a REAL WooCommerce product through the CRUD and
	 * an 'opening' ledger move — never a shadow record. Flagged via
	 * _cntr_quick_added so Admin\Health's incomplete-product list (F7,
	 * COUNTERFRONTEND.md) can list products still missing category/tax
	 * class/cost.
	 */
	public static function quick_add( array $data, int $location_id, int $user_id ): array {
		$name  = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$price = wc_format_decimal( $data['price'] ?? '0', 4 );
		$qty   = wc_format_decimal( $data['qty'] ?? '0', 4 );

		if ( '' === $name || bccomp( $price, '0', 4 ) <= 0 ) {
			return [ 'error' => __( 'A name and a positive price are required.', 'counter' ) ];
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 ); // the ledger move below is the real source of truth
		$product->set_sku( sanitize_text_field( (string) ( $data['barcode'] ?? '' ) ) );
		$product->update_meta_data( '_cntr_quick_added', 1 );
		$product->save();
		$product_id = $product->get_id();

		// F7 — an invalid/omitted unit_id (0, or one that doesn't name a
		// real row) leaves the product with no configured unit at all,
		// exactly today's behaviour; Units::for_product() already returns []
		// for that case, and the terminal's own catalogue/receipt code
		// already treats an empty unit list as "sells in its own base unit."
		// No explicit per-unit price — resolve_price() derives base price ×
		// multiplier on its own, correct for the common Piece/multiplier-1 case.
		$unit_id = (int) ( $data['unit_id'] ?? 0 );
		if ( $unit_id > 0 && Units::get( $unit_id ) ) {
			Units::set_product_units( $product_id, 0, [ [ 'unit_id' => $unit_id, 'is_default' => true ] ] );
		}

		if ( bccomp( $qty, '0', 4 ) > 0 ) {
			Db::transaction(
				function () use ( $product_id, $location_id, $qty, $user_id ) {
					\Counter\Stock\Ledger::move(
						[
							'product_id'   => $product_id,
							'variation_id' => 0,
							'location_id'  => $location_id,
							'qty_delta'    => $qty,
							'reason'       => 'opening',
							'ref_type'     => 'quick_add',
							'ref_id'       => $product_id,
							'user_id'      => $user_id,
						]
					);
					\Counter\Stock\Publisher::publish( $product_id, 0 );
				}
			);
		}

		\Counter\Audit::log( 'quick_add_product', 'product', $product_id, null, [ 'name' => $name, 'price' => $price, 'qty' => $qty ], $user_id );

		return [ 'product_id' => $product_id ];
	}
}
