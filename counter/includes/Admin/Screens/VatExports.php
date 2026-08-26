<?php
namespace Counter\Admin\Screens;

use Counter\Docs\VatExports as Exports;

defined( 'ABSPATH' ) || exit;

/**
 * P3.6 — a thin download screen over Docs\VatExports' own gated export_*()
 * methods. No new data here: this page just turns a from/to form submit into
 * a streamed CSV, same "the screen cannot drift from what actually
 * validates" principle Adjust.php/LabelDesigner.php already use.
 */
class VatExports {

	const TYPES = [
		'6_1'   => 'Mushak 6.1 — Purchase-Sales Account',
		'6_2_1' => 'Mushak 6.2.1 — Purchase Register',
		'6_5'   => 'Mushak 6.5 — Account Current',
		'6_6'   => 'Mushak 6.6 — Return Summary',
	];

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'VAT Exports', 'counter' ),
			__( 'VAT Exports', 'counter' ),
			'cntr_export',
			'counter-vat-exports',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_export' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'counter' ) );
		}

		$requested = isset( $_GET['cntr_vat_export'] ) ? sanitize_key( wp_unslash( $_GET['cntr_vat_export'] ) ) : '';
		if ( '' !== $requested ) {
			check_admin_referer( 'cntr_vat_export' );
			self::stream_download(
				$requested,
				isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
				isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''
			);
			return; // stream_download() exits on success.
		}

		$from = gmdate( 'Y-m-01' );
		$to   = gmdate( 'Y-m-d' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'VAT register exports', 'counter' ); ?></h1>
			<p><?php esc_html_e( 'Spreadsheets shaped to feed your VAT consultant or an NBR-approved filing package — not a replacement for either.', 'counter' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="counter-vat-exports">
				<?php wp_nonce_field( 'cntr_vat_export' ); ?>
				<p>
					<label><?php esc_html_e( 'From', 'counter' ); ?>
						<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>">
					</label>
					&nbsp;
					<label><?php esc_html_e( 'To', 'counter' ); ?>
						<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>">
					</label>
				</p>
				<p>
					<?php foreach ( self::TYPES as $key => $label ) : ?>
						<button type="submit" class="button" name="cntr_vat_export" value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button><br><br>
					<?php endforeach; ?>
				</p>
			</form>
		</div>
		<?php
	}

	private static function stream_download( string $type, string $from, string $to ): void {
		if ( ! isset( self::TYPES[ $type ] ) ) {
			wp_die( esc_html__( 'Unknown export.', 'counter' ) );
		}

		$csv = match ( $type ) {
			'6_1'   => Exports::export_6_1( $from, $to ),
			'6_2_1' => Exports::export_6_2_1( $from, $to ),
			'6_5'   => Exports::export_6_5( $from, $to ),
			'6_6'   => Exports::export_6_6( $from, $to ),
		};
		if ( is_wp_error( $csv ) ) {
			wp_die( esc_html( $csv->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mushak-' . $type . '-' . $from . '-to-' . $to . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput -- raw CSV body, not HTML.
		exit;
	}
}
