<?php
namespace Counter\Orders;

use Counter\Stock\Locations;

defined( 'ABSPATH' ) || exit;

/**
 * The sequence matters here, and getting it out of order silently discards
 * line discounts: item subtotal/total are set BEFORE calculate_taxes(), and
 * calculate_totals(false) never re-derives item totals from product prices —
 * confirmed against the installed WooCommerce (docs/verified-api.md item 7)
 * before relying on it; test_order_builder() check 2 asserts it at runtime
 * forever after, so a calculate_totals(true) slipped in during a later
 * refactor fails the suite instead of silently erasing every line discount.
 */
class Builder {

	/**
	 * $lines: [ [ 'product' => WC_Product, 'qty' => float|string,
	 *             'subtotal' => string, 'total' => string ], ... ]
	 * $context: register_id, shift_id, uuid, location_id, operator_id,
	 *           customer_id, receipt_no — receipt_no is TERMINAL-allocated
	 *           (Invariant IV / the v1.1 C2 fix: a server-only sequence would
	 *           not work while offline), so Builder only records whatever the
	 *           caller already computed — it never generates one itself.
	 */
	/**
	 * $before_save (P2.6) runs after calculate_totals()/rounding, with a real
	 * order total available, but BEFORE the final $order->save(). Confirmed
	 * live against the installed WooCommerce (HPOS): calculate_taxes() itself
	 * already assigns the order a real id and persists a partial row — the
	 * "nothing touched the database yet" assumption this parameter was first
	 * built on turned out to be false, caught by test_customer_ledger()'s own
	 * "assert no order exists" check actually finding an order. Rather than
	 * fight WooCommerce's own internals to stop that early persist, a veto
	 * (the guard throwing) explicitly deletes whatever calculate_taxes()
	 * already wrote before letting the exception propagate — the observable
	 * result callers see is still "no order exists," just reached by
	 * create-then-delete rather than never-created. Rest\Sale::process() uses
	 * this for the credit-limit check (Credit\CustomerLedger::check_limit()).
	 * Optional: every other caller (test_returns() included) passes nothing
	 * and behaves exactly as before this parameter existed.
	 */
	public static function build( array $lines, array $context, ?callable $before_save = null ): \WC_Order {
		// P2.12 — a line may carry 'unit_id' (Piece/Dozen/Box, cntr_units):
		// converted to base units HERE, once, before anything else exists —
		// Entity, Ledger and Channel never see a sub-unit at all. Validated
		// (and refused, throwing before any order object is created) up
		// front rather than partway through the loop below, so a bad line
		// never leaves a half-built order behind. A line with no 'unit_id'
		// behaves exactly as before this parameter existed — 'qty' is
		// already in base units, nothing here touches it.
		$resolved = [];
		foreach ( $lines as $l ) {
			$unit_id = (int) ( $l['unit_id'] ?? 0 );
			if ( $unit_id ) {
				$base_qty = \Counter\Stock\Units::to_base_qty( $unit_id, (string) $l['qty'] );
				if ( is_wp_error( $base_qty ) ) {
					throw new \InvalidArgumentException( $base_qty->get_error_message() );
				}
				$unit_row              = \Counter\Stock\Units::get( $unit_id );
				$l['_cntr_unit_qty']   = wc_format_decimal( $l['qty'], 4 ); // the quantity as ENTERED, in the chosen unit — for the receipt only
				$l['_cntr_unit_name']  = $unit_row['name'] ?? ''; // frozen at sale time, same principle as _cntr_cogs — a later unit rename must not rewrite an old receipt
				$l['_cntr_unit_price'] = isset( $l['unit_price'] ) && '' !== $l['unit_price']
					? wc_format_decimal( $l['unit_price'], 4 )
					: \Counter\Stock\Units::resolve_price( $l['product']->get_id(), 0, $unit_id, null, $l['product'] );
				$l['qty'] = $base_qty; // base units from here on — this is what Entity/Ledger/Channel will ever read
			}
			$resolved[] = $l;
		}

		$order = new \WC_Order();
		$order->set_created_via( 'counter-pos' );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_prices_include_tax( wc_prices_include_tax() );
		$order->set_customer_id( (int) ( $context['customer_id'] ?? 0 ) );

		foreach ( $resolved as $l ) {
			$item = new \WC_Order_Item_Product();
			$item->set_product( $l['product'] );
			$item->set_quantity( $l['qty'] );
			$item->set_subtotal( $l['subtotal'] );   // before line discount
			$item->set_total( $l['total'] );         // after line discount
			if ( isset( $l['_cntr_unit_qty'] ) ) {
				// Recorded on the order line for the receipt and nowhere
				// else (Direction) — the unit the cashier actually chose,
				// the quantity as entered in it, and its resolved price.
				$item->add_meta_data( '_cntr_unit_id', (int) $l['unit_id'] );
				$item->add_meta_data( '_cntr_unit_qty', $l['_cntr_unit_qty'] );
				$item->add_meta_data( '_cntr_unit_name', $l['_cntr_unit_name'] );
				$item->add_meta_data( '_cntr_unit_price', $l['_cntr_unit_price'] );

				// Confirmed live against the installed WooCommerce:
				// wc_stock_amount( '1.5' ) === 1 — WC_Order_Item_Product's
				// own quantity setter silently rounds to a whole number,
				// no filter or workaround short of this. set_quantity()
				// above is still called (WC's own totals/display machinery
				// expects SOME value there), but it cannot be trusted to
				// carry a fractional allow_decimal=1 quantity — this meta
				// is the exact figure Channel::apply_stock() actually reads
				// for the ledger move. See docs/verified-api.md.
				$item->add_meta_data( '_cntr_base_qty', $l['qty'] );
			}
			$order->add_item( $item );
		}

		$order->set_payment_method( 'cntr_pos' );
		$order->set_payment_method_title( __( 'Counter', 'counter' ) );

		// Written before calculate_taxes(): Tax::pos_tax_location() reads
		// _cntr_channel/_cntr_location_id straight off this order object, and
		// the filter only sees what's already set on it at that point —
		// update_meta_data() populates the in-memory prop immediately, no
		// intermediate save() required for the filter to see it.
		$order->update_meta_data( '_cntr_channel', 'pos' );
		$order->update_meta_data( '_cntr_register_id', (int) ( $context['register_id'] ?? 0 ) );
		$order->update_meta_data( '_cntr_shift_id', (int) ( $context['shift_id'] ?? 0 ) );
		$order->update_meta_data( '_cntr_uuid', (string) ( $context['uuid'] ?? '' ) );
		$order->update_meta_data( '_cntr_location_id', (int) ( $context['location_id'] ?? Locations::default_id() ) );
		$order->update_meta_data( '_cntr_operator_id', (int) ( $context['operator_id'] ?? 0 ) );
		$order->update_meta_data( '_cntr_receipt_no', (string) ( $context['receipt_no'] ?? '' ) );
		// B2 — which price group (if any) actually applied to this sale, so
		// a receipt or a later report can say "billed wholesale" rather than
		// leaving that only inferable from comparing prices by hand. 0 is
		// the ordinary case — the register's own price, no group involved.
		$order->update_meta_data( '_cntr_price_group_id', (int) ( $context['price_group_id'] ?? 0 ) );
		if ( ! empty( $context['offline'] ) ) {
			$order->update_meta_data( '_cntr_offline', 1 );
		}

		// B4 — the totals footer's own order-level figures, all resolved
		// BEFORE calculate_totals() below so WooCommerce's own totals
		// machinery folds them in on its one real pass, same discipline
		// the class docblock already states for line discounts.
		//
		// The discount is spread across each line's own `total` (never
		// `subtotal`, which stays the pre-discount figure) proportionally
		// to that line's share of the pre-discount total — this IS what a
		// real WooCommerce discount looks like structurally (discount_total
		// is computed from exactly this subtotal/total gap; a real coupon
		// applied through WC_Cart does the identical thing under the hood),
		// as opposed to "a fudged line": a fake extra order line item
		// carrying a negative price to represent the discount, which is
		// what this explicitly is NOT. The remainder (rounding) lands on
		// the last line so nothing is lost to float division. A
		// WC_Order_Item_Coupon is added alongside purely as a labelled
		// record of why the total moved — it does not itself drive the
		// arithmetic above, since this is a POS discretionary discount, not
		// a real registered coupon code.
		$order_discount = wc_format_decimal( $context['order_discount'] ?? '0', 4 );
		if ( bccomp( $order_discount, '0', 4 ) > 0 ) {
			$items      = array_values( $order->get_items( 'line_item' ) );
			$sum_totals = '0';
			foreach ( $items as $item ) {
				$sum_totals = bcadd( $sum_totals, $item->get_total(), 4 );
			}
			if ( bccomp( $sum_totals, '0', 4 ) > 0 ) {
				$applied = '0';
				$count   = count( $items );
				foreach ( $items as $i => $item ) {
					$is_last = ( $count - 1 === $i );
					$share   = $is_last
						? bcsub( $order_discount, $applied, 4 )
						: wc_format_decimal( bcdiv( bcmul( $order_discount, $item->get_total(), 4 ), $sum_totals, 8 ), 4 );
					$applied = bcadd( $applied, $share, 4 );
					$item->set_total( bcsub( $item->get_total(), $share, 4 ) );
				}
			}
			$coupon = new \WC_Order_Item_Coupon();
			$coupon->set_code( 'pos-discount' );
			$coupon->set_discount( $order_discount );
			$coupon->set_discount_tax( '0' );
			$order->add_item( $coupon );
		}
		$order_tax = wc_format_decimal( $context['order_tax'] ?? '0', 4 );
		if ( bccomp( $order_tax, '0', 4 ) > 0 ) {
			$tax_fee = new \WC_Order_Item_Fee();
			$tax_fee->set_name( __( 'Order tax', 'counter' ) );
			$tax_fee->set_amount( $order_tax );
			$tax_fee->set_total( $order_tax );
			$tax_fee->set_tax_status( 'none' );
			$tax_fee->set_total_tax( '0' );
			$order->add_item( $tax_fee );
		}
		$shipping_amount = wc_format_decimal( $context['shipping'] ?? '0', 4 );
		if ( bccomp( $shipping_amount, '0', 4 ) > 0 ) {
			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( __( 'Shipping', 'counter' ) );
			$shipping_item->set_total( $shipping_amount );
			$order->add_item( $shipping_item );
		}

		$order->calculate_taxes();          // uses the POS tax location filter above
		$order->calculate_totals( false );  // false = do NOT recalculate taxes a second time

		Money::apply_rounding( $order );

		// B6 — a sale document ('cntr-draft'/'cntr-quotation', Orders\Channel's
		// own DOCUMENT_STATUSES) is built through this EXACT same pricing/tax/
		// discount pipeline as a real sale, then simply left in a status
		// Channel::init() never wires to apply_stock() — nothing below this
		// point needs to know or care that it isn't a real sale yet. Absent
		// (every caller before this task), $order->save() below persists
		// whatever WooCommerce's own default new-order status already is,
		// exactly as before this parameter existed; Rest\Sale::process() sets
		// 'completed' itself afterward, same as always.
		if ( isset( $context['document_status'] ) && '' !== $context['document_status'] ) {
			$order->set_status( (string) $context['document_status'] );
		}

		if ( null !== $before_save ) {
			try {
				$before_save( $order );
			} catch ( \Throwable $e ) {
				if ( $order->get_id() ) {
					$order->delete( true );
				}
				throw $e;
			}
		}

		$order->save();

		return $order;
	}
}
