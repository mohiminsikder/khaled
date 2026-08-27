<?php
namespace Counter\Rest;

use Counter\Docs\Labels as LabelsModel;

defined( 'ABSPATH' ) || exit;

/**
 * C8 — the one call the Label Designer's live preview makes: render a
 * NOT-YET-SAVED template against a real (or sample) product, every time an
 * operator changes a field box, a point size, a barcode type, the tax mode,
 * or the price group. build_preview() is the whole thing; the REST route is
 * a thin wrapper so pos.js-style fetch() can reach it — the same "what
 * prints is what is shown" principle as before, just reactive now instead of
 * only rendering after a page reload.
 */
class Labels {

	public static function init(): void {
		add_action( 'cntr_register_routes', [ self::class, 'register_routes' ] );
	}

	public static function register_routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/labels/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'preview' ],
				'permission_callback' => Router::guard( 'cntr_manage_templates' ),
			]
		);
	}

	public static function preview( \WP_REST_Request $req ) {
		$result = self::build_preview(
			[
				'page'           => (array) $req->get_param( 'page' ),
				'fields'         => (array) $req->get_param( 'fields' ),
				'product_id'     => absint( $req->get_param( 'product_id' ) ),
				'qty'            => max( 1, absint( $req->get_param( 'qty' ) ?: 1 ) ),
				'price_group_id' => absint( $req->get_param( 'price_group_id' ) ),
				'tax_mode'       => (string) ( $req->get_param( 'tax_mode' ) ?: 'exclusive' ),
				'barcode_type'   => (string) ( $req->get_param( 'barcode_type' ) ?: 'code128' ),
			]
		);
		return rest_ensure_response( $result );
	}

	/**
	 * @param array{page:array,fields:array,product_id:int,qty:int,price_group_id:int,tax_mode:string,barcode_type:string} $args
	 * @return array{html:string,sheet_count:int}
	 */
	public static function build_preview( array $args ): array {
		$page   = LabelsModel::normalize_page( $args['page'] ?? [] );
		$fields = LabelsModel::normalize_fields( $args['fields'] ?? [] );

		$sample_data = self::resolve_data(
			(int) ( $args['product_id'] ?? 0 ),
			(int) ( $args['price_group_id'] ?? 0 ),
			(string) ( $args['tax_mode'] ?? 'exclusive' ),
			(string) ( $args['barcode_type'] ?? 'code128' )
		);

		$html = LabelsModel::render_label( [ 'page' => $page, 'fields' => $fields ], $sample_data );

		$capacity    = max( 1, (int) $page['cols'] * (int) $page['rows'] );
		$qty         = max( 1, (int) ( $args['qty'] ?? 1 ) );
		$sheet_count = (int) ceil( $qty / $capacity );

		return [ 'html' => $html, 'sheet_count' => $sheet_count ];
	}

	/**
	 * A real product's own data when one is picked, resolved through the
	 * SAME price-group lookup and tax-mode arithmetic a real print would use
	 * — LabelDesigner::SAMPLE_DATA's placeholder values otherwise, so the
	 * preview is never blank before a product is chosen.
	 */
	private static function resolve_data( int $product_id, int $price_group_id, string $tax_mode, string $barcode_type ): array {
		if ( ! $product_id ) {
			$data                 = \Counter\Admin\Screens\LabelDesigner::SAMPLE_DATA;
			$data['barcode_type'] = $barcode_type;
			return $data;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$data                 = \Counter\Admin\Screens\LabelDesigner::SAMPLE_DATA;
			$data['barcode_type'] = $barcode_type;
			return $data;
		}

		$sku          = $product->get_sku();
		$base_price   = $price_group_id
			? ( \Counter\Pricing\Groups::price_for( $product_id, 0, $price_group_id ) ?? $product->get_price() )
			: $product->get_price();
		$base_mrp     = $product->get_regular_price();

		return [
			'name'          => $product->get_name(),
			'price'         => self::apply_tax_mode( (string) $base_price, $tax_mode ),
			'sku'           => $sku ?: ( '#' . $product_id ),
			'expiry'        => '',
			'mrp'           => self::apply_tax_mode( (string) $base_mrp, $tax_mode ),
			'barcode_value' => $sku ?: (string) $product_id,
			'barcode_type'  => $barcode_type,
		];
	}

	/**
	 * A product's own stored price is treated as tax-EXCLUSIVE (Counter has
	 * no other convention for this yet) — 'inclusive' adds vat.rate on top
	 * for display, 'exclusive' shows the stored value unchanged.
	 */
	private static function apply_tax_mode( string $price, string $tax_mode ): string {
		if ( '' === $price || ! is_numeric( $price ) ) {
			return $price;
		}
		if ( 'inclusive' !== $tax_mode ) {
			return wc_format_decimal( $price, 2 );
		}
		$rate = (float) \Counter\Settings::get( 'vat.rate', 0.0 );
		return wc_format_decimal( bcmul( $price, (string) ( 1 + $rate / 100 ), 6 ), 2 );
	}
}
