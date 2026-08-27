<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Stock\Batches as BatchesModel;
use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * C5 — browse by lot and expiry, with a near-expiry filter. Backend is
 * (almost) none: Batches::browse() (this task's one small addition — no
 * existing method exposed "every batch", only purpose-built filters like
 * near_expiry()) is a plain SELECT, the same shape as the rest of that
 * class; near_expiry's own <= boundary rule is reused verbatim through
 * the 'near_expiry_days' filter, never redefined here.
 */
class Batches {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Batches', 'counter' ),
			__( 'Batches', 'counter' ),
			'cntr_manage_stock',
			'counter-batches',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_stock' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$table = new BatchesListTable();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Batches', 'counter' ) . '</h1>';
		$table->maybe_handle_export();
		$table->render_card_header();
		$table->render_filters_accordion();
		$table->prepare_items();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="counter-batches">';
		$table->render_toolbar();
		$table->display();
		echo '</form>';
		echo '</div>';
	}
}

class BatchesListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$this->rows = BatchesModel::browse( $this->pull_filters() );
		parent::__construct( [ 'singular' => 'batch', 'plural' => 'batches', 'ajax' => false ] );
	}

	private function pull_filters(): array {
		return [
			'location_id'      => isset( $_GET['location_id'] ) ? absint( $_GET['location_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state
			'lot_no'           => isset( $_GET['lot_no'] ) ? sanitize_text_field( wp_unslash( $_GET['lot_no'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'near_expiry_days' => isset( $_GET['near_expiry_days'] ) ? absint( $_GET['near_expiry_days'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		];
	}

	public function filter_values(): array {
		$f = $this->pull_filters();
		return array_map( 'strval', $f );
	}

	public function get_columns(): array {
		return [
			'product'      => __( 'Product', 'counter' ),
			'location'     => __( 'Location', 'counter' ),
			'lot_no'       => __( 'Lot', 'counter' ),
			'expiry_date'  => __( 'Expiry', 'counter' ),
			'qty_remaining'=> __( 'Qty remaining', 'counter' ),
			'unit_cost'    => __( 'Unit cost', 'counter' ),
		];
	}

	public function get_filters(): array {
		$locations = [];
		foreach ( Locations::all( 'active' ) as $l ) {
			$locations[ $l['id'] ] = $l['name'];
		}
		return [
			'location_id'      => [ 'label' => __( 'Location', 'counter' ), 'type' => 'select', 'options' => $locations ],
			'lot_no'           => [ 'label' => __( 'Lot contains', 'counter' ), 'type' => 'text' ],
			'near_expiry_days' => [ 'label' => __( 'Expiring within (days)', 'counter' ), 'type' => 'text' ],
		];
	}

	public function export_rows(): array {
		return $this->rows;
	}

	public function export_columns(): array {
		return $this->get_columns();
	}

	public function prepare_items(): void {
		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $this->rows ), 'per_page' => $per_page ] );
		$this->items = array_slice( $this->rows, ( $page - 1 ) * $per_page, $per_page );
	}

	public function column_default( $item, $column_name ) {
		if ( 'product' === $column_name ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			return esc_html( $product ? $product->get_name() : ( '#' . $item['product_id'] ) );
		}
		if ( 'location' === $column_name ) {
			$l = Locations::get( (int) $item['location_id'] );
			return esc_html( $l['name'] ?? '' );
		}
		if ( 'unit_cost' === $column_name ) {
			return wc_price( $item['unit_cost'] );
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
