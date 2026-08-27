<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Admin\EntityPicker;
use Counter\Install;
use Counter\Stock\Locations;
use Counter\Stock\Batches;
use Counter\Reports\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * C2 — stock is managed without leaving Counter. Backend is none:
 * WooCommerce already IS the product master (Stock\Batches for cost,
 * Reports::reorder_list() for what's low, cntr_stock for per-location
 * balances) — this screen only reads and links what already exists.
 *
 * Four tabs, one shared row-building core per tab (query_products(),
 * variation_rows(), low_stock_rows()) so the self-test can assert against
 * exactly the same functions the screen itself renders from — no second,
 * screen-only definition of "low stock" or "per-location stock" to drift
 * from Reports.php's/Batches.php's own.
 */
class Products {

	const TABS = [
		'all'          => 'All products',
		'stock_report' => 'Stock report',
		'variations'   => 'Variations',
		'low_stock'    => 'Low stock',
	];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Products', 'counter' ),
			__( 'Products', 'counter' ),
			'cntr_manage_stock',
			'counter-products',
			[ self::class, 'render' ]
		);
	}

	// -- Testable cores -----------------------------------------------------------------

	/**
	 * @return array<int,string> location_id => qty, for exactly this
	 * product/variation — every ACTIVE location represented (0.0000 where
	 * no cntr_stock row exists yet), never just the locations that happen
	 * to have a row.
	 */
	public static function stock_by_location( int $product_id, int $variation_id ): array {
		global $wpdb;
		$table = Install::table( 'stock' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT location_id, qty FROM {$table} WHERE product_id = %d AND variation_id = %d", $product_id, $variation_id ),
			ARRAY_A
		);
		$by_location = [];
		foreach ( $rows as $r ) {
			$by_location[ (int) $r['location_id'] ] = wc_format_decimal( $r['qty'], 4 );
		}
		$out = [];
		foreach ( Locations::all( 'active' ) as $loc ) {
			$out[ (int) $loc['id'] ] = $by_location[ (int) $loc['id'] ] ?? '0.0000';
		}
		return $out;
	}

	private static function sum_location_maps( array $maps ): array {
		$totals = [];
		foreach ( $maps as $map ) {
			foreach ( $map as $loc_id => $qty ) {
				$totals[ $loc_id ] = bcadd( $totals[ $loc_id ] ?? '0.0000', $qty, 4 );
			}
		}
		return $totals;
	}

	private static function brand_for( \WC_Product $product ): string {
		$terms = get_the_terms( $product->get_id(), 'pa_brand' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * One row per real WooCommerce product (simple + variable — a variable
	 * product's own row shows the SUM of its children's stock; see
	 * variation_rows() for the per-variation breakdown the Variations tab
	 * needs). unit_cost is unset(), never zeroed, without cntr_view_cost —
	 * the same rule Reports.php's own gate_cost()/profit_and_loss() already
	 * use everywhere else in this plugin.
	 */
	public static function query_products( array $filters = [] ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		];
		$tax_query = [];
		if ( ! empty( $filters['category_id'] ) ) {
			$tax_query[] = [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => (int) $filters['category_id'] ];
		}
		if ( ! empty( $filters['type'] ) ) {
			$tax_query[] = [ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => (string) $filters['type'] ];
		}
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- admin list screen, not a hot path
		}
		if ( ! empty( $filters['s'] ) ) {
			$args['s'] = (string) $filters['s'];
		}
		$ids = get_posts( $args );

		$can_view_cost = current_user_can( 'cntr_view_cost' );
		$rows          = [];
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$is_variable  = $product->is_type( 'variable' );
			$stock_by_loc = $is_variable
				? self::sum_location_maps( array_map( static fn( $vid ) => self::stock_by_location( (int) $id, (int) $vid ), $product->get_children() ) )
				: self::stock_by_location( (int) $id, 0 );
			$total_stock  = '0.0000';
			foreach ( $stock_by_loc as $qty ) {
				$total_stock = bcadd( $total_stock, $qty, 4 );
			}

			$row = [
				'product_id'         => (int) $id,
				'image'              => $product->get_image_id() ? (string) wp_get_attachment_thumb_url( $product->get_image_id() ) : '',
				'sku'                => (string) $product->get_sku(),
				'name'               => $product->get_name(),
				'category'           => implode( ', ', wp_list_pluck( get_the_terms( $id, 'product_cat' ) ?: [], 'name' ) ),
				'brand'              => self::brand_for( $product ),
				'unit_cost'          => Batches::last_known_cost( (int) $id, 0 ),
				'selling_price'      => (string) $product->get_price(),
				'stock_by_location'  => $stock_by_loc,
				'total_stock'        => $total_stock,
				'type'               => $product->get_type(),
				'tax_class'          => $product->get_tax_class() ?: 'standard',
			];
			if ( ! $can_view_cost ) {
				unset( $row['unit_cost'] );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * @return array one row per variation of $parent_id, each with its OWN
	 * stock-per-location — the point of this whole method: a variable
	 * product's per-variation stock, never the parent's summed total
	 * query_products() shows.
	 */
	public static function variation_rows( int $parent_id ): array {
		$parent = wc_get_product( $parent_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return [];
		}
		$can_view_cost = current_user_can( 'cntr_view_cost' );
		$rows          = [];
		foreach ( $parent->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}
			$row = [
				'product_id'        => $parent_id,
				'variation_id'      => (int) $variation_id,
				'label'             => wc_get_formatted_variation( $variation, true, false ) ?: $variation->get_name(),
				'sku'               => (string) $variation->get_sku(),
				'unit_cost'         => Batches::last_known_cost( $parent_id, (int) $variation_id ),
				'selling_price'     => (string) $variation->get_price(),
				'stock_by_location' => self::stock_by_location( $parent_id, (int) $variation_id ),
			];
			if ( ! $can_view_cost ) {
				unset( $row['unit_cost'] );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Low stock — literally Reports::reorder_list(), enriched with name/sku
	 * purely for display. The underlying rows (and hence what counts as
	 * "low") are never recomputed a second way here, so this can never
	 * drift from that function's own definition — the self-test asserts
	 * this equality directly rather than assuming it.
	 */
	public static function low_stock_rows( array $filters = [] ): array {
		$rows = Reports::reorder_list( $filters );
		foreach ( $rows as &$row ) {
			$product           = wc_get_product( $row['variation_id'] ?: $row['product_id'] );
			$row['name']       = $product ? $product->get_name() : '';
			$row['sku']        = $product ? (string) $product->get_sku() : '';
			$location          = Locations::get( $row['location_id'] );
			$row['location_name'] = $location['name'] ?? '';
		}
		unset( $row );
		return $rows;
	}

	// -- Rendering ------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_stock' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'all';
		}

		$table = self::table_for_tab( $tab );
		$table->maybe_handle_export(); // exits if this request IS an export — before any HTML

		echo '<div class="wrap">';
		self::render_tabs( $tab );
		self::render_jump_to_product();
		$table->render_card_header();
		$table->render_filters_accordion();
		$table->prepare_items();
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( (string) ( $_GET['page'] ?? 'counter-products' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<input type="hidden" name="tab" value="%s">', esc_attr( $tab ) );
		$table->render_toolbar();
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	private static function table_for_tab( string $tab ): ListTable {
		return match ( $tab ) {
			'stock_report' => new StockReportListTable(),
			'variations'   => new VariationsListTable(),
			'low_stock'    => new LowStockListTable(),
			default        => new ProductsListTable( self::current_filters() ),
		};
	}

	private static function current_filters(): array {
		return [
			'category_id' => isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'type'        => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			's'           => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		];
	}

	private static function render_tabs( string $active ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$url = add_query_arg( [ 'page' => 'counter-products', 'tab' => $key ], admin_url( 'admin.php' ) );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$key === $active ? ' nav-tab-active' : '',
				esc_html( __( $label, 'counter' ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- self::TABS is a fixed, known set
			);
		}
		echo '</h2>';
	}

	/** "The picker resolves by name" — a quick jump straight to a product's own edit screen, the same Rest\EntitySearch 'product' type every other picker in this plugin already uses. */
	private static function render_jump_to_product(): void {
		echo '<div class="cntr-products-jump">';
		EntityPicker::render(
			[
				'id'          => 'cntr-products-jump',
				'hidden_name' => 'jump_product_id',
				'type'        => 'product',
				'placeholder' => __( 'Jump to a product by name or SKU…', 'counter' ),
			]
		);
		echo '</div>';
		?>
		<script>
		( function () {
			var hidden = document.getElementById( 'cntr-products-jump' );
			if ( ! hidden ) { return; }
			hidden.addEventListener( 'change', function () {
				if ( hidden.value ) {
					window.location.href = <?php echo wp_json_encode( admin_url( 'post.php?action=edit&post=' ) ); ?> + hidden.value;
				}
			} );
		} )();
		</script>
		<?php
	}
}

/** All products — image, SKU, name, category, brand, unit cost (gated), selling price, total stock, type, tax, row actions. */
class ProductsListTable extends ListTable {

	private array $rows;

	public function __construct( array $filters ) {
		$this->rows       = Products::query_products( $filters );
		$this->add_url    = admin_url( 'post-new.php?post_type=product' );
		$this->add_label  = __( '+ Add product', 'counter' );
		parent::__construct( [ 'singular' => 'product', 'plural' => 'products', 'ajax' => false ] );
	}

	public function get_columns(): array {
		$cols = [
			'image'    => '',
			'sku'      => __( 'SKU', 'counter' ),
			'name'     => __( 'Name', 'counter' ),
			'category' => __( 'Category', 'counter' ),
			'brand'    => __( 'Brand', 'counter' ),
		];
		if ( current_user_can( 'cntr_view_cost' ) ) {
			$cols['unit_cost'] = __( 'Unit cost', 'counter' );
		}
		$cols['selling_price'] = __( 'Selling price', 'counter' );
		$cols['total_stock']   = __( 'Stock', 'counter' );
		$cols['type']          = __( 'Type', 'counter' );
		$cols['tax_class']     = __( 'Tax', 'counter' );
		$cols['actions']       = '';
		return $cols;
	}

	public function get_filters(): array {
		$terms   = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$options = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$options[ $t->term_id ] = $t->name;
			}
		}
		return [
			'category_id' => [ 'label' => __( 'Category', 'counter' ), 'type' => 'select', 'options' => $options ],
			'type'        => [
				'label'   => __( 'Type', 'counter' ),
				'type'    => 'select',
				'options' => [ 'simple' => __( 'Simple', 'counter' ), 'variable' => __( 'Variable', 'counter' ) ],
			],
			's'           => [ 'label' => __( 'Search', 'counter' ), 'type' => 'text' ],
		];
	}

	public function export_rows(): array {
		return $this->rows;
	}

	public function export_columns(): array {
		$cols = $this->get_columns();
		unset( $cols['image'], $cols['actions'] );
		return $cols;
	}

	public function prepare_items(): void {
		$rows    = $this->rows;
		$orderby = (string) ( $_REQUEST['orderby'] ?? 'name' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort state
		$order   = (string) ( $_REQUEST['order'] ?? 'asc' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$cmp = strnatcasecmp( (string) ( $a[ $orderby ] ?? '' ), (string) ( $b[ $orderby ] ?? '' ) );
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);
		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $rows ), 'per_page' => $per_page ] );
		$this->items          = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		$this->_column_headers = [ $this->get_columns(), [], [ 'name' => [ 'name', false ] ] ];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'image':
				return $item['image'] ? '<img src="' . esc_url( $item['image'] ) . '" width="32" height="32" alt="">' : '';
			case 'unit_cost':
			case 'selling_price':
				return isset( $item[ $column_name ] ) ? wc_price( $item[ $column_name ] ) : '';
			case 'actions':
				return $this->build_row_actions(
					[
						'edit' => '<a href="' . esc_url( (string) get_edit_post_link( $item['product_id'] ) ) . '">' . esc_html__( 'Edit', 'counter' ) . '</a>',
						'view' => '<a href="' . esc_url( (string) get_permalink( $item['product_id'] ) ) . '" target="_blank">' . esc_html__( 'View', 'counter' ) . '</a>',
					]
				);
			default:
				return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
		}
	}
}

/** Stock report — one column per active location, plus a total. */
class StockReportListTable extends ListTable {

	private array $rows;
	private array $locations;

	public function __construct() {
		$this->locations = Locations::all( 'active' );
		$this->rows      = Products::query_products();
		parent::__construct( [ 'singular' => 'product', 'plural' => 'stock report', 'ajax' => false ] );
	}

	public function get_columns(): array {
		$cols = [ 'sku' => __( 'SKU', 'counter' ), 'name' => __( 'Name', 'counter' ) ];
		foreach ( $this->locations as $loc ) {
			$cols[ 'loc_' . $loc['id'] ] = $loc['name'];
		}
		$cols['total_stock'] = __( 'Total', 'counter' );
		return $cols;
	}

	public function get_filters(): array {
		return [];
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
		if ( str_starts_with( $column_name, 'loc_' ) ) {
			$loc_id = (int) substr( $column_name, 4 );
			return esc_html( (string) ( $item['stock_by_location'][ $loc_id ] ?? '0.0000' ) );
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}

/** Variations — every variation of every variable product, each with its OWN stock (never the parent's summed total). */
class VariationsListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$parent_ids = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => [ 'publish', 'private' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => [ [ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'variable' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			]
		);
		$this->rows = [];
		foreach ( $parent_ids as $pid ) {
			foreach ( Products::variation_rows( (int) $pid ) as $row ) {
				$row['parent_name'] = get_the_title( $pid );
				$this->rows[]       = $row;
			}
		}
		parent::__construct( [ 'singular' => 'variation', 'plural' => 'variations', 'ajax' => false ] );
	}

	public function get_columns(): array {
		$cols = [ 'parent_name' => __( 'Product', 'counter' ), 'label' => __( 'Variation', 'counter' ), 'sku' => __( 'SKU', 'counter' ) ];
		if ( current_user_can( 'cntr_view_cost' ) ) {
			$cols['unit_cost'] = __( 'Unit cost', 'counter' );
		}
		$cols['selling_price'] = __( 'Selling price', 'counter' );
		$cols['stock']         = __( 'Stock', 'counter' );
		return $cols;
	}

	public function get_filters(): array {
		return [];
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
		if ( 'stock' === $column_name ) {
			$sum = '0.0000';
			foreach ( $item['stock_by_location'] as $qty ) {
				$sum = bcadd( $sum, $qty, 4 );
			}
			return esc_html( $sum );
		}
		if ( 'unit_cost' === $column_name || 'selling_price' === $column_name ) {
			return isset( $item[ $column_name ] ) ? wc_price( $item[ $column_name ] ) : '';
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}

/** Low stock — Products::low_stock_rows(), which is Reports::reorder_list() verbatim plus display fields. */
class LowStockListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$this->rows = Products::low_stock_rows();
		parent::__construct( [ 'singular' => 'low stock item', 'plural' => 'low stock', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'sku'           => __( 'SKU', 'counter' ),
			'name'          => __( 'Name', 'counter' ),
			'location_name' => __( 'Location', 'counter' ),
			'qty'           => __( 'On hand', 'counter' ),
			'threshold'     => __( 'Alert at', 'counter' ),
		];
	}

	public function get_filters(): array {
		$locations = [];
		foreach ( Locations::all( 'active' ) as $loc ) {
			$locations[ $loc['id'] ] = $loc['name'];
		}
		return [ 'location_id' => [ 'label' => __( 'Location', 'counter' ), 'type' => 'select', 'options' => $locations ] ];
	}

	public function export_rows(): array {
		return $this->rows;
	}

	public function export_columns(): array {
		return $this->get_columns();
	}

	public function prepare_items(): void {
		$location_id = isset( $_REQUEST['location_id'] ) ? absint( $_REQUEST['location_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows        = $location_id ? Products::low_stock_rows( [ 'location_id' => $location_id ] ) : $this->rows;

		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $rows ), 'per_page' => $per_page ] );
		$this->items = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
	}
}
