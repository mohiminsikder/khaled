<?php
namespace Counter\Admin\Screens;

use Counter\Admin\ListTable;
use Counter\Admin\EntityPicker;
use Counter\Purchasing\Orders;
use Counter\Purchasing\Suppliers as SuppliersModel;
use Counter\Stock\Locations;
use Counter\Pricing\Groups;

defined( 'ABSPATH' ) || exit;

/**
 * C3 — placing a purchase order. Backend is none: Purchasing\Orders already
 * validates, computes, and distributes landed cost (Orders::create()'s own
 * docblock: fixed ONCE at ordering time, from the shipping/other charges
 * captured HERE, on this screen — Receiving only ever slices what this
 * screen already decided).
 *
 * The line grid's own margin%/selling-price columns and per-line discount %
 * exist ONLY here, client-side and in this screen's own POST handling —
 * `cntr_purchase_lines` has a single `unit_cost` column (no discount_pct, no
 * margin, no selling price of its own): discount is folded into the
 * unit_cost this screen sends to Orders::create() before anything is
 * written, and the selling price is written through separately via
 * Pricing\Groups::set_price() (an existing function, not new backend logic)
 * once the order itself already exists. Both directions of margin<->price
 * are computed the same way (see PurchaseOrders.php's own inline script()):
 * `price = cost / (1 - margin/100)`, `margin = (1 - cost/price) * 100`.
 */
class PurchaseOrders {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	/**
	 * The margin<->price formula, in PHP — the browser-side line grid
	 * (script()'s own recalc()) implements the identical arithmetic for its
	 * live oninput recalculation, since a bidirectional live field can only
	 * ever be computed client-side; these two static methods exist so
	 * test_purchase_screens() has something to call a self-test can
	 * actually assert against ("margin and selling price stay consistent
	 * both directions"), not because either screen calls them directly.
	 */
	public static function price_from_margin( string $unit_cost, string $margin_pct ): string {
		$unit_cost  = wc_format_decimal( $unit_cost, 8 );
		$margin_pct = wc_format_decimal( $margin_pct, 8 );
		if ( bccomp( $margin_pct, '100', 8 ) >= 0 ) {
			return '0.0000';
		}
		return wc_format_decimal( bcdiv( $unit_cost, bcsub( '1', bcdiv( $margin_pct, '100', 10 ), 10 ), 10 ), 4 );
	}

	public static function margin_from_price( string $unit_cost, string $selling_price ): string {
		$unit_cost     = wc_format_decimal( $unit_cost, 8 );
		$selling_price = wc_format_decimal( $selling_price, 8 );
		if ( bccomp( $selling_price, '0', 8 ) <= 0 ) {
			return '0.0000';
		}
		return wc_format_decimal( bcmul( bcsub( '1', bcdiv( $unit_cost, $selling_price, 10 ), 10 ), '100', 10 ), 4 );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Purchase Orders', 'counter' ),
			__( 'Purchase Orders', 'counter' ),
			'cntr_manage_purchasing',
			'counter-purchase-orders',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_purchasing' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view selector

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Purchase Orders', 'counter' ) . '</h1>';

		if ( 'new' === $view ) {
			self::render_new_view();
		} elseif ( 'view' === $view ) {
			self::render_view_po();
		} else {
			$table = new PurchaseOrdersListTable();
			$table->maybe_handle_export();
			self::render_notice();
			$table->render_card_header();
			$table->render_filters_accordion();
			$table->prepare_items();
			echo '<form method="get">';
			echo '<input type="hidden" name="page" value="counter-purchase-orders">';
			$table->render_toolbar();
			$table->display();
			echo '</form>';
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$notice = get_transient( 'cntr_po_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'cntr_po_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $notice['ok'] ? 'success' : 'error', esc_html( $notice['message'] ) );
	}

	private static function set_notice( bool $ok, string $message ): void {
		set_transient( 'cntr_po_notice_' . get_current_user_id(), [ 'ok' => $ok, 'message' => $message ], MINUTE_IN_SECONDS );
	}

	private static function render_new_view(): void {
		if ( isset( $_POST['cntr_po_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_po_save' );
			$po_id = self::handle_save();
			if ( is_wp_error( $po_id ) ) {
				self::set_notice( false, $po_id->get_error_message() );
			} else {
				self::set_notice( true, __( 'Purchase order placed.', 'counter' ) );
				wp_safe_redirect( admin_url( 'admin.php?page=counter-purchase-orders&view=view&id=' . $po_id ) );
				exit;
			}
		}

		echo '<h2>' . esc_html__( 'New purchase order', 'counter' ) . '</h2>';

		$suppliers = array_map(
			static fn( $s ) => [ 'id' => (int) $s['id'], 'label' => $s['name'] ],
			SuppliersModel::all( 'active' )
		);
		$locations = array_map(
			static fn( $l ) => [ 'id' => (int) $l['id'], 'label' => $l['name'] ],
			Locations::all( 'active' )
		);
		$groups = array_map(
			static fn( $g ) => [ 'id' => (int) $g['id'], 'label' => $g['name'] ],
			Groups::all( 'active' )
		);

		echo '<form method="post" id="cntr-po-form">';
		wp_nonce_field( 'cntr_po_save' );
		echo '<input type="hidden" name="cntr_po_action" value="save">';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Supplier', 'counter' ) . '</label></th><td>';
		EntityPicker::render( [ 'id' => 'cntr-po-supplier', 'hidden_name' => 'supplier_id', 'type' => 'supplier', 'required' => true, 'options' => $suppliers ] );
		echo '</td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Location', 'counter' ) . '</label></th><td>';
		EntityPicker::render( [ 'id' => 'cntr-po-location', 'hidden_name' => 'location_id', 'type' => 'location', 'required' => true, 'options' => $locations ] );
		echo '</td></tr>';
		echo '<tr><th><label for="cntr-po-group">' . esc_html__( 'Price group to update', 'counter' ) . '</label></th><td><select id="cntr-po-group" name="price_group_id">';
		foreach ( $groups as $g ) {
			printf( '<option value="%d">%s</option>', (int) $g['id'], esc_html( $g['label'] ) );
		}
		echo '</select> <span class="description">' . esc_html__( 'each line\'s own selling price writes through here on save', 'counter' ) . '</span></td></tr>';
		echo '<tr><th><label for="cntr-po-expected">' . esc_html__( 'Expected date', 'counter' ) . '</label></th><td><input type="date" id="cntr-po-expected" name="expected_at"></td></tr>';
		echo '<tr><th><label for="cntr-po-shipping">' . esc_html__( 'Shipping (৳)', 'counter' ) . '</label></th><td><input type="text" id="cntr-po-shipping" name="shipping_total" value="0.00" class="cntr-po-charges"></td></tr>';
		echo '<tr><th><label for="cntr-po-other">' . esc_html__( 'Other charges (৳)', 'counter' ) . '</label></th><td><input type="text" id="cntr-po-other" name="other_total" value="0.00" class="cntr-po-charges"></td></tr>';
		echo '<tr><th><label for="cntr-po-note">' . esc_html__( 'Note', 'counter' ) . '</label></th><td><input type="text" id="cntr-po-note" name="note" class="regular-text"></td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Lines', 'counter' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Unit cost before tax and line total include the discount; margin % and selling price are linked — editing one recalculates the other. Both write through to the chosen price group on save.', 'counter' ) . '</p>';
		echo '<table class="widefat" id="cntr-po-lines"><thead><tr>';
		foreach (
			[
				__( 'Product', 'counter' ), __( 'Qty', 'counter' ), __( 'Unit cost before discount', 'counter' ),
				__( 'Discount %', 'counter' ), __( 'Unit cost before tax', 'counter' ), __( 'Tax %', 'counter' ),
				__( 'Line total', 'counter' ), __( 'Margin %', 'counter' ), __( 'Selling price', 'counter' ),
				__( 'Lot no.', 'counter' ), __( 'Expiry', 'counter' ), '',
			] as $h
		) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody></tbody></table>';
		echo '<p><button type="button" class="button" id="cntr-po-add-line">' . esc_html__( '+ Add line', 'counter' ) . '</button></p>';
		echo '<p><strong>' . esc_html__( 'Grand total:', 'counter' ) . '</strong> <span id="cntr-po-grand-total">৳ 0.00</span></p>';

		self::render_line_template();

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Place order', 'counter' ) . '</button></p>';
		echo '</form>';

		self::script();
	}

	/**
	 * The row template — real EntityPicker markup, cloned per row rather
	 * than hand-built a second time in JS, so it can never drift from
	 * EntityPicker::render()'s own HTML the moment either one changes.
	 * __IDX__ is a literal placeholder this file controls (never user
	 * input) that JS replaces with the real row index after cloning — inert
	 * until then, since it lives inside a <template>, never the live DOM.
	 * Wrapped in a throwaway <table><tbody> (not a bare <tr>) because the
	 * HTML parser's own "foster parenting" rules can otherwise drop a
	 * top-level <tr> inside <template> depending on the engine.
	 */
	private static function render_line_template(): void {
		echo '<template id="cntr-po-line-template"><table><tbody><tr>';
		echo '<td>';
		EntityPicker::render( [ 'id' => 'cntr-po-product-__IDX__', 'hidden_name' => 'lines[__IDX__][product_id]', 'type' => 'product', 'placeholder' => __( 'Product name or SKU…', 'counter' ), 'required' => true ] );
		EntityPicker::render( [ 'id' => 'cntr-po-variation-__IDX__', 'hidden_name' => 'lines[__IDX__][variation_id]', 'type' => 'variation', 'parent_field' => 'cntr-po-product-__IDX__', 'placeholder' => __( 'Variation, if any…', 'counter' ) ] );
		echo '</td>';
		echo '<td><input type="text" class="f-qty" name="lines[__IDX__][qty_ordered]" value="1" style="width:70px"></td>';
		echo '<td><input type="text" class="f-cost-before" name="lines[__IDX__][unit_cost_before_discount]" value="0.00" style="width:90px"></td>';
		echo '<td><input type="text" class="f-discount" name="lines[__IDX__][discount_pct]" value="0" style="width:60px"></td>';
		echo '<td><span class="f-cost-after">0.0000</span></td>';
		echo '<td><input type="text" class="f-tax" name="lines[__IDX__][tax_rate]" value="0" style="width:60px"></td>';
		echo '<td><span class="f-line-total">0.00</span></td>';
		echo '<td><input type="text" class="f-margin" name="lines[__IDX__][margin_pct]" value="0" style="width:70px"></td>';
		echo '<td><input type="text" class="f-price" name="lines[__IDX__][selling_price]" value="0.00" style="width:90px"></td>';
		echo '<td><input type="text" name="lines[__IDX__][lot_no]" style="width:80px"></td>';
		echo '<td><input type="date" name="lines[__IDX__][expiry_date]"></td>';
		echo '<td><button type="button" class="button-link-delete cntr-po-remove-line">&times;</button></td>';
		echo '</tr></tbody></table></template>';
	}

	/** @return int|\WP_Error */
	private static function handle_save() {
		$lines_in = isset( $_POST['lines'] ) && is_array( $_POST['lines'] ) ? wp_unslash( $_POST['lines'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitized per-field below
		$lines    = [];
		$price_writes = [];
		foreach ( $lines_in as $l ) {
			$product_id   = absint( $l['product_id'] ?? 0 );
			$variation_id = absint( $l['variation_id'] ?? 0 );
			if ( ! $product_id ) {
				continue;
			}
			$qty                    = wc_format_decimal( sanitize_text_field( $l['qty_ordered'] ?? '0' ), 4 );
			$unit_cost_before_disc  = wc_format_decimal( sanitize_text_field( $l['unit_cost_before_discount'] ?? '0' ), 4 );
			$discount_pct           = wc_format_decimal( sanitize_text_field( $l['discount_pct'] ?? '0' ), 4 );
			$unit_cost              = wc_format_decimal(
				bcmul( $unit_cost_before_disc, bcsub( '1', bcdiv( $discount_pct, '100', 6 ), 6 ), 6 ),
				4
			);
			$lines[] = [
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'qty_ordered'  => $qty,
				'unit_cost'    => $unit_cost,
				'tax_rate'     => wc_format_decimal( sanitize_text_field( $l['tax_rate'] ?? '0' ), 4 ),
				'lot_no'       => sanitize_text_field( $l['lot_no'] ?? '' ),
				'expiry_date'  => ! empty( $l['expiry_date'] ) ? sanitize_text_field( $l['expiry_date'] ) : null,
			];
			$selling_price = sanitize_text_field( $l['selling_price'] ?? '' );
			if ( '' !== $selling_price && is_numeric( $selling_price ) ) {
				$price_writes[] = [ 'product_id' => $product_id, 'variation_id' => $variation_id, 'price' => wc_format_decimal( $selling_price, 4 ) ];
			}
		}

		$result = Orders::create(
			[
				'supplier_id'    => absint( $_POST['supplier_id'] ?? 0 ),
				'location_id'    => absint( $_POST['location_id'] ?? 0 ),
				'shipping_total' => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['shipping_total'] ?? '0' ) ), 4 ),
				'other_total'    => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['other_total'] ?? '0' ) ), 4 ),
				'note'           => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
				'expected_at'    => ! empty( $_POST['expected_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_at'] ) ) : null,
				'user_id'        => get_current_user_id(),
				'lines'          => $lines,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// "Both write through to the price group on save" — AFTER the order
		// itself exists, using the existing Pricing\Groups::set_price(), not
		// new backend logic. A price-write failure here never unwinds the
		// already-placed order — the order is real regardless of whether the
		// selling side of the same screen also got typed in correctly.
		$group_id = absint( $_POST['price_group_id'] ?? 0 ) ?: Groups::default_group_id();
		foreach ( $price_writes as $w ) {
			Groups::set_price( $group_id, $w['product_id'], $w['variation_id'], $w['price'] );
		}

		return $result;
	}

	private static function render_view_po(): void {
		$po_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$po    = $po_id ? Orders::get( $po_id ) : null;
		if ( ! $po ) {
			echo '<p>' . esc_html__( 'Purchase order not found.', 'counter' ) . '</p>';
			return;
		}
		self::render_notice();

		$supplier = SuppliersModel::get( (int) $po['supplier_id'] );
		echo '<h2>' . esc_html( $po['po_no'] ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Supplier:', 'counter' ) . '</strong> ' . esc_html( $supplier['name'] ?? '' )
			. ' &nbsp; <strong>' . esc_html__( 'Status:', 'counter' ) . '</strong> ' . esc_html( $po['status'] )
			. ' &nbsp; <strong>' . esc_html__( 'Grand total:', 'counter' ) . '</strong> ' . wc_price( $po['grand_total'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</p>';

		if ( 'closed' !== $po['status'] && current_user_can( 'cntr_manage_purchasing' ) ) {
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=counter-receiving&view=receive&id=' . $po_id ) ),
				esc_html__( 'Receive', 'counter' )
			);
		}

		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Product', 'counter' ) . '</th><th>' . esc_html__( 'Ordered', 'counter' ) . '</th><th>' . esc_html__( 'Received', 'counter' ) . '</th><th>' . esc_html__( 'Unit cost', 'counter' ) . '</th><th>' . esc_html__( 'Landed unit cost', 'counter' ) . '</th><th>' . esc_html__( 'Lot', 'counter' ) . '</th><th>' . esc_html__( 'Expiry', 'counter' ) . '</th></tr></thead><tbody>';
		foreach ( Orders::lines( $po_id ) as $l ) {
			$product         = wc_get_product( $l['variation_id'] ?: $l['product_id'] );
			$per_unit_landed = bccomp( $l['qty_ordered'], '0', 4 ) > 0 ? bcdiv( $l['landed_cost_share'], $l['qty_ordered'], 4 ) : '0.0000';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $product ? $product->get_name() : ( '#' . $l['product_id'] ) ),
				esc_html( $l['qty_ordered'] ),
				esc_html( $l['qty_received'] ),
				wc_price( $l['unit_cost'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				wc_price( bcadd( $l['unit_cost'], $per_unit_landed, 4 ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $l['lot_no'] ),
				esc_html( (string) $l['expiry_date'] )
			);
		}
		echo '</tbody></table>';
	}

	private static function script(): void {
		?>
		<script>
		( function () {
			var tbody = document.querySelector( '#cntr-po-lines tbody' );
			var addBtn = document.getElementById( 'cntr-po-add-line' );
			var idx = 0;

			function num( el ) { return parseFloat( el.value ) || 0; }

			function recalc( tr ) {
				var qty = num( tr.querySelector( '.f-qty' ) );
				var costBefore = num( tr.querySelector( '.f-cost-before' ) );
				var discountPct = num( tr.querySelector( '.f-discount' ) );
				var taxPct = num( tr.querySelector( '.f-tax' ) );
				var costAfter = costBefore * ( 1 - discountPct / 100 );
				var lineTotal = qty * costAfter * ( 1 + taxPct / 100 );

				tr.querySelector( '.f-cost-after' ).textContent = costAfter.toFixed( 4 );
				tr.querySelector( '.f-line-total' ).textContent = lineTotal.toFixed( 2 );

				var marginInput = tr.querySelector( '.f-margin' );
				var priceInput = tr.querySelector( '.f-price' );
				var source = tr.dataset.priceSource || 'margin';
				if ( costAfter > 0 ) {
					if ( 'price' === source ) {
						var price = num( priceInput );
						if ( price > 0 ) { marginInput.value = ( ( 1 - costAfter / price ) * 100 ).toFixed( 2 ); }
					} else {
						var margin = num( marginInput );
						if ( margin < 100 ) { priceInput.value = ( costAfter / ( 1 - margin / 100 ) ).toFixed( 2 ); }
					}
				}
				updateGrandTotal();
			}

			function updateGrandTotal() {
				var total = 0;
				tbody.querySelectorAll( 'tr' ).forEach( function ( tr ) {
					total += parseFloat( tr.querySelector( '.f-line-total' ).textContent ) || 0;
				} );
				var shipping = parseFloat( document.getElementById( 'cntr-po-shipping' ).value ) || 0;
				var other = parseFloat( document.getElementById( 'cntr-po-other' ).value ) || 0;
				document.getElementById( 'cntr-po-grand-total' ).textContent = '৳ ' + ( total + shipping + other ).toFixed( 2 );
			}

			function newRow() {
				var tpl = document.getElementById( 'cntr-po-line-template' );
				var frag = tpl.content.cloneNode( true );
				var tr = frag.querySelector( 'tr' );
				// __IDX__ -> the real row index, everywhere it appears (every
				// name= and id= attribute the template printed) — done on the
				// serialized HTML, then re-parsed by the innerHTML assignment,
				// BEFORE this row is wired or appended, so nothing ends up
				// half-renamed.
				tr.innerHTML = tr.innerHTML.split( '__IDX__' ).join( String( idx ) );
				tr.dataset.priceSource = 'margin';
				tbody.appendChild( tr );

				[ 'f-qty', 'f-cost-before', 'f-discount', 'f-tax' ].forEach( function ( cls ) {
					tr.querySelector( '.' + cls ).addEventListener( 'input', function () { recalc( tr ); } );
				} );
				tr.querySelector( '.f-margin' ).addEventListener( 'input', function () { tr.dataset.priceSource = 'margin'; recalc( tr ); } );
				tr.querySelector( '.f-price' ).addEventListener( 'input', function () { tr.dataset.priceSource = 'price'; recalc( tr ); } );

				// The just-cloned row's own EntityPicker markup is real, but
				// unwired until this runs — wireAll() is idempotent (its own
				// data-cntr-wired guard), so calling it again only ever wires
				// what's actually new.
				if ( window.CNTR_EntityPicker ) { window.CNTR_EntityPicker.wireAll(); }

				idx++;
			}

			if ( addBtn ) { addBtn.addEventListener( 'click', newRow ); }
			if ( tbody ) {
				tbody.addEventListener( 'click', function ( e ) {
					if ( e.target.classList.contains( 'cntr-po-remove-line' ) ) {
						e.target.closest( 'tr' ).remove();
						updateGrandTotal();
					}
				} );
			}
			document.querySelectorAll( '.cntr-po-charges' ).forEach( function ( el ) {
				el.addEventListener( 'input', updateGrandTotal );
			} );

			newRow(); // start with one line
		} )();
		</script>
		<?php
	}
}

class PurchaseOrdersListTable extends ListTable {

	private array $rows;

	public function __construct() {
		global $wpdb;
		$table       = \Counter\Install::table( 'purchase_orders' );
		$this->rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variables, static SQL
		$this->add_url   = admin_url( 'admin.php?page=counter-purchase-orders&view=new' );
		$this->add_label = __( '+ New purchase order', 'counter' );
		parent::__construct( [ 'singular' => 'purchase order', 'plural' => 'purchase orders', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'po_no'       => __( 'PO #', 'counter' ),
			'supplier'    => __( 'Supplier', 'counter' ),
			'status'      => __( 'Status', 'counter' ),
			'grand_total' => __( 'Total', 'counter' ),
			'ordered_at'  => __( 'Ordered', 'counter' ),
			'actions'     => '',
		];
	}

	public function get_filters(): array {
		return [
			'status' => [
				'label'   => __( 'Status', 'counter' ),
				'type'    => 'select',
				'options' => [ 'ordered' => __( 'Ordered', 'counter' ), 'partial' => __( 'Partial', 'counter' ), 'closed' => __( 'Closed', 'counter' ) ],
			],
		];
	}

	private function filtered_rows(): array {
		$status = $this->filter_values()['status'] ?? '';
		return $status ? array_values( array_filter( $this->rows, static fn( $r ) => $r['status'] === $status ) ) : $this->rows;
	}

	public function export_rows(): array {
		return $this->filtered_rows();
	}

	public function export_columns(): array {
		$cols = $this->get_columns();
		unset( $cols['actions'] );
		return $cols;
	}

	public function prepare_items(): void {
		$rows     = $this->filtered_rows();
		$per_page = 20;
		$page     = $this->get_pagenum();
		$this->set_pagination_args( [ 'total_items' => count( $rows ), 'per_page' => $per_page ] );
		$this->items = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
	}

	public function column_default( $item, $column_name ) {
		if ( 'supplier' === $column_name ) {
			$s = SuppliersModel::get( (int) $item['supplier_id'] );
			return esc_html( $s['name'] ?? '' );
		}
		if ( 'grand_total' === $column_name ) {
			return wc_price( $item['grand_total'] );
		}
		if ( 'actions' === $column_name ) {
			$actions = [ 'view' => '<a href="' . esc_url( admin_url( 'admin.php?page=counter-purchase-orders&view=view&id=' . $item['id'] ) ) . '">' . esc_html__( 'View', 'counter' ) . '</a>' ];
			if ( 'closed' !== $item['status'] ) {
				$actions['receive'] = '<a href="' . esc_url( admin_url( 'admin.php?page=counter-receiving&view=receive&id=' . $item['id'] ) ) . '">' . esc_html__( 'Receive', 'counter' ) . '</a>';
			}
			return $this->build_row_actions( $actions );
		}
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}
}
