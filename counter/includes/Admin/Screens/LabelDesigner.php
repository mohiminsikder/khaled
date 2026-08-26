<?php
namespace Counter\Admin\Screens;

use Counter\Docs\Labels;

defined( 'ABSPATH' ) || exit;

/**
 * P3.2 — a plain wp-admin form over Docs\Labels' own validated create()/
 * update(), the same "the screen cannot drift from what actually validates"
 * principle as Adjust.php, just via a POST-back form (this screen edits a
 * whole geometry + a variable-length list of field boxes — awkward as one
 * small fetch() call, natural as a normal WordPress admin form) rather than
 * REST. The live preview underneath the form is Docs\Labels::render_label()
 * itself, so what the operator sees here is the same code that will render
 * the printed sheet — never a second, drifting approximation of it.
 *
 * The calibration sheet is Direction's actual point: "do not trust the
 * roll's stated size or the driver's preset" — print this, measure the
 * offset with a ruler on the shop's own printer and roll, and type the
 * correction into a template's own top/left offset fields.
 */
class LabelDesigner {

	const SAMPLE_DATA = [
		'name'          => 'Sample Product Name',
		'price'         => '৳100.00',
		'sku'           => 'SKU-DEMO-001',
		'expiry'        => '2026-12-31',
		'mrp'           => '৳120.00',
		'barcode_value' => 'SKU-DEMO-001',
		'barcode_type'  => 'code128',
	];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );

		// P3.3 — the three real trigger points Direction names: a product
		// list (a WooCommerce Products bulk action), a price change (an
		// admin notice with a reprint link), and a purchase receipt (no
		// receiving screen exists yet to hang a notice off — reached today
		// via ?cntr_print_batch=po&po_id=N directly).
		add_filter( 'bulk_actions-edit-product', [ self::class, 'register_bulk_action' ] );
		add_filter( 'handle_bulk_actions-edit-product', [ self::class, 'handle_bulk_action' ], 10, 3 );
		add_action( 'woocommerce_product_object_updated_props', [ self::class, 'maybe_flag_price_change' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'maybe_show_price_change_notice' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Label Designer', 'counter' ),
			__( 'Label Designer', 'counter' ),
			'cntr_manage_templates',
			'counter-labels',
			[ self::class, 'render' ]
		);
	}

	public static function register_bulk_action( array $actions ): array {
		if ( current_user_can( 'cntr_print_labels' ) || current_user_can( 'cntr_manage_templates' ) ) {
			$actions['cntr_print_labels'] = __( 'Print labels (Counter)', 'counter' );
		}
		return $actions;
	}

	public static function handle_bulk_action( string $redirect_to, string $doaction, array $post_ids ): string {
		if ( 'cntr_print_labels' !== $doaction || empty( $post_ids ) ) {
			return $redirect_to;
		}
		$url = add_query_arg(
			[
				'page'             => 'counter-labels',
				'cntr_print_batch' => 'list',
				'product_ids'      => array_map( 'absint', $post_ids ),
				'qty'              => array_fill( 0, count( $post_ids ), 1 ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * A price change is a real WooCommerce prop update, not a diff this
	 * class has to compute itself — $updated_props is the list of prop
	 * names WooCommerce's own save path actually changed.
	 */
	public static function maybe_flag_price_change( \WC_Product $product, array $updated_props ): void {
		if ( ! in_array( 'regular_price', $updated_props, true ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			set_transient( 'cntr_price_changed_' . $user_id, $product->get_id(), 5 * MINUTE_IN_SECONDS );
		}
	}

	public static function maybe_show_price_change_notice(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id || ( ! current_user_can( 'cntr_print_labels' ) && ! current_user_can( 'cntr_manage_templates' ) ) ) {
			return;
		}
		$product_id = get_transient( 'cntr_price_changed_' . $user_id );
		if ( ! $product_id ) {
			return;
		}
		delete_transient( 'cntr_price_changed_' . $user_id );

		$url = add_query_arg(
			[ 'page' => 'counter-labels', 'cntr_print_batch' => 'product', 'product_id' => (int) $product_id, 'qty' => 1 ],
			admin_url( 'admin.php' )
		);
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s" target="_blank">%s</a></p></div>',
			esc_html__( "This product's price changed.", 'counter' ),
			esc_url( $url ),
			esc_html__( 'Print an updated label', 'counter' )
		);
	}

	public static function render(): void {
		// P3.3 — printing a batch is a narrower action than managing
		// templates: cntr_print_labels (a stockkeeper's own cap) is enough
		// on its own, so receiving/product-list printing does not require
		// the template-design capability.
		if ( isset( $_GET['cntr_print_batch'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render, no state change
			if ( ! current_user_can( 'cntr_print_labels' ) && ! current_user_can( 'cntr_manage_templates' ) ) {
				wp_die( esc_html__( 'Not permitted.', 'counter' ) );
			}
			self::render_print_batch();
			return;
		}

		if ( ! current_user_can( 'cntr_manage_templates' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		if ( isset( $_GET['cntr_calibration'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render, no state change
			self::render_calibration_sheet();
			return;
		}

		$notice = self::handle_post();

		$edit_id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, no state change
		$editing  = $edit_id ? Labels::get( $edit_id ) : null;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Label Designer', 'counter' ) . '</h1>';

		if ( $notice ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$notice['ok'] ? 'success' : 'error',
				esc_html( $notice['message'] )
			);
		}

		self::render_calibration_prompt();
		self::render_list( $edit_id );
		self::render_form( $editing );
		echo '</div>';
	}

	/**
	 * @return array{ok:bool,message:string}|null
	 */
	private static function handle_post(): ?array {
		if ( ! isset( $_POST['cntr_labels_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			return null;
		}
		check_admin_referer( 'cntr_labels_save' );

		$action = sanitize_key( wp_unslash( $_POST['cntr_labels_action'] ) );

		if ( 'delete' === $action ) {
			$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			if ( $id ) {
				Labels::delete( $id );
			}
			return [ 'ok' => true, 'message' => __( 'Template deleted.', 'counter' ) ];
		}

		$data = [
			'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'page' => [],
			'fields' => [],
		];
		foreach ( Labels::PAGE_KEYS as $k ) {
			$data['page'][ $k ] = isset( $_POST['page'][ $k ] ) ? sanitize_text_field( wp_unslash( $_POST['page'][ $k ] ) ) : 0;
		}
		if ( isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ) {
			foreach ( wp_unslash( $_POST['fields'] ) as $f ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- unslashed above
				if ( empty( $f['field'] ) ) {
					continue;
				}
				$data['fields'][] = [
					'field'     => sanitize_key( $f['field'] ),
					'x_mm'      => sanitize_text_field( $f['x_mm'] ?? 0 ),
					'y_mm'      => sanitize_text_field( $f['y_mm'] ?? 0 ),
					'width_mm'  => sanitize_text_field( $f['width_mm'] ?? 0 ),
					'height_mm' => sanitize_text_field( $f['height_mm'] ?? 0 ),
				];
			}
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = $id ? Labels::update( $id, $data ) : Labels::create( $data );

		if ( is_wp_error( $result ) ) {
			return [ 'ok' => false, 'message' => $result->get_error_message() ];
		}
		return [ 'ok' => true, 'message' => __( 'Template saved.', 'counter' ) ];
	}

	private static function render_calibration_prompt(): void {
		$url = esc_url( add_query_arg( [ 'page' => 'counter-labels', 'cntr_calibration' => 1 ] ) );
		echo '<div class="notice notice-info"><p>' . sprintf(
			/* translators: %s: link to the calibration sheet */
			esc_html__( 'Do not trust the roll\'s stated size or the driver\'s preset. %s on the shop\'s actual printer and actual roll, measure the offset with a ruler, then enter the correction as this template\'s top/left offset below.', 'counter' ),
			'<a href="' . $url . '" target="_blank">' . esc_html__( 'Print a millimetre-grid calibration sheet', 'counter' ) . '</a>'
		) . '</p></div>';
	}

	/**
	 * P3.3 — one entry point for all three trigger sources Direction names.
	 * ?cntr_print_batch=po&po_id=N       — one label per unit actually received on that PO.
	 * ?cntr_print_batch=list&product_ids[]=N&qty[]=N (index-aligned)     — a chosen product list, per-product counts.
	 * ?cntr_print_batch=product&product_id=N&qty=N   — a single reprint, e.g. after a price change (qty defaults to 1).
	 */
	private static function render_print_batch(): void {
		$source   = sanitize_key( wp_unslash( $_GET['cntr_print_batch'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render
		$template = Labels::get_default();
		if ( ! $template ) {
			wp_die( esc_html__( 'No label template exists yet. Create one first.', 'counter' ) );
		}

		$lines = match ( $source ) {
			'po'      => self::lines_from_po( isset( $_GET['po_id'] ) ? absint( $_GET['po_id'] ) : 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'list'    => self::lines_from_list(
				isset( $_GET['product_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['product_ids'] ) ) : [], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset( $_GET['qty'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['qty'] ) ) : [] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			),
			'product' => self::lines_from_product(
				isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				isset( $_GET['qty'] ) ? max( 1, absint( $_GET['qty'] ) ) : 1 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			),
			default   => [],
		};

		if ( empty( $lines ) ) {
			wp_die( esc_html__( 'Nothing to print — no received units, no products selected, or the source no longer exists.', 'counter' ) );
		}

		$sheets = Labels::render_batch( $template, $lines );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Counter — Print Labels', 'counter' ) . '</title>';
		printf(
			'<style>@page { size: %smm %smm; margin: 0; } body { margin: 0; } .cntr-label-sheet { page-break-after: always; }</style>',
			esc_attr( $template['page']['page_width_mm'] ),
			esc_attr( $template['page']['page_height_mm'] )
		);
		echo '</head><body>';
		foreach ( $sheets as $sheet_html ) {
			echo $sheet_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels::render_sheet()/render_label() escape every field value themselves
		}
		echo '<script>window.onload = function () { window.print(); };</script>';
		echo '</body></html>';
	}

	private static function lines_from_po( int $po_id ): array {
		if ( ! $po_id ) {
			return [];
		}
		$lines = [];
		foreach ( \Counter\Purchasing\Orders::lines( $po_id ) as $l ) {
			$qty = (float) $l['qty_received'];
			if ( $qty <= 0 ) {
				continue;
			}
			$lines[] = self::product_line( (int) $l['product_id'], (int) round( $qty ) );
		}
		return array_filter( $lines );
	}

	private static function lines_from_list( array $product_ids, array $qtys ): array {
		$lines = [];
		foreach ( $product_ids as $i => $pid ) {
			$qty     = max( 1, (int) ( $qtys[ $i ] ?? 1 ) );
			$line    = self::product_line( $pid, $qty );
			if ( $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	private static function lines_from_product( int $product_id, int $qty ): array {
		$line = self::product_line( $product_id, $qty );
		return $line ? [ $line ] : [];
	}

	private static function product_line( int $product_id, int $qty ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$sku = $product->get_sku();
		return [
			'name'          => $product->get_name(),
			'price'         => wc_format_decimal( $product->get_price(), 2 ),
			'sku'           => $sku ?: ( '#' . $product_id ),
			'mrp'           => wc_format_decimal( $product->get_regular_price(), 2 ),
			'barcode_value' => $sku ?: (string) $product_id,
			'barcode_type'  => 'code128',
			'qty'           => $qty,
		];
	}

	private static function render_calibration_sheet(): void {
		$width  = isset( $_GET['w'] ) ? (float) $_GET['w'] : 210.0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render
		$height = isset( $_GET['h'] ) ? (float) $_GET['h'] : 297.0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only render
		$svg    = Labels::calibration_sheet( $width, $height );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Counter — Calibration Sheet', 'counter' ) . '</title>';
		echo '<style>@page { size: ' . esc_attr( $width ) . 'mm ' . esc_attr( $height ) . 'mm; margin: 0; } body { margin: 0; }</style></head><body>';
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels::calibration_sheet() builds this SVG itself, no user data in it
		echo '<script>window.onload = function () { window.print(); };</script>';
		echo '</body></html>';
	}

	private static function render_list( int $edit_id ): void {
		$templates = Labels::all();
		echo '<h2>' . esc_html__( 'Templates', 'counter' ) . '</h2>';
		if ( empty( $templates ) ) {
			echo '<p>' . esc_html__( 'No label templates yet.', 'counter' ) . '</p>';
			return;
		}
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Name', 'counter' ) . '</th><th>' . esc_html__( 'Page', 'counter' ) . '</th><th>' . esc_html__( 'Fields', 'counter' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $templates as $t ) {
			$edit_url = esc_url( add_query_arg( [ 'page' => 'counter-labels', 'id' => $t['id'] ] ) );
			printf(
				'<tr%s><td><a href="%s">%s</a></td><td>%dx%d @ %smm x %smm</td><td>%d</td><td>',
				(int) $t['id'] === $edit_id ? ' style="background:#f6f7f7"' : '',
				$edit_url,
				esc_html( $t['name'] ),
				(int) $t['page']['cols'],
				(int) $t['page']['rows'],
				esc_html( $t['page']['label_width_mm'] ),
				esc_html( $t['page']['label_height_mm'] ),
				count( $t['fields'] )
			);
			echo '<form method="post" style="display:inline" onsubmit="return confirm(' . esc_js( __( 'Delete this template?', 'counter' ) ) . ');">';
			wp_nonce_field( 'cntr_labels_save' );
			printf( '<input type="hidden" name="cntr_labels_action" value="delete"><input type="hidden" name="id" value="%d">', (int) $t['id'] );
			echo '<button type="submit" class="button-link-delete">' . esc_html__( 'Delete', 'counter' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_form( ?array $editing ): void {
		$page   = $editing['page'] ?? array_fill_keys( Labels::PAGE_KEYS, '' );
		$fields = $editing['fields'] ?? [];
		$name   = $editing['name'] ?? '';
		$id     = $editing['id'] ?? 0;

		echo '<h2>' . ( $id ? esc_html__( 'Edit template', 'counter' ) : esc_html__( 'New template', 'counter' ) ) . '</h2>';
		echo '<form method="post" id="cntr-label-form">';
		wp_nonce_field( 'cntr_labels_save' );
		printf( '<input type="hidden" name="cntr_labels_action" value="save"><input type="hidden" name="id" value="%d">', (int) $id );

		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="cntr-lbl-name">' . esc_html__( 'Template name', 'counter' ) . '</label></th><td><input type="text" id="cntr-lbl-name" name="name" class="regular-text" value="' . esc_attr( $name ) . '" required></td></tr>';

		$page_labels = [
			'page_width_mm'   => __( 'Page/roll width (mm)', 'counter' ),
			'page_height_mm'  => __( 'Page/roll height (mm)', 'counter' ),
			'cols'            => __( 'Columns', 'counter' ),
			'rows'            => __( 'Rows', 'counter' ),
			'label_width_mm'  => __( 'Label width (mm)', 'counter' ),
			'label_height_mm' => __( 'Label height (mm)', 'counter' ),
			'gutter_x_mm'     => __( 'Horizontal gutter (mm)', 'counter' ),
			'gutter_y_mm'     => __( 'Vertical gutter (mm)', 'counter' ),
			'offset_top_mm'   => __( 'Top offset — calibration correction (mm)', 'counter' ),
			'offset_left_mm'  => __( 'Left offset — calibration correction (mm)', 'counter' ),
		];
		foreach ( $page_labels as $key => $label ) {
			printf(
				'<tr><th><label for="cntr-lbl-%1$s">%2$s</label></th><td><input type="number" step="any" id="cntr-lbl-%1$s" name="page[%1$s]" value="%3$s" required></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) ( $page[ $key ] ?? '' ) )
			);
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Fields on one label', 'counter' ) . '</h3>';
		echo '<table class="widefat" id="cntr-lbl-fields"><thead><tr><th>' . esc_html__( 'Field', 'counter' ) . '</th><th>' . esc_html__( 'x (mm)', 'counter' ) . '</th><th>' . esc_html__( 'y (mm)', 'counter' ) . '</th><th>' . esc_html__( 'width (mm)', 'counter' ) . '</th><th>' . esc_html__( 'height (mm)', 'counter' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $fields as $i => $f ) {
			self::render_field_row( $i, $f );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="cntr-lbl-add-field">' . esc_html__( '+ Add field', 'counter' ) . '</button></p>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save template', 'counter' ) . '</button></p>';
		echo '</form>';

		if ( $editing ) {
			echo '<h3>' . esc_html__( 'Preview (sample data)', 'counter' ) . '</h3>';
			echo Labels::render_label( $editing, self::SAMPLE_DATA ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_label() escapes every field value itself
		}

		self::script();
	}

	private static function render_field_row( int $i, array $f = [] ): void {
		printf( '<tr><td><select name="fields[%1$d][field]">', $i );
		foreach ( Labels::FIELD_TYPES as $type ) {
			printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $type ), ( $f['field'] ?? '' ) === $type ? ' selected' : '' );
		}
		echo '</select></td>';
		foreach ( [ 'x_mm', 'y_mm', 'width_mm', 'height_mm' ] as $k ) {
			printf(
				'<td><input type="number" step="any" name="fields[%d][%s]" value="%s" style="width:80px"></td>',
				$i,
				esc_attr( $k ),
				esc_attr( (string) ( $f[ $k ] ?? '' ) )
			);
		}
		echo '<td><button type="button" class="button-link-delete cntr-lbl-remove-field">' . esc_html__( 'Remove', 'counter' ) . '</button></td></tr>';
	}

	private static function script(): void {
		$field_types = wp_json_encode( Labels::FIELD_TYPES );
		?>
		<script>
		( function () {
			var tbody = document.querySelector( '#cntr-lbl-fields tbody' );
			var addBtn = document.getElementById( 'cntr-lbl-add-field' );
			var types = <?php echo $field_types; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output ?>;
			var idx = tbody ? tbody.children.length : 0;

			function newRow() {
				var tr = document.createElement( 'tr' );
				var select = '<select name="fields[' + idx + '][field]">' + types.map( function ( t ) { return '<option value="' + t + '">' + t + '</option>'; } ).join( '' ) + '</select>';
				var inputs = [ 'x_mm', 'y_mm', 'width_mm', 'height_mm' ].map( function ( k ) {
					return '<td><input type="number" step="any" name="fields[' + idx + '][' + k + ']" value="0" style="width:80px"></td>';
				} ).join( '' );
				tr.innerHTML = '<td>' + select + '</td>' + inputs + '<td><button type="button" class="button-link-delete cntr-lbl-remove-field">Remove</button></td>';
				tbody.appendChild( tr );
				idx++;
			}

			if ( addBtn ) {
				addBtn.addEventListener( 'click', newRow );
			}
			if ( tbody ) {
				tbody.addEventListener( 'click', function ( e ) {
					if ( e.target.classList.contains( 'cntr-lbl-remove-field' ) ) {
						e.target.closest( 'tr' ).remove();
					}
				} );
			}
		} )();
		</script>
		<?php
	}
}
