<?php
namespace Counter\Docs;

defined( 'ABSPATH' ) || exit;

/**
 * HTML rendered for a hidden <iframe> the terminal prints with @page { size:
 * 79mm auto; margin: 0 } then print(). The browser rasterises it and hands
 * pixels to the Windows driver — which is exactly why ৳ and Bengali render
 * correctly. Raw ESC/POS is where they stop rendering; that is the whole
 * argument for this mechanism.
 */
class Receipt {

	public static function render( \WC_Order $order ): string {
		ob_start();
		include CNTR_DIR . 'templates/receipt-79.php';
		return (string) ob_get_clean();
	}
}
