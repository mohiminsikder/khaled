<?php
namespace Counter\Admin\Screens;

use Counter\Stock\Locations;
use Counter\Rest\Stock;
use Counter\Admin\EntityPicker;

defined( 'ABSPATH' ) || exit;

/**
 * P2.2 — a thin wp-admin form over POST /counter/v1/stock/adjust. The form
 * itself does no writing: it generates a client uuid and POSTs through the
 * same REST route a terminal or any other caller would use, so this screen
 * can never drift from Rest\Stock::process()'s own validation.
 */
class Adjust {

	public static function init(): void {
		add_action( 'cntr_admin_menu', [ self::class, 'register_menu' ] );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'counter',
			__( 'Adjust Stock', 'counter' ),
			__( 'Adjust Stock', 'counter' ),
			'cntr_adjust_stock',
			'counter-adjust',
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'cntr_adjust_stock' ) ) {
			wp_die( esc_html__( 'Not permitted.', 'counter' ) );
		}

		$locations = Locations::all( 'active' );
		$rest_url  = esc_url_raw( rest_url( 'counter/v1/stock/adjust' ) );
		$nonce     = wp_create_nonce( 'wp_rest' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Counter — Adjust Stock', 'counter' ) . '</h1>';
		echo '<p>' . esc_html__( 'A correction writes a new ledger move; it never edits a balance. Pick a reason honestly — "adjust" is a generic correction (a miscount, a data-entry fix), "waste" and "damage" are shrinkage with a cause.', 'counter' ) . '</p>';

		echo '<form id="cntr-adjust-form" onsubmit="return false;">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="cntr-adj-location">' . esc_html__( 'Location', 'counter' ) . '</label></th><td><select id="cntr-adj-location">';
		foreach ( $locations as $loc ) {
			printf( '<option value="%d">%s</option>', (int) $loc['id'], esc_html( $loc['name'] ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="cntr-adj-product-input">' . esc_html__( 'Product', 'counter' ) . '</label></th><td>';
		EntityPicker::render(
			[
				'id'          => 'cntr-adj-product',
				'hidden_name' => 'product_id',
				'type'        => 'product',
				'placeholder' => __( 'Type a product name or SKU…', 'counter' ),
				'required'    => true,
			]
		);
		echo '</td></tr>';

		echo '<tr><th><label for="cntr-adj-variation-input">' . esc_html__( 'Variation (leave blank for a simple product)', 'counter' ) . '</label></th><td>';
		EntityPicker::render(
			[
				'id'           => 'cntr-adj-variation',
				'hidden_name'  => 'variation_id',
				'type'         => 'variation',
				'placeholder'  => __( 'Pick a product first…', 'counter' ),
				'parent_field' => 'cntr-adj-product',
			]
		);
		echo '</td></tr>';
		echo '<tr><th><label for="cntr-adj-qty">' . esc_html__( 'Quantity change', 'counter' ) . '</label></th><td><input type="number" id="cntr-adj-qty" step="any" placeholder="' . esc_attr__( 'e.g. -3 or 10', 'counter' ) . '" required></td></tr>';

		echo '<tr><th><label for="cntr-adj-reason">' . esc_html__( 'Reason', 'counter' ) . '</label></th><td><select id="cntr-adj-reason">';
		foreach ( Stock::REASONS as $reason ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $reason ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="cntr-adj-note">' . esc_html__( 'Note', 'counter' ) . '</label></th><td><textarea id="cntr-adj-note" rows="3" class="large-text"></textarea>';
		echo '<p class="description">' . esc_html__( 'Required for any adjustment above the configured value threshold.', 'counter' ) . '</p></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="button" class="button button-primary" id="cntr-adj-submit">' . esc_html__( 'Write adjustment', 'counter' ) . '</button></p>';
		echo '<div id="cntr-adj-result"></div>';
		echo '</form>';
		echo '</div>';

		self::script( $rest_url, $nonce );
	}

	private static function script( string $rest_url, string $nonce ): void {
		?>
		<script>
		( function () {
			var btn = document.getElementById( 'cntr-adj-submit' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var out = document.getElementById( 'cntr-adj-result' );
				out.textContent = <?php echo wp_json_encode( __( 'Sending…', 'counter' ) ); ?>;

				var uuid = ( window.crypto && window.crypto.randomUUID )
					? window.crypto.randomUUID()
					: 'cntr-' + Date.now() + '-' + Math.random().toString( 16 ).slice( 2 );

				var body = {
					uuid: uuid,
					product_id: parseInt( document.getElementById( 'cntr-adj-product' ).value, 10 ) || 0,
					variation_id: parseInt( document.getElementById( 'cntr-adj-variation' ).value, 10 ) || 0,
					location_id: parseInt( document.getElementById( 'cntr-adj-location' ).value, 10 ) || 0,
					qty_delta: document.getElementById( 'cntr-adj-qty' ).value,
					reason: document.getElementById( 'cntr-adj-reason' ).value,
					note: document.getElementById( 'cntr-adj-note' ).value
				};

				fetch( <?php echo wp_json_encode( $rest_url ); ?>, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': <?php echo wp_json_encode( $nonce ); ?>
					},
					body: JSON.stringify( body )
				} )
					.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } ); } )
					.then( function ( res ) {
						out.textContent = JSON.stringify( res.j, null, 2 );
						if ( res.ok && res.j && res.j.flagged_negative ) {
							out.textContent += <?php echo wp_json_encode( "\n\n" . __( 'Note: the balance went below zero and is flagged for review.', 'counter' ) ); ?>;
						}
					} )
					.catch( function ( e ) { out.textContent = 'Error: ' + e.message; } );
			} );
		} )();
		</script>
		<?php
	}
}
