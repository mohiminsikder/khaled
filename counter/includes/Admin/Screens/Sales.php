<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Stock\Locations;
use Counter\Pos\Registers;
use Counter\Docs\Templates;
use Counter\Docs\Challan;
use Counter\Install;

defined( 'ABSPATH' ) || exit;

/**
 * C4 — every sale document, till or desk, in one place. Backend is none:
 * wc_get_orders() (HPOS-aware — this shop's orders do not live in wp_posts,
 * so a plain get_posts() query, fine for Products.php's own product query,
 * would silently miss every order here) plus the same _cntr_* order meta
 * every other screen already reads.
 *
 * Teardown defect #7: SuperShop's Sales Order list defaults to a date range
 * that silently excludes today's own orders. default_filters() here always
 * defaults to today; query_orders()/explain_empty() are the SAME functions
 * both the screen and test_sales_screen() call, so a filter combination
 * that returns nothing can always be traced back to the one filter actually
 * responsible, never left as an unexplained blank table.
 */
class Sales {

	const PAYMENT_STATUSES = [ 'paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid' ];
	const TENDER_METHODS   = [ 'cash', 'card', 'bkash', 'nagad', 'rocket', 'bank', 'credit' ];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'All Sales', 'counter' ),
			__( 'All Sales', 'counter' ),
			'cntr_view_all_sales',
			'counter-sales',
			[ self::class, 'render' ]
		);
	}

	/** Today, both ends — teardown defect #7's own fix, the one thing this function must never get wrong. */
	public static function default_filters(): array {
		$today = wp_date( 'Y-m-d' );
		return [
			'date_from'      => $today,
			'date_to'        => $today,
			'location_id'    => 0,
			'payment_status' => '',
			'cashier_id'     => 0,
			'method'         => '',
			'register_id'    => 0,
			'shipping'       => '',
			'customer'       => '',
		];
	}

	public static function row_from_order( \WC_Order $order ): array {
		$tenders_json = (string) $order->get_meta( '_cntr_tenders' );
		$tenders      = $tenders_json ? json_decode( $tenders_json, true ) : [];
		$net_tendered = '0.0000';
		$methods      = [];
		foreach ( (array) $tenders as $t ) {
			$amount = wc_format_decimal( $t['amount'] ?? 0, 4 );
			$net_tendered = ! empty( $t['is_change'] ) ? bcsub( $net_tendered, $amount, 4 ) : bcadd( $net_tendered, $amount, 4 );
			if ( empty( $t['is_change'] ) && ! empty( $t['method'] ) ) {
				$methods[] = (string) $t['method'];
			}
		}
		$total = wc_format_decimal( $order->get_total(), 4 );

		if ( $tenders ) {
			$due_raw        = bcsub( $total, $net_tendered, 4 );
			$due            = bccomp( $due_raw, '0', 4 ) > 0 ? $due_raw : '0.0000';
			$payment_status = bccomp( $due, '0', 4 ) <= 0 ? 'paid' : ( bccomp( $net_tendered, '0', 4 ) > 0 ? 'partial' : 'unpaid' );
		} else {
			$payment_status = $order->is_paid() ? 'paid' : 'unpaid';
			$due            = $order->is_paid() ? '0.0000' : $total;
		}

		$customer_id = (int) $order->get_customer_id();
		$name        = trim( $order->get_formatted_billing_full_name() );
		if ( '' === $name && $customer_id ) {
			$user = get_userdata( $customer_id );
			$name = $user ? $user->display_name : '';
		}

		return [
			'order_id'       => $order->get_id(),
			'date'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'invoice'        => (string) $order->get_meta( '_cntr_receipt_no' ) ?: ( '#' . $order->get_id() ),
			'customer'       => '' !== $name ? $name : __( 'Walk-in', 'counter' ),
			'customer_id'    => $customer_id,
			'total'          => $total,
			'payment_status' => $payment_status,
			'due'            => $due,
			'methods'        => $methods ?: array_filter( [ (string) $order->get_payment_method_title() ] ),
			'status'         => $order->get_status(),
			'shipping'       => wc_format_decimal( $order->get_shipping_total(), 4 ),
			'location_id'    => (int) $order->get_meta( '_cntr_location_id' ),
			'operator_id'    => (int) $order->get_meta( '_cntr_operator_id' ),
			'register_id'    => (int) $order->get_meta( '_cntr_register_id' ),
		];
	}

	/** The ONE query both the screen and the self-test use — a filter that zeroes the result set here zeroes it everywhere this is called from. */
	public static function query_orders( array $filters ): array {
		$filters = array_merge( self::default_filters(), $filters );

		$args = [
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'date_created' => $filters['date_from'] . '...' . $filters['date_to'],
		];
		$meta_query = [];
		if ( $filters['location_id'] ) {
			$meta_query[] = [ 'key' => '_cntr_location_id', 'value' => (int) $filters['location_id'] ];
		}
		if ( $filters['cashier_id'] ) {
			$meta_query[] = [ 'key' => '_cntr_operator_id', 'value' => (int) $filters['cashier_id'] ];
		}
		if ( $filters['register_id'] ) {
			$meta_query[] = [ 'key' => '_cntr_register_id', 'value' => (int) $filters['register_id'] ];
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list screen, not a hot path
		}

		$orders = wc_get_orders( $args );
		$rows   = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$row = self::row_from_order( $order );

			if ( '' !== $filters['payment_status'] && $row['payment_status'] !== $filters['payment_status'] ) {
				continue;
			}
			if ( '' !== $filters['method'] && ! in_array( $filters['method'], $row['methods'], true ) ) {
				continue;
			}
			if ( 'yes' === $filters['shipping'] && bccomp( $row['shipping'], '0', 4 ) <= 0 ) {
				continue;
			}
			if ( 'no' === $filters['shipping'] && bccomp( $row['shipping'], '0', 4 ) > 0 ) {
				continue;
			}
			if ( '' !== $filters['customer'] && false === stripos( $row['customer'], (string) $filters['customer'] ) ) {
				continue;
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * When $filters produces zero rows, names the ONE filter that — applied
	 * alone, against the same date range, with every other filter back at
	 * its unfiltered default — is already enough to zero the result on its
	 * own. Null means the combination is what's empty, not any single
	 * filter (e.g. a real quiet day, or two individually-fine filters that
	 * just happen not to overlap).
	 */
	public static function explain_empty( array $filters ): ?string {
		$filters = array_merge( self::default_filters(), $filters );
		$defaults = self::default_filters();

		foreach ( $filters as $key => $value ) {
			if ( in_array( $key, [ 'date_from', 'date_to' ], true ) ) {
				continue;
			}
			if ( $value === $defaults[ $key ] ) {
				continue;
			}
			$solo = $defaults;
			$solo['date_from'] = $filters['date_from'];
			$solo['date_to']   = $filters['date_to'];
			$solo[ $key ]      = $value;
			if ( empty( self::query_orders( $solo ) ) ) {
				return $key;
			}
		}
		return null;
	}

	/** Row actions, gated by the actual capabilities they need — a user missing one never sees that action at all, not a disabled/greyed one. */
	public static function row_actions_for( array $row ): array {
		$order_id = (int) $row['order_id'];
		$order    = wc_get_order( $order_id );
		$edit_url = $order ? $order->get_edit_order_url() : '';

		$actions = [
			'view'          => [ 'label' => __( 'View', 'counter' ), 'url' => $edit_url ],
			'edit'          => [ 'label' => __( 'Edit', 'counter' ), 'url' => $edit_url ],
			'print_invoice' => [ 'label' => __( 'Print invoice', 'counter' ), 'url' => self::print_url( 'invoice', $order_id ) ],
			'packing_slip'  => [ 'label' => __( 'Packing slip', 'counter' ), 'url' => self::print_url( 'packing-slip', $order_id ) ],
			'delivery_note' => [ 'label' => __( 'Delivery note', 'counter' ), 'url' => self::print_url( 'delivery-note', $order_id ) ],
			'view_payments' => [ 'label' => __( 'View payments', 'counter' ), 'url' => admin_url( 'admin.php?page=counter-sales&cntr_view=payments&order_id=' . $order_id ) ],
			'invoice_url'   => [ 'label' => __( 'Invoice URL', 'counter' ), 'url' => self::view_url( 'invoice', $order_id ) ],
		];
		if ( current_user_can( 'cntr_refund' ) ) {
			$actions['sell_return'] = [ 'label' => __( 'Sell return', 'counter' ), 'url' => $edit_url ];
		}
		if ( Challan::get_by_order( $order_id ) ) {
			$actions['challan'] = [ 'label' => __( 'Challan', 'counter' ), 'url' => admin_url( 'admin.php?page=counter-sales&cntr_view=challan&order_id=' . $order_id ) ];
		}
		return $actions;
	}

	private static function print_url( string $type, int $order_id ): string {
		return admin_url( 'admin.php?page=counter-sales&cntr_print=' . $type . '&order_id=' . $order_id );
	}

	private static function view_url( string $type, int $order_id ): string {
		return admin_url( 'admin.php?page=counter-sales&cntr_view=' . $type . '&order_id=' . $order_id );
	}

	// -- Rendering --------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'cntr_view_all_sales' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		if ( isset( $_GET['cntr_print'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only document render
			self::render_print( sanitize_key( wp_unslash( $_GET['cntr_print'] ) ), isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0, true );
			return;
		}
		if ( isset( $_GET['cntr_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
			$view_type = sanitize_key( wp_unslash( $_GET['cntr_view'] ) );
			$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
			if ( 'payments' === $view_type ) {
				self::render_payments_view( $order_id );
				return;
			}
			if ( 'challan' === $view_type ) {
				self::render_challan_view( $order_id );
				return;
			}
			self::render_print( $view_type, $order_id, false );
			return;
		}

		$table = new SalesListTable();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — All Sales', 'counter' ) . '</h1>';
		$table->maybe_handle_export();
		$table->render_card_header();
		$table->render_filters_accordion();
		$table->prepare_items();
		if ( empty( $table->items ) ) {
			$blame = self::explain_empty( $table->filter_values() );
			if ( $blame ) {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					sprintf(
						/* translators: %s: the filter label responsible for the empty result */
						esc_html__( 'No sales match — the "%s" filter is what\'s excluding them. Clear it to see more.', 'counter' ),
						esc_html( $blame )
					)
				);
			}
		}
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="counter-sales">';
		$table->render_toolbar();
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	private static function render_payments_view( int $order_id ): void {
		global $wpdb;
		$order = wc_get_order( $order_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Counter — Payments', 'counter' ) . '</h1>';
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'counter' ) . '</p></div>';
			return;
		}
		$table = Install::table( 'tenders' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id", $order_id ), ARRAY_A );
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Method', 'counter' ) . '</th><th>' . esc_html__( 'Amount', 'counter' ) . '</th><th>' . esc_html__( 'Change?', 'counter' ) . '</th><th>' . esc_html__( 'Refund?', 'counter' ) . '</th><th>' . esc_html__( 'Reference', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r['method'] ),
				wc_price( $r['amount'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$r['is_change'] ? esc_html__( 'Yes', 'counter' ) : esc_html__( 'No', 'counter' ),
				$r['refund_id'] > 0 ? esc_html__( 'Yes', 'counter' ) : esc_html__( 'No', 'counter' ),
				esc_html( $r['reference'] )
			);
		}
		echo '</tbody></table></div>';
	}

	private static function render_challan_view( int $order_id ): void {
		$order   = wc_get_order( $order_id );
		$challan = Challan::get_by_order( $order_id );
		echo '<div class="wrap"><h1>' . esc_html__( 'Counter — Challan', 'counter' ) . '</h1>';
		if ( ! $order || ! $challan ) {
			echo '<p>' . esc_html__( 'No challan exists for this order.', 'counter' ) . '</p></div>';
			return;
		}
		echo Challan::render_html( $challan, $order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Challan::render_html() escapes its own field values
		echo '</div>';
	}

	/**
	 * Invoice/packing-slip/delivery-note, via the SAME generic template
	 * engine invoice-a4 already used (Docs\Templates) — the two document
	 * types this task's row-action list names that Templates::TYPES didn't
	 * already include ('packing-slip-a4', 'delivery-note-a4') are added
	 * there, not reinvented here. No template row exists for ANY of these
	 * types on a fresh install yet (there is no screen anywhere in this
	 * plugin to create one — that is the Documents tab of C7's own Settings
	 * screen, not built yet), so a built-in fallback renders correctly even
	 * before one is ever configured; Templates::find() is preferred the
	 * moment a real row exists.
	 */
	private static function render_print( string $type, int $order_id, bool $auto_print ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'counter' ) );
		}
		$template_type = [ 'invoice' => 'invoice-a4', 'packing-slip' => 'packing-slip-a4', 'delivery-note' => 'delivery-note-a4' ][ $type ] ?? '';
		if ( '' === $template_type ) {
			wp_die( esc_html__( 'Unknown document type.', 'counter' ) );
		}

		$template = Templates::find( $template_type );
		$html     = $template ? Templates::render( $template, self::template_data( $order, $template_type ) ) : self::fallback_html( $template_type, $order );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $template_type ) . '</title></head><body>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Templates::render()/fallback_html() escape every field value themselves
		if ( $auto_print ) {
			echo '<script>window.onload = function () { window.print(); };</script>';
		}
		echo '</body></html>';
	}

	private static function template_data( \WC_Order $order, string $template_type ): array {
		$items_html = '<table style="width:100%">';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$items_html .= '<tr><td>' . esc_html( $item->get_name() ) . '</td><td>' . esc_html( (string) $item->get_quantity() ) . '</td><td>' . esc_html( (string) $item->get_total() ) . '</td></tr>';
		}
		$items_html .= '</table>';

		return [
			'shop_name'     => (string) \Counter\Settings::get( 'shop.name' ),
			'shop_address'  => (string) \Counter\Settings::get( 'shop.address' ),
			'shop_phone'    => (string) \Counter\Settings::get( 'shop.phone' ),
			'shop_bin'      => (string) \Counter\Settings::get( 'shop.bin' ),
			'receipt_no'    => (string) $order->get_meta( '_cntr_receipt_no' ) ?: ( '#' . $order->get_id() ),
			'date'          => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
			'customer_name' => trim( $order->get_formatted_billing_full_name() ) ?: __( 'Walk-in', 'counter' ),
			'items_html'    => $items_html,
			'subtotal'      => (string) $order->get_subtotal(),
			'tax'           => (string) $order->get_total_tax(),
			'total'         => (string) $order->get_total(),
			'footer_text'   => (string) \Counter\Settings::get( 'shop.footer_text' ),
		];
	}

	private static function fallback_html( string $template_type, \WC_Order $order ): string {
		$data  = self::template_data( $order, $template_type );
		$title = [ 'invoice-a4' => __( 'Invoice', 'counter' ), 'packing-slip-a4' => __( 'Packing Slip', 'counter' ), 'delivery-note-a4' => __( 'Delivery Note', 'counter' ) ][ $template_type ] ?? '';
		$show_prices = 'packing-slip-a4' !== $template_type; // a packing slip is what a warehouse hand checks against — never prices

		$out  = '<div style="width:210mm;box-sizing:border-box;padding:10mm;font-family:sans-serif;">';
		$out .= '<h2>' . esc_html( $data['shop_name'] ) . '</h2>';
		$out .= '<p>' . esc_html( $data['shop_address'] ) . ' &nbsp; ' . esc_html( $data['shop_phone'] ) . '</p>';
		$out .= '<h3>' . esc_html( $title ) . ' — ' . esc_html( $data['receipt_no'] ) . '</h3>';
		$out .= '<p>' . esc_html__( 'Date:', 'counter' ) . ' ' . esc_html( $data['date'] ) . ' &nbsp; ' . esc_html__( 'Customer:', 'counter' ) . ' ' . esc_html( $data['customer_name'] ) . '</p>';
		$out .= '<table style="width:100%;border-collapse:collapse;" border="1" cellpadding="4">';
		$out .= '<tr><th>' . esc_html__( 'Item', 'counter' ) . '</th><th>' . esc_html__( 'Qty', 'counter' ) . '</th>' . ( $show_prices ? '<th>' . esc_html__( 'Total', 'counter' ) . '</th>' : '' ) . '</tr>';
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$out .= '<tr><td>' . esc_html( $item->get_name() ) . '</td><td>' . esc_html( (string) $item->get_quantity() ) . '</td>' . ( $show_prices ? '<td>' . esc_html( (string) $item->get_total() ) . '</td>' : '' ) . '</tr>';
		}
		$out .= '</table>';
		if ( $show_prices ) {
			$out .= '<p><strong>' . esc_html__( 'Total:', 'counter' ) . '</strong> ' . esc_html( $data['total'] ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}
}

class SalesListTable extends ListTable {

	private array $rows;

	public function __construct() {
		$this->rows = Sales::query_orders( $this->pull_filters_from_request() );
		parent::__construct( [ 'singular' => 'sale', 'plural' => 'sales', 'ajax' => false ] );
	}

	private function pull_filters_from_request(): array {
		$defaults = Sales::default_filters();
		$out      = [];
		foreach ( $defaults as $key => $default ) {
			$raw       = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state
			$out[ $key ] = '' !== $raw ? $raw : $default;
		}
		return $out;
	}

	public function filter_values(): array {
		return $this->pull_filters_from_request();
	}

	public function get_columns(): array {
		return [
			'date'           => __( 'Date', 'counter' ),
			'invoice'        => __( 'Invoice', 'counter' ),
			'customer'       => __( 'Customer', 'counter' ),
			'total'          => __( 'Total', 'counter' ),
			'payment_status' => __( 'Payment status', 'counter' ),
			'due'            => __( 'Due', 'counter' ),
			'methods'        => __( 'Method', 'counter' ),
			'status'         => __( 'Status', 'counter' ),
			'shipping'       => __( 'Shipping', 'counter' ),
			'actions'        => '',
		];
	}

	public function get_filters(): array {
		$locations = [];
		foreach ( Locations::all( 'active' ) as $l ) {
			$locations[ $l['id'] ] = $l['name'];
		}
		$registers = [];
		foreach ( Registers::all( 'active' ) as $r ) {
			$registers[ $r['id'] ] = $r['name'];
		}
		$methods = array_combine( Sales::TENDER_METHODS, array_map( 'ucfirst', Sales::TENDER_METHODS ) );

		return [
			'date_from'      => [ 'label' => __( 'From', 'counter' ), 'type' => 'date' ],
			'date_to'        => [ 'label' => __( 'To', 'counter' ), 'type' => 'date' ],
			'location_id'    => [ 'label' => __( 'Location', 'counter' ), 'type' => 'select', 'options' => $locations ],
			'payment_status' => [ 'label' => __( 'Payment status', 'counter' ), 'type' => 'select', 'options' => Sales::PAYMENT_STATUSES ],
			'method'         => [ 'label' => __( 'Method', 'counter' ), 'type' => 'select', 'options' => $methods ],
			'register_id'    => [ 'label' => __( 'Register', 'counter' ), 'type' => 'select', 'options' => $registers ],
			'shipping'       => [ 'label' => __( 'Shipping', 'counter' ), 'type' => 'select', 'options' => [ 'yes' => __( 'Has shipping', 'counter' ), 'no' => __( 'No shipping', 'counter' ) ] ],
			'customer'       => [ 'label' => __( 'Customer', 'counter' ), 'type' => 'text' ],
		];
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
		if ( 'total' === $column_name || 'due' === $column_name || 'shipping' === $column_name ) {
			return wc_price( $item[ $column_name ] );
		}
		if ( 'methods' === $column_name ) {
			return esc_html( implode( ', ', array_map( 'ucfirst', $item['methods'] ) ) );
		}
		if ( 'actions' === $column_name ) {
			$actions = [];
			foreach ( \Counter\Admin\Screens\Sales::row_actions_for( $item ) as $key => $a ) {
				$actions[ $key ] = '<a href="' . esc_url( $a['url'] ) . '" target="_blank">' . esc_html( $a['label'] ) . '</a>';
			}
			return $this->build_row_actions( $actions );
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
