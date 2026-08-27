<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Admin\EntityPicker;
use Counter\Stock\Transfers as TransfersModel;
use Counter\Stock\Locations;
use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * C5 — moving stock between locations. Backend is none:
 * Stock\Transfers::create()/send()/receive() already validate and write the
 * two ledger moves per line (Invariant I) — this screen only collects lines
 * and calls them. Transfers::create()/send()/receive() throw plain PHP
 * exceptions rather than returning \WP_Error (unlike most of this plugin),
 * so every call here is wrapped accordingly.
 */
class Transfers {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Transfers', 'counter' ),
			__( 'Transfers', 'counter' ),
			'cntr_transfer_stock',
			'counter-transfers',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_transfer_stock' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Transfers', 'counter' ) . '</h1>';

		if ( 'new' === $view ) {
			self::render_new();
		} elseif ( 'view' === $view ) {
			self::render_view();
		} else {
			$table = new TransfersListTable();
			$table->render_card_header();
			$table->prepare_items();
			$table->display();
		}
		echo '</div>';
	}

	private static function render_new(): void {
		$notice = null;
		if ( isset( $_POST['cntr_transfer_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_transfer_save' );
			try {
				$lines = [];
				foreach ( (array) ( wp_unslash( $_POST['lines'] ?? [] ) ) as $l ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized per-field below
					$product_id = absint( $l['product_id'] ?? 0 );
					if ( ! $product_id ) {
						continue;
					}
					$lines[] = [
						'product_id'   => $product_id,
						'variation_id' => absint( $l['variation_id'] ?? 0 ),
						'qty'          => wc_format_decimal( sanitize_text_field( $l['qty'] ?? '0' ), 4 ),
					];
				}
				$transfer_id = TransfersModel::create(
					[
						'from_location_id' => absint( $_POST['from_location_id'] ?? 0 ),
						'to_location_id'   => absint( $_POST['to_location_id'] ?? 0 ),
						'note'             => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
						'user_id'          => get_current_user_id(),
						'lines'            => $lines,
					]
				);
				TransfersModel::send( $transfer_id, get_current_user_id() );
				wp_safe_redirect( admin_url( 'admin.php?page=counter-transfers&view=view&id=' . $transfer_id ) );
				exit;
			} catch ( \Throwable $e ) {
				$notice = $e->getMessage();
			}
		}

		if ( $notice ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice ) );
		}

		$locations = Locations::all( 'active' );
		echo '<h2>' . esc_html__( 'New transfer', 'counter' ) . '</h2>';
		echo '<form method="post" id="cntr-transfer-form">';
		wp_nonce_field( 'cntr_transfer_save' );
		echo '<input type="hidden" name="cntr_transfer_action" value="save">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'From', 'counter' ) . '</th><td><select name="from_location_id">';
		foreach ( $locations as $l ) {
			printf( '<option value="%d">%s</option>', (int) $l['id'], esc_html( $l['name'] ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'To', 'counter' ) . '</th><td><select name="to_location_id">';
		foreach ( $locations as $l ) {
			printf( '<option value="%d">%s</option>', (int) $l['id'], esc_html( $l['name'] ) );
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Note', 'counter' ) . '</th><td><input type="text" name="note" class="regular-text"></td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Lines', 'counter' ) . '</h3>';
		echo '<table class="widefat" id="cntr-transfer-lines"><thead><tr><th>' . esc_html__( 'Product', 'counter' ) . '</th><th>' . esc_html__( 'Qty', 'counter' ) . '</th><th></th></tr></thead><tbody></tbody></table>';
		echo '<p><button type="button" class="button" id="cntr-transfer-add-line">' . esc_html__( '+ Add line', 'counter' ) . '</button></p>';

		echo '<template id="cntr-transfer-line-template"><table><tbody><tr>';
		echo '<td>';
		EntityPicker::render( [ 'id' => 'cntr-transfer-product-__IDX__', 'hidden_name' => 'lines[__IDX__][product_id]', 'type' => 'product', 'placeholder' => __( 'Product name or SKU…', 'counter' ), 'required' => true ] );
		EntityPicker::render( [ 'id' => 'cntr-transfer-variation-__IDX__', 'hidden_name' => 'lines[__IDX__][variation_id]', 'type' => 'variation', 'parent_field' => 'cntr-transfer-product-__IDX__', 'placeholder' => __( 'Variation, if any…', 'counter' ) ] );
		echo '</td>';
		echo '<td><input type="text" name="lines[__IDX__][qty]" value="1" style="width:80px"></td>';
		echo '<td><button type="button" class="button-link-delete cntr-transfer-remove-line">&times;</button></td>';
		echo '</tr></tbody></table></template>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send transfer', 'counter' ) . '</button></p>';
		echo '</form>';

		self::script();
	}

	private static function render_view(): void {
		$transfer_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$transfer    = $transfer_id ? TransfersModel::get( $transfer_id ) : null;
		if ( ! $transfer ) {
			echo '<p>' . esc_html__( 'Transfer not found.', 'counter' ) . '</p>';
			return;
		}

		if ( isset( $_POST['cntr_receive_lines'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_transfer_receive_' . $transfer_id );
			try {
				$received = [];
				foreach ( (array) ( $_POST['qty_now'] ?? [] ) as $line_id => $qty ) {
					$qty = wc_format_decimal( sanitize_text_field( wp_unslash( $qty ) ), 4 );
					if ( bccomp( $qty, '0', 4 ) > 0 ) {
						$received[ absint( $line_id ) ] = $qty;
					}
				}
				TransfersModel::receive( $transfer_id, $received, get_current_user_id() );
				$transfer = TransfersModel::get( $transfer_id );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Received.', 'counter' ) . '</p></div>';
			} catch ( \Throwable $e ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			}
		}

		$from = Locations::get( (int) $transfer['from_location_id'] );
		$to   = Locations::get( (int) $transfer['to_location_id'] );
		echo '<h2>' . esc_html( $transfer['ref_no'] ) . '</h2>';
		echo '<p>' . esc_html( $from['name'] ?? '' ) . ' &rarr; ' . esc_html( $to['name'] ?? '' ) . ' &nbsp; <strong>' . esc_html__( 'Status:', 'counter' ) . '</strong> ' . esc_html( $transfer['status'] ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'cntr_transfer_receive_' . $transfer_id );
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Product', 'counter' ) . '</th><th>' . esc_html__( 'Sent', 'counter' ) . '</th><th>' . esc_html__( 'Received', 'counter' ) . '</th><th>' . esc_html__( 'Outstanding', 'counter' ) . '</th><th>' . esc_html__( 'Receive now', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( TransfersModel::lines( $transfer_id ) as $l ) {
			$product     = wc_get_product( $l['variation_id'] ?: $l['product_id'] );
			$outstanding = bcsub( $l['qty_sent'], $l['qty_received'], 4 );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $product ? $product->get_name() : ( '#' . $l['product_id'] ) ),
				esc_html( $l['qty_sent'] ),
				esc_html( $l['qty_received'] ),
				esc_html( $outstanding ),
				bccomp( $outstanding, '0', 4 ) > 0
					? '<input type="text" name="qty_now[' . (int) $l['id'] . ']" value="' . esc_attr( $outstanding ) . '" style="width:80px">'
					: '—'
			);
		}
		echo '</tbody></table>';
		if ( 'received' !== $transfer['status'] ) {
			echo '<p><button type="submit" name="cntr_receive_lines" value="1" class="button button-primary">' . esc_html__( 'Receive', 'counter' ) . '</button></p>';
		}
		echo '</form>';
	}

	private static function script(): void {
		?>
		<script>
		( function () {
			var tbody = document.querySelector( '#cntr-transfer-lines tbody' );
			var addBtn = document.getElementById( 'cntr-transfer-add-line' );
			var idx = 0;
			function newRow() {
				var tpl = document.getElementById( 'cntr-transfer-line-template' );
				var clone = tpl.content.cloneNode( true );
				var tr = clone.querySelector( 'tr' );
				tr.innerHTML = tr.innerHTML.split( '__IDX__' ).join( String( idx ) );
				tbody.appendChild( tr );
				if ( window.CNTR_EntityPicker ) { window.CNTR_EntityPicker.wireAll(); }
				idx++;
			}
			if ( addBtn ) { addBtn.addEventListener( 'click', newRow ); }
			if ( tbody ) {
				tbody.addEventListener( 'click', function ( e ) {
					if ( e.target.classList.contains( 'cntr-transfer-remove-line' ) ) { e.target.closest( 'tr' ).remove(); }
				} );
			}
			newRow();
		} )();
		</script>
		<?php
	}
}

class TransfersListTable extends ListTable {

	private array $rows;

	public function __construct() {
		global $wpdb;
		$table      = Install::table( 'transfers' );
		$this->rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables, static SQL
		$this->add_url   = admin_url( 'admin.php?page=counter-transfers&view=new' );
		$this->add_label = __( '+ New transfer', 'counter' );
		parent::__construct( [ 'singular' => 'transfer', 'plural' => 'transfers', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [ 'ref_no' => __( 'Ref', 'counter' ), 'from' => __( 'From', 'counter' ), 'to' => __( 'To', 'counter' ), 'status' => __( 'Status', 'counter' ), 'created_at' => __( 'Created', 'counter' ), 'actions' => '' ];
	}

	public function get_filters(): array {
		return [
			'status' => [
				'label'   => __( 'Status', 'counter' ),
				'type'    => 'select',
				'options' => [ 'draft' => __( 'Draft', 'counter' ), 'sent' => __( 'Sent', 'counter' ), 'partial' => __( 'Partial', 'counter' ), 'received' => __( 'Received', 'counter' ) ],
			],
		];
	}

	private function filtered(): array {
		$status = $this->filter_values()['status'] ?? '';
		return $status ? array_values( array_filter( $this->rows, static fn( $r ) => $r['status'] === $status ) ) : $this->rows;
	}

	public function export_rows(): array {
		return $this->filtered();
	}

	public function export_columns(): array {
		$cols = $this->get_columns();
		unset( $cols['actions'] );
		return $cols;
	}

	public function prepare_items(): void {
		$rows     = $this->filtered();
		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $rows ), 'per_page' => $per_page ] );
		$this->items = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
	}

	public function column_default( $item, $column_name ) {
		if ( 'from' === $column_name || 'to' === $column_name ) {
			$l = Locations::get( (int) $item[ $column_name . '_location_id' ] );
			return esc_html( $l['name'] ?? '' );
		}
		if ( 'actions' === $column_name ) {
			return $this->build_row_actions(
				[ 'view' => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-transfers&view=view&id=' . $item['id'] ) ) . '">' . esc_html__( 'View', 'counter' ) . '</a>' ]
			);
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
