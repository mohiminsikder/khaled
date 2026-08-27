<?php
namespace Counter\Admin\Screens;

use Counter\Settings as SettingsModel;
use Counter\Install;
use Counter\Db;

defined( 'ABSPATH' ) || exit;

/**
 * C7 — the system is tuned without SSH. Backend is none: this is a form over
 * Settings::get()/set(), which already types and stores every key. The one
 * thing Settings::set() itself doesn't do is REFUSE a bad value (it just
 * coerces to the declared type) — validate() below is this screen's own
 * gate, refusing with a reason before a coerced-but-nonsensical value (a
 * 140% VAT rate, a rounding mode WordPress has never heard of) ever reaches
 * the store.
 */
class Settings {

	const TABS = [
		'business'  => 'Business',
		'pos'       => 'POS',
		'tax'       => 'Tax',
		'documents' => 'Documents',
		'backup'    => 'Backup',
	];

	/** Which settings keys live on which tab, in display order. */
	const TAB_KEYS = [
		'business'  => [ 'shop.name', 'shop.bin', 'shop.address', 'shop.phone', 'shop.footer_text', 'cash.rounding_step', 'cash.rounding_mode', 'credit.default_due_days', 'reports.dead_stock_days' ],
		'pos'       => [ 'pos.default_register', 'pos.search_fields', 'pos.discount_ceiling_pct', 'pos.weight_barcode_prefix', 'pos.keyboard_map', 'stock.allow_negative_pos', 'stock.online_buffer', 'stock.adjustment_note_threshold' ],
		'tax'       => [ 'vat.enabled', 'vat.rate', 'vat.challan_prefix', 'vat.challan_start' ],
		'documents' => [ 'print.receipt_template_id', 'print.label_template_id' ],
		'backup'    => [ 'backup.manifest' ],
	];

	/** Keys whose value changing means the terminal needs to notice on its next poll. */
	const CATALOG_NOTICE_KEYS = [ 'pos.default_register', 'pos.search_fields', 'pos.discount_ceiling_pct', 'pos.weight_barcode_prefix', 'pos.keyboard_map' ];

	const ROUNDING_MODES = [ 'nearest', 'up', 'down' ];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Settings', 'counter' ),
			__( 'Settings', 'counter' ),
			'cntr_manage_settings',
			'counter-settings',
			[ self::class, 'render' ]
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function validate( string $key, $raw ) {
		switch ( $key ) {
			case 'shop.name':
				if ( '' === trim( (string) $raw ) ) {
					return new \WP_Error( 'cntr_settings_required', __( 'Shop name is required.', 'counter' ) );
				}
				break;
			case 'cash.rounding_step':
				if ( ! is_numeric( $raw ) || (float) $raw <= 0 ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Rounding step must be a positive number.', 'counter' ) );
				}
				break;
			case 'cash.rounding_mode':
				if ( ! in_array( (string) $raw, self::ROUNDING_MODES, true ) ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Rounding mode must be nearest, up, or down.', 'counter' ) );
				}
				break;
			case 'credit.default_due_days':
			case 'reports.dead_stock_days':
			case 'stock.online_buffer':
			case 'vat.challan_start':
				if ( ! is_numeric( $raw ) || (int) $raw < 0 ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'This must be zero or a positive whole number.', 'counter' ) );
				}
				break;
			case 'pos.discount_ceiling_pct':
			case 'vat.rate':
				if ( ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw > 100 ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Must be a percentage between 0 and 100.', 'counter' ) );
				}
				break;
			case 'stock.adjustment_note_threshold':
				if ( ! is_numeric( $raw ) || (float) $raw < 0 ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Must be zero or a positive amount.', 'counter' ) );
				}
				break;
			case 'pos.weight_barcode_prefix':
				if ( ! preg_match( '/^\d$/', (string) $raw ) ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Must be a single digit.', 'counter' ) );
				}
				break;
			case 'pos.keyboard_map':
				$decoded = json_decode( (string) $raw, true );
				if ( ! is_array( $decoded ) || empty( $decoded ) ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Must be a JSON object mapping keys to actions.', 'counter' ) );
				}
				break;
			case 'backup.manifest':
				$decoded = json_decode( (string) $raw, true );
				if ( ! is_array( $decoded ) ) {
					return new \WP_Error( 'cntr_settings_bad_value', __( 'Must be a JSON array of table names.', 'counter' ) );
				}
				$unknown = array_diff( $decoded, Install::expected_tables() );
				if ( ! empty( $unknown ) ) {
					return new \WP_Error( 'cntr_settings_bad_value', sprintf( /* translators: %s: comma-separated list of unrecognised table names */ __( 'Not a recognised table: %s', 'counter' ), implode( ', ', $unknown ) ) );
				}
				break;
		}
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function save( string $key, $raw ) {
		$check = self::validate( $key, $raw );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		SettingsModel::set( $key, $raw );
		// C7 — a POS-tab key is read into a terminal's own long-lived
		// session (pos.php's bootstrap, or a value cached client-side); the
		// terminal's poll loop already watches catalog_rev for a reason to
		// re-sync, so a settings change borrows the same signal rather than
		// requiring a manual till reload to ever be noticed at all.
		if ( in_array( $key, self::CATALOG_NOTICE_KEYS, true ) ) {
			Db::next_seq( 'catalog_rev' );
		}
		return true;
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_manage_settings' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'business'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector
		if ( ! isset( self::TABS[ $tab ] ) ) {
			$tab = 'business';
		}

		$errors = [];
		$saved  = false;

		if ( isset( $_POST['cntr_settings_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines below
			check_admin_referer( 'cntr_settings_save_' . $tab );
			foreach ( self::TAB_KEYS[ $tab ] as $key ) {
				[ , $type ] = SettingsModel::defaults()[ $key ];
				$raw    = 'bool' === $type
					? ! empty( $_POST[ $key ] )
					: sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
				$result = self::save( $key, $raw );
				if ( is_wp_error( $result ) ) {
					$errors[ $key ] = $result->get_error_message();
				}
			}
			$saved = empty( $errors );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Settings', 'counter' ) . '</h1>';

		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'counter' ) . '</p></div>';
		} elseif ( $errors ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Some values were not saved:', 'counter' ) . '</p><ul>';
			foreach ( $errors as $key => $message ) {
				printf( '<li><code>%s</code> — %s</li>', esc_html( $key ), esc_html( $message ) );
			}
			echo '</ul></div>';
		}

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::TABS as $key => $label ) {
			$url = add_query_arg( [ 'page' => 'counter-settings', 'tab' => $key ], admin_url( 'admin.php' ) );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		echo '<form method="post">';
		wp_nonce_field( 'cntr_settings_save_' . $tab );
		echo '<input type="hidden" name="cntr_settings_action" value="save">';
		echo '<table class="form-table"><tbody>';
		foreach ( self::TAB_KEYS[ $tab ] as $key ) {
			self::render_field( $key );
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save changes', 'counter' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	private static function render_field( string $key ): void {
		[ $default, $type ] = SettingsModel::defaults()[ $key ];
		$value = SettingsModel::get( $key, $default );
		$id    = 'cntr-set-' . str_replace( '.', '-', $key );
		$label = self::label_for( $key );

		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';

		if ( 'backup.manifest' === $key ) {
			$checked = json_decode( (string) $value, true );
			$checked = is_array( $checked ) ? $checked : [];
			foreach ( Install::expected_tables() as $table ) {
				printf(
					'<label style="display:inline-block;width:220px;"><input type="checkbox" class="cntr-backup-table" value="%1$s"%2$s> %1$s</label>',
					esc_attr( $table ),
					in_array( $table, $checked, true ) ? ' checked' : ''
				);
			}
			printf( '<input type="hidden" id="%s" name="%s" value="%s">', esc_attr( $id ), esc_attr( $key ), esc_attr( wp_json_encode( array_values( $checked ) ) ) );
			echo '<p class="description">' . esc_html__( 'Confirm which tables your backup actually covers — this is a declared record, not a live check.', 'counter' ) . '</p>';
			echo '<script>(function(){var box=document.getElementById(' . wp_json_encode( $id ) . ');function sync(){var checks=document.querySelectorAll(".cntr-backup-table:checked");var vals=[];checks.forEach(function(c){vals.push(c.value);});box.value=JSON.stringify(vals);}document.querySelectorAll(".cntr-backup-table").forEach(function(c){c.addEventListener("change",sync);});})();</script>';
		} elseif ( 'bool' === $type ) {
			printf( '<input type="checkbox" id="%s" name="%s" value="1"%s>', esc_attr( $id ), esc_attr( $key ), $value ? ' checked' : '' );
		} elseif ( 'cash.rounding_mode' === $key ) {
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">';
			foreach ( self::ROUNDING_MODES as $mode ) {
				printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $mode ), selected( $value, $mode, false ) );
			}
			echo '</select>';
		} elseif ( 'pos.keyboard_map' === $key ) {
			printf( '<textarea id="%s" name="%s" class="large-text code" rows="3">%s</textarea>', esc_attr( $id ), esc_attr( $key ), esc_textarea( (string) $value ) );
			echo '<p class="description">' . esc_html__( 'JSON object mapping a key name to an action (e.g. "F2":"pay").', 'counter' ) . '</p>';
		} else {
			printf( '<input type="text" id="%s" name="%s" class="regular-text" value="%s">', esc_attr( $id ), esc_attr( $key ), esc_attr( (string) $value ) );
		}

		echo '</td></tr>';
	}

	private static function label_for( string $key ): string {
		$labels = [
			'shop.name'                        => __( 'Shop name', 'counter' ),
			'shop.bin'                         => __( 'BIN', 'counter' ),
			'shop.address'                     => __( 'Address', 'counter' ),
			'shop.phone'                       => __( 'Phone', 'counter' ),
			'shop.footer_text'                 => __( 'Receipt footer text', 'counter' ),
			'cash.rounding_step'               => __( 'Cash rounding step (৳)', 'counter' ),
			'cash.rounding_mode'               => __( 'Cash rounding mode', 'counter' ),
			'credit.default_due_days'          => __( 'Default credit due days', 'counter' ),
			'reports.dead_stock_days'          => __( 'Dead stock threshold (days)', 'counter' ),
			'pos.default_register'             => __( 'Default register id', 'counter' ),
			'pos.search_fields'                => __( 'Search fields', 'counter' ),
			'pos.discount_ceiling_pct'         => __( 'Discount ceiling (%)', 'counter' ),
			'pos.weight_barcode_prefix'        => __( 'Weight barcode prefix digit', 'counter' ),
			'pos.keyboard_map'                 => __( 'Keyboard shortcuts', 'counter' ),
			'stock.allow_negative_pos'         => __( 'Allow the till to sell past zero stock', 'counter' ),
			'stock.online_buffer'              => __( 'Online stock buffer (units)', 'counter' ),
			'stock.adjustment_note_threshold'  => __( 'Adjustment note required above (৳)', 'counter' ),
			'vat.enabled'                      => __( 'VAT enabled', 'counter' ),
			'vat.rate'                         => __( 'VAT rate (%)', 'counter' ),
			'vat.challan_prefix'               => __( 'Challan number prefix', 'counter' ),
			'vat.challan_start'                => __( 'Challan number starting value', 'counter' ),
			'print.receipt_template_id'        => __( 'Receipt template id', 'counter' ),
			'print.label_template_id'          => __( 'Label template id', 'counter' ),
			'backup.manifest'                  => __( 'Tables covered by your backup', 'counter' ),
		];
		return $labels[ $key ] ?? $key;
	}
}
