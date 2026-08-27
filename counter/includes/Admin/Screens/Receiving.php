<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Install;
use Counter\Purchasing\Orders;
use Counter\Purchasing\Receiving as ReceivingModel;
use Counter\Purchasing\Suppliers as SuppliersModel;

defined( 'ABSPATH' ) || exit;

/**
 * C3 — receiving a delivery against an open purchase order. Backend is
 * none: Purchasing\Receiving::receive() already validates outstanding qty
 * per line, creates the batch (lot/expiry — see the note below), posts the
 * one ledger move per line, and writes the supplier's bill — this screen
 * only collects "how much of each line arrived just now" and calls it.
 *
 * Two real gaps between this screen and a literal reading of the spec's own
 * flow ("scan-driven partial-receive with lot, expiry... then landed
 * cost... then post to stock"), found while reading Receiving::receive()
 * itself rather than assumed:
 *  - Lot number and expiry are fixed on the PURCHASE ORDER's own lines
 *    (Orders::create()) — receive() has no parameter for either and always
 *    copies whatever the PO line already has into the batch it creates.
 *    This screen shows them read-only rather than pretending they're
 *    editable here; changing a lot/expiry that was wrong at ordering time
 *    is out of scope for this task (would need a Receiving::receive()
 *    signature change, which "Backend: none" does not authorise).
 *  - Landed cost (shipping/other charges) is likewise fixed once, on the
 *    PO, at PurchaseOrders::handle_save() time — never entered here. This
 *    screen shows the already-computed landed unit cost per line, not an
 *    input for it.
 * "Then post to stock" is not a separate step either: Receiving::receive()
 * IS the post — Stock\Batches::receive() (called once per line inside it)
 * both creates the batch and writes the stock ledger move in the same call.
 */
class Receiving {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Receiving', 'counter' ),
			__( 'Receiving', 'counter' ),
			'cntr_manage_purchasing',
			'counter-receiving',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_purchasing' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Receiving', 'counter' ) . '</h1>';

		if ( 'receive' === $view ) {
			self::render_receive_view();
		} else {
			$table = new ReceivingListTable();
			$table->render_card_header();
			$table->prepare_items();
			$table->display();
		}
		echo '</div>';
	}

	private static function render_receive_view(): void {
		$po_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$po    = $po_id ? Orders::get( $po_id ) : null;
		if ( ! $po ) {
			echo '<p>' . esc_html__( 'Purchase order not found.', 'counter' ) . '</p>';
			return;
		}
		if ( 'closed' === $po['status'] ) {
			echo '<p>' . esc_html__( 'This purchase order is already fully received.', 'counter' ) . '</p>';
			return;
		}

		if ( isset( $_POST['cntr_receive_uuid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_receive_' . $po_id );
			$received = [];
			foreach ( (array) ( $_POST['qty_now'] ?? [] ) as $line_id => $qty ) {
				$qty = wc_format_decimal( sanitize_text_field( wp_unslash( $qty ) ), 4 );
				if ( bccomp( $qty, '0', 4 ) > 0 ) {
					$received[ absint( $line_id ) ] = $qty;
				}
			}
			$uuid   = sanitize_text_field( wp_unslash( $_POST['cntr_receive_uuid'] ) );
			$result = ReceivingModel::receive( $po_id, $uuid, $received, get_current_user_id() );

			if ( is_wp_error( $result ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result->get_error_message() ) );
			} else {
				self::render_result( $po, $result );
				return;
			}
		}

		$supplier = SuppliersModel::get( (int) $po['supplier_id'] );
		echo '<h2>' . sprintf( esc_html__( 'Receive against %s', 'counter' ), esc_html( $po['po_no'] ) ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Supplier:', 'counter' ) . '</strong> ' . esc_html( $supplier['name'] ?? '' ) . '</p>';

		echo '<form method="post">';
		wp_nonce_field( 'cntr_receive_' . $po_id );
		printf( '<input type="hidden" name="cntr_receive_uuid" value="%s">', esc_attr( wp_generate_uuid4() ) );

		echo '<table class="widefat"><thead><tr>';
		foreach (
			[
				__( 'Product', 'counter' ), __( 'SKU', 'counter' ), __( 'Lot', 'counter' ), __( 'Expiry', 'counter' ),
				__( 'Ordered', 'counter' ), __( 'Received so far', 'counter' ), __( 'Outstanding', 'counter' ),
				__( 'Landed unit cost', 'counter' ), __( 'Receive now', 'counter' ), __( 'Status', 'counter' ),
			] as $h
		) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( Orders::lines( $po_id ) as $l ) {
			$product         = wc_get_product( $l['variation_id'] ?: $l['product_id'] );
			$outstanding     = bcsub( $l['qty_ordered'], $l['qty_received'], 4 );
			$per_unit_landed = bccomp( $l['qty_ordered'], '0', 4 ) > 0 ? bcdiv( $l['landed_cost_share'], $l['qty_ordered'], 4 ) : '0.0000';
			$landed_cost     = bcadd( $l['unit_cost'], $per_unit_landed, 4 );
			$is_complete     = bccomp( $outstanding, '0', 4 ) <= 0;

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="cntr-recv-status" data-line="%d">%s</td></tr>',
				esc_html( $product ? $product->get_name() : ( '#' . $l['product_id'] ) ),
				esc_html( $product ? (string) $product->get_sku() : '' ),
				esc_html( $l['lot_no'] ),
				esc_html( (string) $l['expiry_date'] ),
				esc_html( $l['qty_ordered'] ),
				esc_html( $l['qty_received'] ),
				esc_html( $outstanding ),
				wc_price( $landed_cost ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$is_complete
					? '—'
					: '<input type="text" class="cntr-recv-qty" data-outstanding="' . esc_attr( $outstanding ) . '" name="qty_now[' . (int) $l['id'] . ']" value="' . esc_attr( $outstanding ) . '" style="width:80px">',
				(int) $l['id'],
				$is_complete ? esc_html__( 'Complete', 'counter' ) : esc_html__( 'Outstanding', 'counter' )
			);
		}
		echo '</tbody></table>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Post receiving', 'counter' ) . '</button></p>';
		echo '</form>';

		self::script();
	}

	private static function render_result( array $po, array $result ): void {
		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			sprintf(
				/* translators: 1: new PO status, 2: the supplier bill amount */
				esc_html__( 'Received. Purchase order is now %1$s. Supplier billed %2$s.', 'counter' ),
				esc_html( $result['status'] ),
				wp_strip_all_tags( wc_price( $result['bill_total'] ) )
			)
		);
		echo '<p>';
		printf(
			'<a class="button button-primary" href="%s">%s</a> ',
			esc_url( admin_url( 'admin.php?page=counter-labels&cntr_print_batch=po&po_id=' . $po['id'] ) ),
			esc_html__( 'Print labels for what arrived', 'counter' )
		);
		printf(
			'<a class="button" href="%s">%s</a> ',
			esc_url( admin_url( 'admin.php?page=counter-suppliers&view=ledger&id=' . $po['supplier_id'] ) ),
			esc_html__( 'Reconcile the supplier bill', 'counter' )
		);
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=counter-purchase-orders&view=view&id=' . $po['id'] ) ),
			esc_html__( 'View purchase order', 'counter' )
		);
		echo '</p>';
	}

	/** Live short/complete status as the operator types "receive now" — a read-time comparison, not a stored state (Purchasing has no per-line status column). */
	private static function script(): void {
		?>
		<script>
		( function () {
			document.querySelectorAll( '.cntr-recv-qty' ).forEach( function ( input ) {
				input.addEventListener( 'input', function () {
					var outstanding = parseFloat( input.dataset.outstanding ) || 0;
					var now = parseFloat( input.value ) || 0;
					var cell = input.closest( 'tr' ).querySelector( '.cntr-recv-status' );
					if ( now <= 0 ) { cell.textContent = 'Outstanding'; }
					else if ( now < outstanding ) { cell.textContent = 'Short'; }
					else { cell.textContent = 'Complete'; }
				} );
			} );
		} )();
		</script>
		<?php
	}
}

class ReceivingListTable extends ListTable {

	private array $rows;

	public function __construct() {
		global $wpdb;
		$table      = Install::table( 'purchase_orders' );
		$this->rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status IN (%s, %s) ORDER BY id DESC", 'ordered', 'partial' ),
			ARRAY_A
		);
		parent::__construct( [ 'singular' => 'open order', 'plural' => 'open orders', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'po_no'      => __( 'PO #', 'counter' ),
			'supplier'   => __( 'Supplier', 'counter' ),
			'status'     => __( 'Status', 'counter' ),
			'ordered_at' => __( 'Ordered', 'counter' ),
			'actions'    => '',
		];
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
		if ( 'supplier' === $column_name ) {
			$s = SuppliersModel::get( (int) $item['supplier_id'] );
			return esc_html( $s['name'] ?? '' );
		}
		if ( 'actions' === $column_name ) {
			return $this->build_row_actions(
				[
					'receive' => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-receiving&view=receive&id=' . $item['id'] ) ) . '">' . esc_html__( 'Receive', 'counter' ) . '</a>',
				]
			);
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
