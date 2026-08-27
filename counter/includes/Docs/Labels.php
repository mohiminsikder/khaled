<?php
namespace Counter\Docs;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P3.2 — "'any direct-thermal barcode printer' is true and almost useless."
 * Templates store the physical geometry of a label run in millimetres:
 * page/roll size, the columns and rows a page holds, one label's own size,
 * the gutter between labels, and a top/left offset correction the shop
 * measures with a ruler against its own printer and roll (calibration, not
 * configuration — Direction). A row a schema this shape cannot represent
 * (rows*cols that would not fit the stated page, a field box that would not
 * fit inside the label) is refused at save time rather than silently printed
 * wrong and discovered by a cashier mid-queue.
 *
 * Rendering is plain HTML+CSS in millimetres (Docs\Receipt's own idiom, not
 * Docs\Barcode's raw-SVG one) — a label is a small printed card of mixed text
 * and (optionally) one embedded barcode, not a single vector drawing; the
 * barcode field alone embeds Docs\Barcode's own self-contained inline SVG
 * verbatim. The calibration sheet, by contrast, IS a natural SVG grid — no
 * text layout, just measured lines — so it follows Barcode's mm-on-the-<svg>
 * convention instead.
 */
class Labels {

	const FIELD_TYPES = [ 'name', 'price', 'barcode', 'sku', 'expiry', 'mrp' ];

	const PAGE_KEYS = [
		'page_width_mm', 'page_height_mm', 'cols', 'rows',
		'label_width_mm', 'label_height_mm', 'gutter_x_mm', 'gutter_y_mm',
		'offset_top_mm', 'offset_left_mm',
	];

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'label_templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	public static function all(): array {
		global $wpdb;
		$table = Install::table( 'label_templates' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name", ARRAY_A );
		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * The template a batch print (P3.3) uses when the caller does not name
	 * one explicitly — is_default=1, or failing that the first template
	 * that exists. Null only when no template has been created yet.
	 */
	public static function get_default(): ?array {
		global $wpdb;
		$table = Install::table( 'label_templates' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_default = 1 ORDER BY id LIMIT 1", ARRAY_A );
		if ( ! $row ) {
			$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id LIMIT 1", ARRAY_A );
		}
		return $row ? self::decode( $row ) : null;
	}

	private static function decode( array $row ): array {
		$row['page']   = json_decode( (string) $row['page_json'], true ) ?: [];
		$row['fields'] = json_decode( (string) $row['fields_json'], true ) ?: [];
		unset( $row['page_json'], $row['fields_json'] );
		return $row;
	}

	/**
	 * @return array{id:int}|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'cntr_label_no_name', __( 'A template name is required.', 'counter' ), [ 'status' => 400 ] );
		}

		$page   = self::normalize_page( $data['page'] ?? [] );
		$fields = self::normalize_fields( $data['fields'] ?? [] );

		$page_check = self::validate_page( $page );
		if ( is_wp_error( $page_check ) ) {
			return $page_check;
		}
		$fields_check = self::validate_fields( $page, $fields );
		if ( is_wp_error( $fields_check ) ) {
			return $fields_check;
		}

		$table = Install::table( 'label_templates' );
		$wpdb->insert(
			$table,
			[
				'name'         => $name,
				'page_json'    => wp_json_encode( $page ),
				'fields_json'  => wp_json_encode( $fields ),
				'is_default'   => ! empty( $data['is_default'] ) ? 1 : 0,
				'updated_at'   => Db::now(),
			]
		);

		return [ 'id' => (int) $wpdb->insert_id ];
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$existing = self::get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'cntr_label_not_found', __( 'Template not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$page   = self::normalize_page( $data['page'] ?? $existing['page'] );
		$fields = self::normalize_fields( $data['fields'] ?? $existing['fields'] );

		$page_check = self::validate_page( $page );
		if ( is_wp_error( $page_check ) ) {
			return $page_check;
		}
		$fields_check = self::validate_fields( $page, $fields );
		if ( is_wp_error( $fields_check ) ) {
			return $fields_check;
		}

		$fieldset = [
			'page_json'   => wp_json_encode( $page ),
			'fields_json' => wp_json_encode( $fields ),
			'updated_at'  => Db::now(),
		];
		if ( isset( $data['name'] ) && '' !== trim( (string) $data['name'] ) ) {
			$fieldset['name'] = trim( (string) $data['name'] );
		}
		if ( isset( $data['is_default'] ) ) {
			$fieldset['is_default'] = ! empty( $data['is_default'] ) ? 1 : 0;
		}

		$table = Install::table( 'label_templates' );
		$wpdb->update( $table, $fieldset, [ 'id' => $id ] );
		return true;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$table = Install::table( 'label_templates' );
		$wpdb->delete( $table, [ 'id' => $id ] );
	}

	/** Public so a not-yet-saved template (C8's live preview) normalizes identically to what save() would store. */
	public static function normalize_page( array $page ): array {
		$out = [];
		foreach ( self::PAGE_KEYS as $k ) {
			if ( 'cols' === $k || 'rows' === $k ) {
				$out[ $k ] = max( 1, (int) ( $page[ $k ] ?? 1 ) );
			} else {
				$out[ $k ] = wc_format_decimal( $page[ $k ] ?? 0, 3 );
			}
		}
		return $out;
	}

	/** Public — same reason as normalize_page(). */
	public static function normalize_fields( array $fields ): array {
		$out = [];
		foreach ( $fields as $f ) {
			$type = (string) ( $f['field'] ?? '' );
			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				continue;
			}
			$out[] = [
				'field'     => $type,
				'x_mm'      => wc_format_decimal( $f['x_mm'] ?? 0, 3 ),
				'y_mm'      => wc_format_decimal( $f['y_mm'] ?? 0, 3 ),
				'width_mm'  => wc_format_decimal( $f['width_mm'] ?? 0, 3 ),
				'height_mm' => wc_format_decimal( $f['height_mm'] ?? 0, 3 ),
				// C8 — a field the operator has turned off keeps its own
				// geometry rather than losing it, so switching it back on
				// later doesn't mean re-measuring the box by hand again.
				'enabled'   => ! isset( $f['enabled'] ) || ! empty( $f['enabled'] ),
				'font_pt'   => max( 1, (int) ( $f['font_pt'] ?? 8 ) ),
			];
		}
		return $out;
	}

	/**
	 * Refuses a page geometry whose rows*cols grid — plus the gutters between
	 * labels and the top/left calibration offset — would not physically fit
	 * inside the stated page/roll size.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_page( array $page ) {
		$needed_width  = bcadd( $page['offset_left_mm'], bcadd(
			bcmul( (string) $page['cols'], $page['label_width_mm'], 3 ),
			bcmul( (string) max( 0, (int) $page['cols'] - 1 ), $page['gutter_x_mm'], 3 ),
			3
		), 3 );
		$needed_height = bcadd( $page['offset_top_mm'], bcadd(
			bcmul( (string) $page['rows'], $page['label_height_mm'], 3 ),
			bcmul( (string) max( 0, (int) $page['rows'] - 1 ), $page['gutter_y_mm'], 3 ),
			3
		), 3 );

		if ( bccomp( $needed_width, $page['page_width_mm'], 3 ) > 0 || bccomp( $needed_height, $page['page_height_mm'], 3 ) > 0 ) {
			return new \WP_Error(
				'cntr_label_page_overflow',
				__( 'This many rows and columns of this label size do not fit the stated page.', 'counter' ),
				[ 'status' => 400, 'needed_width_mm' => $needed_width, 'needed_height_mm' => $needed_height ]
			);
		}
		return true;
	}

	/**
	 * Refuses a field box that would not fit inside a single label's own
	 * size — the render-time clamp in render_label() is defence in depth for
	 * data that reaches it some other way, not a substitute for refusing bad
	 * geometry at the point it was entered.
	 *
	 * @return true|\WP_Error
	 */
	public static function validate_fields( array $page, array $fields ) {
		foreach ( $fields as $f ) {
			if ( bccomp( $f['x_mm'], '0', 3 ) < 0 || bccomp( $f['y_mm'], '0', 3 ) < 0 ) {
				return new \WP_Error( 'cntr_label_field_overflow', __( 'A field box cannot start outside the label.', 'counter' ), [ 'status' => 400, 'field' => $f ] );
			}
			$right  = bcadd( $f['x_mm'], $f['width_mm'], 3 );
			$bottom = bcadd( $f['y_mm'], $f['height_mm'], 3 );
			if ( bccomp( $right, $page['label_width_mm'], 3 ) > 0 || bccomp( $bottom, $page['label_height_mm'], 3 ) > 0 ) {
				return new \WP_Error( 'cntr_label_field_overflow', __( 'A field box does not fit inside the label.', 'counter' ), [ 'status' => 400, 'field' => $f ] );
			}
		}
		return true;
	}

	/**
	 * The millimetre offset of the top-left corner of label ($row, $col),
	 * both 0-indexed, within the page. Pure arithmetic — no I/O, no
	 * rounding beyond bcmath's own scale, so test_labels() can hand-compute
	 * the expected value directly.
	 */
	public static function layout( array $page, int $row, int $col ): array {
		$x = bcadd( $page['offset_left_mm'], bcmul( (string) $col, bcadd( $page['label_width_mm'], $page['gutter_x_mm'], 3 ), 3 ), 3 );
		$y = bcadd( $page['offset_top_mm'], bcmul( (string) $row, bcadd( $page['label_height_mm'], $page['gutter_y_mm'], 3 ), 3 ), 3 );
		return [ 'x_mm' => rtrim( rtrim( $x, '0' ), '.' ), 'y_mm' => rtrim( rtrim( $y, '0' ), '.' ) ];
	}

	/**
	 * One label as an HTML fragment, $label_width_mm x $label_height_mm,
	 * every field box CLAMPED to the label's own bounds regardless of what
	 * is stored — a field cannot render outside the label even if it somehow
	 * reached here without going through validate_fields() first. A field
	 * with no matching key in $sample_data (a barcode field with no SKU/GTIN
	 * given, most notably) renders an empty box rather than erroring.
	 */
	public static function render_label( array $template, array $sample_data ): string {
		$page   = $template['page'];
		$fields = $template['fields'];
		$lw     = (float) $page['label_width_mm'];
		$lh     = (float) $page['label_height_mm'];

		$html = sprintf(
			'<div class="cntr-label" style="position:relative;width:%smm;height:%smm;overflow:hidden;box-sizing:border-box;border:1px solid #ccc;">',
			self::fmt( $lw ),
			self::fmt( $lh )
		);

		foreach ( $fields as $f ) {
			// C8 — an operator's own "hide this field" toggle, not a
			// geometry problem render_label() itself needs to solve;
			// nothing about $f's box is touched, so re-enabling it later
			// draws exactly where it always did.
			if ( isset( $f['enabled'] ) && ! $f['enabled'] ) {
				continue;
			}

			$x = max( 0.0, min( (float) $f['x_mm'], $lw ) );
			$y = max( 0.0, min( (float) $f['y_mm'], $lh ) );
			$w = max( 0.0, min( (float) $f['width_mm'], $lw - $x ) );
			$h = max( 0.0, min( (float) $f['height_mm'], $lh - $y ) );
			$font_pt = max( 1, (int) ( $f['font_pt'] ?? 8 ) );

			$html .= sprintf(
				'<div class="cntr-label-field cntr-label-field-%s" style="position:absolute;left:%smm;top:%smm;width:%smm;height:%smm;overflow:hidden;font-size:%dpt;">',
				esc_attr( $f['field'] ),
				self::fmt( $x ),
				self::fmt( $y ),
				self::fmt( $w ),
				self::fmt( $h ),
				$font_pt
			);

			if ( 'barcode' === $f['field'] ) {
				$value = (string) ( $sample_data['barcode_value'] ?? '' );
				$type  = (string) ( $sample_data['barcode_type'] ?? 'code128' );
				$svg   = '';
				if ( '' !== $value ) {
					$svg = ( 'ean13' === $type )
						? ( Barcode::ean13_svg( $value, 0.2, max( 1.0, $h - 2 ) ) ?? '' )
						: ( Barcode::code128_svg( $value, 0.2, max( 1.0, $h - 2 ) ) ?? '' );
				}
				$html .= $svg; // Barcode's own SVG is already self-contained and escaped internally.
			} else {
				$html .= esc_html( (string) ( $sample_data[ $f['field'] ] ?? '' ) );
			}

			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * A millimetre-grid calibration sheet: a light line every 5mm, a darker
	 * line and a printed millimetre label every 10mm, at EXACTLY the stated
	 * page size — what the shop prints, measures with a ruler against its
	 * own printer and roll, and reads the offset correction off directly.
	 */
	public static function calibration_sheet( float $page_width_mm, float $page_height_mm ): string {
		$svg = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%smm" height="%smm" viewBox="0 0 %s %s">',
			self::fmt( $page_width_mm ),
			self::fmt( $page_height_mm ),
			self::fmt( $page_width_mm ),
			self::fmt( $page_height_mm )
		);
		$svg .= sprintf( '<rect x="0" y="0" width="%s" height="%s" fill="#fff"/>', self::fmt( $page_width_mm ), self::fmt( $page_height_mm ) );

		for ( $x = 0; $x <= $page_width_mm; $x += 5 ) {
			$major = ( 0 === (int) round( $x ) % 10 );
			$svg  .= sprintf(
				'<line x1="%1$s" y1="0" x2="%1$s" y2="%2$s" stroke="%3$s" stroke-width="%4$s"/>',
				self::fmt( $x ), self::fmt( $page_height_mm ), $major ? '#000' : '#ccc', $major ? '0.2' : '0.1'
			);
			if ( $major && $x > 0 ) {
				$svg .= sprintf( '<text x="%s" y="3" font-size="2">%d</text>', self::fmt( $x + 0.3 ), (int) $x );
			}
		}
		for ( $y = 0; $y <= $page_height_mm; $y += 5 ) {
			$major = ( 0 === (int) round( $y ) % 10 );
			$svg  .= sprintf(
				'<line x1="0" y1="%1$s" x2="%2$s" y2="%1$s" stroke="%3$s" stroke-width="%4$s"/>',
				self::fmt( $y ), self::fmt( $page_width_mm ), $major ? '#000' : '#ccc', $major ? '0.2' : '0.1'
			);
			if ( $major && $y > 0 ) {
				$svg .= sprintf( '<text x="0.3" y="%s" font-size="2">%d</text>', self::fmt( $y - 0.3 ), (int) $y );
			}
		}

		$svg .= '</svg>';
		return $svg;
	}

	private static function fmt( float $n ): string {
		$s = rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	// -- P3.3: batch label printing ------------------------------------------------
	//
	// One shared pipeline for all three trigger sources Direction names
	// (a purchase receipt, a product list, a price change): each reduces to
	// the same shape — a list of [product data, qty] lines — because "print
	// from a receipt" and "print from a list" differ only in where the qty
	// numbers came from, never in how labels get queued or laid out.

	/**
	 * $lines: [ [ 'name'=>?, 'price'=>?, 'sku'=>?, 'expiry'=>?, 'mrp'=>?,
	 *   'barcode_value'=>?, 'barcode_type'=>?, 'qty'=>int ], ... ]. One label
	 * per unit — "receiving 12 units queues 12 labels" is qty=12 on one
	 * line producing 12 identical entries, not one entry carrying a count.
	 */
	public static function expand( array $lines ): array {
		$out = [];
		foreach ( $lines as $line ) {
			$qty  = max( 0, (int) ( $line['qty'] ?? 1 ) );
			$data = [
				'name'          => (string) ( $line['name'] ?? '' ),
				'price'         => (string) ( $line['price'] ?? '' ),
				'sku'           => (string) ( $line['sku'] ?? '' ),
				'expiry'        => (string) ( $line['expiry'] ?? '' ),
				'mrp'           => (string) ( $line['mrp'] ?? '' ),
				'barcode_value' => (string) ( $line['barcode_value'] ?? $line['sku'] ?? '' ),
				'barcode_type'  => (string) ( $line['barcode_type'] ?? 'code128' ),
			];
			for ( $i = 0; $i < $qty; $i++ ) {
				$out[] = $data;
			}
		}
		return $out;
	}

	/**
	 * Splits a flat label list into sheets of $page's own capacity
	 * (cols x rows). array_chunk() leaves the final chunk short rather than
	 * padding it — there is no such thing as an "empty label" entry for
	 * render_sheet() to skip; it is simply not there.
	 */
	public static function paginate( array $flat_items, array $page ): array {
		$capacity = max( 1, (int) $page['cols'] * (int) $page['rows'] );
		return array_chunk( $flat_items, $capacity );
	}

	/**
	 * One full sheet as an HTML fragment, $page's own page_width_mm x
	 * page_height_mm. Iterates ONLY over $sheet_items — a partial last
	 * sheet (fewer items than the grid holds) renders that many label
	 * boxes and not one box more; there is no loop over the grid's own
	 * capacity that a short sheet would leave trailing blank stickers in.
	 */
	public static function render_sheet( array $template, array $sheet_items ): string {
		$page = $template['page'];
		$cols = max( 1, (int) $page['cols'] );

		$html = sprintf(
			'<div class="cntr-label-sheet" style="position:relative;width:%smm;height:%smm;">',
			self::fmt( (float) $page['page_width_mm'] ),
			self::fmt( (float) $page['page_height_mm'] )
		);

		foreach ( $sheet_items as $i => $data ) {
			$row = intdiv( $i, $cols );
			$col = $i % $cols;
			$pos = self::layout( $page, $row, $col );
			$html .= sprintf( '<div style="position:absolute;left:%smm;top:%smm;">', $pos['x_mm'], $pos['y_mm'] );
			$html .= self::render_label( $template, $data );
			$html .= '</div>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * expand() + paginate() + render_sheet() for every resulting sheet —
	 * the one call a print trigger (receiving, a product list, a price
	 * change) actually needs.
	 *
	 * @return string[] one HTML fragment per sheet
	 */
	public static function render_batch( array $template, array $lines ): array {
		$sheets = self::paginate( self::expand( $lines ), $template['page'] );
		return array_map( static fn( $sheet ) => self::render_sheet( $template, $sheet ), $sheets );
	}
}
