<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Admin\EntityPicker;
use Counter\Stock\Stocktake as StocktakeModel;
use Counter\Stock\Locations;
use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * C5 — the count sheet -> variance flow P2.9's own Direction describes.
 * Backend is none: Stocktake::open()/count_line()/close()/variance_sheet()
 * already do everything, including reading `expected` live at count time
 * (never cached from when the sheet opened) and writing the one 'stocktake'
 * ledger move only when a line's own count actually differs. This screen is
 * the count-sheet form and the variance display over them.
 */
class Stocktake {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Stocktake', 'counter' ),
			__( 'Stocktake', 'counter' ),
			'cntr_stocktake',
			'counter-stocktake',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_stocktake' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Stocktake', 'counter' ) . '</h1>';

		if ( 'new' === $view ) {
			self::render_new();
		} elseif ( 'count' === $view ) {
			self::render_count();
		} else {
			$table = new StocktakeListTable();
			$table->render_card_header();
			$table->prepare_items();
			$table->display();
		}
		echo '</div>';
	}

	private static function render_new(): void {
		if ( isset( $_POST['cntr_stocktake_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_stocktake_open' );
			$id = StocktakeModel::open(
				[
					'location_id' => absint( $_POST['location_id'] ?? 0 ),
					'note'        => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
					'user_id'     => get_current_user_id(),
				]
			);
			wp_safe_redirect( admin_url( 'admin.php?page=counter-stocktake&view=count&id=' . $id ) );
			exit;
		}

		$locations = Locations::all( 'active' );
		echo '<h2>' . esc_html__( 'Open a stocktake', 'counter' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cntr_stocktake_open' );
		echo '<input type="hidden" name="cntr_stocktake_action" value="open">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Location', 'counter' ) . '</th><td><select name="location_id">';
		foreach ( $locations as $l ) {
			printf( '<option value="%d">%s</option>', (int) $l['id'], esc_html( $l['name'] ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Note', 'counter' ) . '</th><td><input type="text" name="note" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Open', 'counter' ) . '</button></p>';
		echo '</form>';
	}

	private static function render_count(): void {
		$id        = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$stocktake = $id ? StocktakeModel::get( $id ) : null;
		if ( ! $stocktake ) {
			echo '<p>' . esc_html__( 'Stocktake not found.', 'counter' ) . '</p>';
			return;
		}

		if ( isset( $_POST['cntr_count_line'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_stocktake_count_' . $id );
			$result = StocktakeModel::count_line(
				$id,
				absint( $_POST['product_id'] ?? 0 ),
				absint( $_POST['variation_id'] ?? 0 ),
				sanitize_text_field( wp_unslash( $_POST['counted'] ?? '0' ) ),
				get_current_user_id()
			);
			if ( is_wp_error( $result ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result->get_error_message() ) );
			} else {
				printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html__( 'Counted.', 'counter' ) );
			}
		}

		if ( isset( $_POST['cntr_close_stocktake'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_stocktake_count_' . $id );
			$result = StocktakeModel::close( $id );
			if ( is_wp_error( $result ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result->get_error_message() ) );
			} else {
				$stocktake = StocktakeModel::get( $id );
			}
		}

		$location = Locations::get( (int) $stocktake['location_id'] );
		echo '<h2>' . esc_html( $stocktake['ref_no'] ) . ' — ' . esc_html( $location['name'] ?? '' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Status:', 'counter' ) . '</strong> ' . esc_html( $stocktake['status'] ) . '</p>';

		if ( 'open' === $stocktake['status'] ) {
			echo '<form method="post">';
			wp_nonce_field( 'cntr_stocktake_count_' . $id );
			echo '<table class="form-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Product', 'counter' ) . '</th><td>';
			EntityPicker::render( [ 'id' => 'cntr-st-product', 'hidden_name' => 'product_id', 'type' => 'product', 'placeholder' => __( 'Product name or SKU…', 'counter' ), 'required' => true ] );
			EntityPicker::render( [ 'id' => 'cntr-st-variation', 'hidden_name' => 'variation_id', 'type' => 'variation', 'parent_field' => 'cntr-st-product', 'placeholder' => __( 'Variation, if any…', 'counter' ) ] );
			echo '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Counted quantity', 'counter' ) . '</th><td><input type="text" name="counted" value="0" style="width:100px"></td></tr>';
			echo '</tbody></table>';
			echo '<p><button type="submit" name="cntr_count_line" value="1" class="button button-primary">' . esc_html__( 'Record count', 'counter' ) . '</button> ';
			echo '<button type="submit" name="cntr_close_stocktake" value="1" class="button" onclick="return confirm(' . esc_js( __( 'Close this stocktake? No more counts can be added afterward.', 'counter' ) ) . ');">' . esc_html__( 'Close stocktake', 'counter' ) . '</button></p>';
			echo '</form>';
		}

		$sheet = StocktakeModel::variance_sheet( $id );
		echo '<h3>' . esc_html__( 'Count sheet', 'counter' ) . '</h3>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Product', 'counter' ) . '</th><th>' . esc_html__( 'Expected', 'counter' ) . '</th><th>' . esc_html__( 'Counted', 'counter' ) . '</th><th>' . esc_html__( 'Variance', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $sheet['lines'] as $l ) {
			$product = wc_get_product( $l['variation_id'] ?: $l['product_id'] );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $product ? $product->get_name() : ( '#' . $l['product_id'] ) ),
				esc_html( $l['expected'] ),
				esc_html( $l['counted'] ),
				esc_html( $l['variance'] )
			);
		}
		echo '</tbody></table>';
		echo '<p><strong>' . esc_html__( 'Totals:', 'counter' ) . '</strong> ' . esc_html__( 'Expected', 'counter' ) . ' ' . esc_html( $sheet['total_expected'] )
			. ', ' . esc_html__( 'Counted', 'counter' ) . ' ' . esc_html( $sheet['total_counted'] )
			. ', ' . esc_html__( 'Variance', 'counter' ) . ' ' . esc_html( $sheet['total_variance'] ) . '</p>';
	}
}

class StocktakeListTable extends ListTable {

	private array $rows;

	public function __construct() {
		global $wpdb;
		$table      = Install::table( 'stocktakes' );
		$this->rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables, static SQL
		$this->add_url   = admin_url( 'admin.php?page=counter-stocktake&view=new' );
		$this->add_label = __( '+ New stocktake', 'counter' );
		parent::__construct( [ 'singular' => 'stocktake', 'plural' => 'stocktakes', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [ 'ref_no' => __( 'Ref', 'counter' ), 'location' => __( 'Location', 'counter' ), 'status' => __( 'Status', 'counter' ), 'opened_at' => __( 'Opened', 'counter' ), 'actions' => '' ];
	}

	public function get_filters(): array {
		return [];
	}

	public function export_rows(): array {
		return $this->rows;
	}

	public function export_columns(): array {
		$cols = $this->get_columns();
		unset( $cols['actions'] );
		return $cols;
	}

	public function prepare_items(): void {
		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $this->rows ), 'per_page' => $per_page ] );
		$this->items = array_slice( $this->rows, ( $page - 1 ) * $per_page, $per_page );
	}

	public function column_default( $item, $column_name ) {
		if ( 'location' === $column_name ) {
			$l = Locations::get( (int) $item['location_id'] );
			return esc_html( $l['name'] ?? '' );
		}
		if ( 'actions' === $column_name ) {
			return $this->build_row_actions(
				[ 'view' => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-stocktake&view=count&id=' . $item['id'] ) ) . '">' . esc_html__( 'Open', 'counter' ) . '</a>' ]
			);
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
