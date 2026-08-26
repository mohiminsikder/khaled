<?php
namespace Counter\Orders;

use Counter\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Audit finding T7. No drawer in Dhaka holds poisha. WooCommerce's tax engine
 * will happily produce ৳ 247.35; the customer hands over ৳ 250 and takes ৳ 3
 * back, and the drawer is short by two poisha on every sale, compounding into
 * a daily variance nobody can explain.
 *
 * Applied ONCE, after taxes, as a real fee line — never a silent adjustment
 * at tender time. It has to appear on the receipt, in the order total and in
 * the ledger, or the Z-report and the accountant will disagree and both will
 * be right.
 */
class Money {

	public static function apply_rounding( \WC_Order $order ): void {
		if ( $order->get_meta( '_cntr_rounding' ) ) {
			return; // already applied — the idempotency guard
		}

		$step = (float) Settings::get( 'cash.rounding_step' );
		if ( $step <= 0 ) {
			return; // a step of 0 disables rounding entirely
		}

		$total   = (float) $order->get_total();
		$rounded = round( $total / $step ) * $step;
		$diff    = round( $rounded - $total, 4 );

		if ( abs( $diff ) < 0.005 ) {
			return;
		}

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( __( 'Rounding', 'counter' ) );
		$fee->set_amount( $diff );
		$fee->set_total( $diff );
		$fee->set_tax_status( 'none' );
		$fee->set_total_tax( 0 );
		$order->add_item( $fee );
		$order->update_meta_data( '_cntr_rounding', $diff );
		$order->calculate_totals( false );
	}
}
