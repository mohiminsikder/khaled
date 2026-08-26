<?php
namespace Counter\Docs;

use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * P3.4 — "a template is data, not code." A row's own html/css is stored
 * text, rendered by substituting a fixed WHITELIST of {{token}} placeholders
 * — never eval, never a PHP include of the row's own content — so an
 * operator (or a compromised admin session) editing a template can change
 * captions and layout, never behaviour. A token outside the whitelist, or
 * one the caller did not supply a value for, renders to an empty string:
 * there is no path from "typo in a template" to a literal `{{shpo_name}}`
 * leaking onto a printed receipt.
 *
 * Every substituted value is escaped by default (Direction's own worked
 * example: a shop name containing HTML must not inject it) — the one
 * exception is RAW_TOKENS, pre-built HTML fragments Counter's own trusted
 * code assembles server-side (a rendered line-items table), never raw user
 * input.
 */
class Templates {

	const TYPES = [ 'receipt-79', 'receipt-58', 'invoice-a4', 'challan-63' ];

	const DEFAULT_WIDTH_MM = [
		'receipt-79' => 79,
		'receipt-58' => 58,
		'invoice-a4' => 210,
		'challan-63' => 210,
	];

	const ALLOWED_TOKENS = [
		'shop_name', 'shop_address', 'shop_phone', 'shop_bin',
		'receipt_no', 'date', 'customer_name', 'buyer_bin',
		'items_html', 'subtotal', 'tax', 'rounding', 'total',
		'footer_text', 'taxable_value', 'vat_amount', 'sd_amount', 'serial',
	];

	// A pre-built HTML fragment Counter's own code assembled — passed
	// through verbatim. Every other token is a plain scalar and is escaped.
	const RAW_TOKENS = [ 'items_html' ];

	// The tokens a template of this type is refused at save time without —
	// the ones the type cannot be meaningfully correct without.
	const REQUIRED_TOKENS = [
		'receipt-79' => [ 'total' ],
		'receipt-58' => [ 'total' ],
		'invoice-a4' => [ 'total', 'customer_name' ],
		'challan-63' => [ 'serial', 'taxable_value', 'vat_amount', 'total', 'buyer_bin' ],
	];

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Install::table( 'doc_templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * The template to actually use for $type: an exact locale match first,
	 * then that type's own is_default row, then simply the first row of
	 * that type. Null only when no template of this type exists yet.
	 */
	public static function find( string $type, string $locale = '' ): ?array {
		global $wpdb;
		$table = Install::table( 'doc_templates' );

		if ( '' !== $locale ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND locale = %s ORDER BY id LIMIT 1", $type, $locale ), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND is_default = 1 ORDER BY id LIMIT 1", $type ), ARRAY_A );
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id LIMIT 1", $type ), ARRAY_A );
		}
		return $row ?: null;
	}

	public static function all( string $type = '' ): array {
		global $wpdb;
		$table = Install::table( 'doc_templates' );
		if ( '' === $type ) {
			return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY type, locale", ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY locale", $type ), ARRAY_A );
	}

	/**
	 * @return array{id:int}|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;

		$check = self::validate( $data );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$table  = Install::table( 'doc_templates' );
		$result = $wpdb->insert(
			$table,
			[
				'type'       => $data['type'],
				'name'       => trim( (string) ( $data['name'] ?? '' ) ),
				'width_mm'   => (int) ( $data['width_mm'] ?? self::DEFAULT_WIDTH_MM[ $data['type'] ] ),
				'locale'     => (string) ( $data['locale'] ?? '' ),
				'html'       => (string) ( $data['html'] ?? '' ),
				'css'        => (string) ( $data['css'] ?? '' ),
				'is_default' => ! empty( $data['is_default'] ) ? 1 : 0,
				'updated_at' => Db::now(),
			]
		);

		// $wpdb->insert() returns false — and never touches insert_id — on a
		// real failure; a caller that only ever reads insert_id would read
		// stale state from a PRIOR, unrelated successful insert and report
		// success for a row that was never written. Found live: a locale
		// over the column's own 10-character limit fails exactly this way.
		if ( false === $result || ! $wpdb->insert_id ) {
			return new \WP_Error( 'cntr_doc_save_failed', $wpdb->last_error ?: __( 'Could not save this template.', 'counter' ), [ 'status' => 500 ] );
		}

		return [ 'id' => (int) $wpdb->insert_id ];
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;

		$existing = self::get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'cntr_doc_not_found', __( 'Template not found.', 'counter' ), [ 'status' => 404 ] );
		}

		$merged = array_merge( $existing, $data );
		$check  = self::validate( $merged );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$fieldset = [ 'updated_at' => Db::now() ];
		foreach ( [ 'type', 'name', 'width_mm', 'locale', 'html', 'css' ] as $k ) {
			if ( isset( $data[ $k ] ) ) {
				$fieldset[ $k ] = $data[ $k ];
			}
		}
		if ( isset( $data['is_default'] ) ) {
			$fieldset['is_default'] = ! empty( $data['is_default'] ) ? 1 : 0;
		}

		$table  = Install::table( 'doc_templates' );
		$result = $wpdb->update( $table, $fieldset, [ 'id' => $id ] );
		if ( false === $result ) { // false is a real failure; 0 is "nothing changed", not an error
			return new \WP_Error( 'cntr_doc_save_failed', $wpdb->last_error ?: __( 'Could not save this template.', 'counter' ), [ 'status' => 500 ] );
		}
		return true;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$table = Install::table( 'doc_templates' );
		$wpdb->delete( $table, [ 'id' => $id ] );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function validate( array $data ): bool|\WP_Error {
		$type = (string) ( $data['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'cntr_doc_bad_type', __( 'Unknown document template type.', 'counter' ), [ 'status' => 400 ] );
		}
		if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
			return new \WP_Error( 'cntr_doc_no_name', __( 'A template name is required.', 'counter' ), [ 'status' => 400 ] );
		}
		// Matches the schema's own column widths — refused here with a clear
		// message rather than left to fail at the $wpdb layer (see create()'s
		// own comment: that failure mode returns false/0 silently unless
		// explicitly checked, which nothing upstream of this class does).
		if ( strlen( (string) ( $data['locale'] ?? '' ) ) > 10 ) {
			return new \WP_Error( 'cntr_doc_locale_too_long', __( 'Locale must be 10 characters or fewer.', 'counter' ), [ 'status' => 400 ] );
		}
		if ( strlen( (string) ( $data['name'] ?? '' ) ) > 120 ) {
			return new \WP_Error( 'cntr_doc_name_too_long', __( 'Template name must be 120 characters or fewer.', 'counter' ), [ 'status' => 400 ] );
		}

		$present  = self::extract_tokens( (string) ( $data['html'] ?? '' ) );
		$required = self::REQUIRED_TOKENS[ $type ] ?? [];
		$missing  = array_values( array_diff( $required, $present ) );
		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'cntr_doc_missing_token',
				sprintf(
					/* translators: 1: template type, 2: comma-separated list of missing tokens */
					__( 'A %1$s template must include: %2$s', 'counter' ),
					$type,
					implode( ', ', array_map( static fn( $t ) => '{{' . $t . '}}', $missing ) )
				),
				[ 'status' => 400, 'missing' => $missing ]
			);
		}
		return true;
	}

	/**
	 * Every {{token}} name that appears in $html, whitespace inside the
	 * braces tolerated ({{ total }} and {{total}} are the same token) —
	 * the one place this pattern is defined, shared by validate() (what a
	 * template promises to fill in) and render() (what actually gets
	 * substituted), so the two can never quietly disagree with each other.
	 */
	private static function extract_tokens( string $html ): array {
		preg_match_all( '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $html, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * $data: token => value. A token not in ALLOWED_TOKENS, or one $data
	 * has no key for, renders to '' — never the literal placeholder text.
	 * Every value is esc_html()'d except RAW_TOKENS.
	 */
	public static function render( array $template, array $data ): string {
		$html = (string) ( $template['html'] ?? '' );

		$rendered = preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
			static function ( $m ) use ( $data ) {
				$token = $m[1];
				if ( ! in_array( $token, self::ALLOWED_TOKENS, true ) || ! array_key_exists( $token, $data ) ) {
					return '';
				}
				$value = $data[ $token ];
				return in_array( $token, self::RAW_TOKENS, true ) ? (string) $value : esc_html( (string) $value );
			},
			$html
		);

		$width = (int) ( $template['width_mm'] ?? 0 );
		$css   = (string) ( $template['css'] ?? '' );

		return sprintf(
			'<div class="cntr-doc" style="width:%dmm;box-sizing:border-box;">%s%s</div>',
			$width,
			'' !== $css ? '<style>' . $css . '</style>' : '',
			$rendered
		);
	}
}
