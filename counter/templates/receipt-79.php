<?php
/**
 * 79mm thermal receipt. Plain HTML+CSS, printed via a hidden iframe + print()
 * — never raw ESC/POS, which is why ৳ and Bengali render correctly here.
 *
 * @var \WC_Order $order Set by Docs\Receipt::render() before this include.
 */
defined( 'ABSPATH' ) || exit;

$shop_name    = (string) \Counter\Settings::get( 'shop.name' );
$shop_bin     = (string) \Counter\Settings::get( 'shop.bin' );
$shop_address = (string) \Counter\Settings::get( 'shop.address' );
$shop_phone   = (string) \Counter\Settings::get( 'shop.phone' );
$footer_text  = (string) \Counter\Settings::get( 'shop.footer_text' );

$receipt_no = (string) $order->get_meta( '_cntr_receipt_no' );
$rounding   = $order->get_meta( '_cntr_rounding' );

$tenders_json = (string) $order->get_meta( '_cntr_tenders' );
$tenders      = $tenders_json ? json_decode( $tenders_json, true ) : [];

// F4 (COUNTERFRONTEND.md) — "a credit customer should leave holding what
// they now owe." Read AFTER Tenders::record() has already committed any
// shortfall (Rest\Sale::process() builds this receipt as its own step 9,
// strictly after the step-6 transaction), so this is the real post-sale
// balance, never a stale pre-sale figure.
$cntr_customer_id = (int) $order->get_customer_id();
$cntr_balance      = $cntr_customer_id > 0 ? \Counter\Credit\CustomerLedger::balance( $cntr_customer_id ) : null;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	@page { size: 79mm auto; margin: 0; }
	* { box-sizing: border-box; }
	body {
		width: 79mm;
		margin: 0;
		padding: 3mm;
		font-family: 'Noto Sans Bengali', 'Segoe UI', sans-serif;
		font-size: 11px;
		color: #000;
	}
	.cntr-r-center { text-align: center; }
	.cntr-r-shop-name { font-size: 15px; font-weight: 700; }
	.cntr-r-hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
	table.cntr-r-items { width: 100%; border-collapse: collapse; }
	table.cntr-r-items td { padding: 2px 0; vertical-align: top; }
	.cntr-r-qty { width: 24px; }
	.cntr-r-price { width: 60px; text-align: right; }
	.cntr-r-totals td { padding: 1px 0; }
	.cntr-r-totals .cntr-r-label { text-align: left; }
	.cntr-r-totals .cntr-r-value { text-align: right; }
	.cntr-r-grand { font-size: 14px; font-weight: 700; }
	.cntr-r-footer { margin-top: 6px; font-size: 10px; }
</style>
</head>
<body>
	<div class="cntr-r-center">
		<?php if ( $shop_name ) : ?><div class="cntr-r-shop-name"><?php echo esc_html( $shop_name ); ?></div><?php endif; ?>
		<?php if ( $shop_address ) : ?><div><?php echo esc_html( $shop_address ); ?></div><?php endif; ?>
		<?php if ( $shop_phone ) : ?><div><?php echo esc_html( $shop_phone ); ?></div><?php endif; ?>
		<?php if ( $shop_bin ) : ?><div>BIN: <?php echo esc_html( $shop_bin ); ?></div><?php endif; ?>
	</div>
	<hr class="cntr-r-hr">
	<div>Receipt: <?php echo esc_html( $receipt_no ); ?></div>
	<div><?php echo esc_html( wp_date( 'Y-m-d H:i', $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ) ); ?></div>
	<hr class="cntr-r-hr">
	<table class="cntr-r-items">
		<?php foreach ( $order->get_items( 'line_item' ) as $item ) : ?>
			<tr>
				<td colspan="3"><?php echo esc_html( $item->get_name() ); ?></td>
			</tr>
			<tr>
				<td class="cntr-r-qty"><?php
					// P2.12 — the unit the cashier actually chose
					// (Piece/Dozen/Box), not the order item's own base-unit
					// (and possibly WooCommerce-rounded — see
					// docs/verified-api.md) quantity.
					$cntr_unit_id  = $item->get_meta( '_cntr_unit_id' );
					$cntr_unit_qty = $item->get_meta( '_cntr_unit_qty' );
					if ( $cntr_unit_id && '' !== $cntr_unit_qty ) {
						// Frozen at sale time (_cntr_unit_name), not looked up
						// live — a later unit rename must not rewrite an old
						// receipt, same principle as _cntr_cogs.
						$qty_display = rtrim( rtrim( (string) $cntr_unit_qty, '0' ), '.' );
						$cntr_unit_name = (string) $item->get_meta( '_cntr_unit_name' );
						if ( '' !== $cntr_unit_name ) {
							$qty_display .= ' ' . $cntr_unit_name;
						}
					} else {
						$qty_display = (string) $item->get_quantity();
					}
					echo esc_html( $qty_display );
					?></td>
				<td></td>
				<td class="cntr-r-price"><?php echo esc_html( (string) $item->get_total() ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<hr class="cntr-r-hr">
	<table class="cntr-r-totals" style="width:100%">
		<tr>
			<td class="cntr-r-label">Subtotal</td>
			<td class="cntr-r-value"><?php echo esc_html( (string) $order->get_subtotal() ); ?></td>
		</tr>
		<?php if ( (float) $order->get_total_tax() > 0 ) : ?>
		<tr>
			<td class="cntr-r-label">VAT</td>
			<td class="cntr-r-value"><?php echo esc_html( (string) $order->get_total_tax() ); ?></td>
		</tr>
		<?php endif; ?>
		<?php if ( $rounding ) : ?>
		<tr>
			<td class="cntr-r-label">Rounding</td>
			<td class="cntr-r-value"><?php echo esc_html( (string) $rounding ); ?></td>
		</tr>
		<?php endif; ?>
		<tr class="cntr-r-grand">
			<td class="cntr-r-label">Total</td>
			<td class="cntr-r-value"><?php echo esc_html( (string) $order->get_total() ); ?></td>
		</tr>
	</table>
	<hr class="cntr-r-hr">
	<table class="cntr-r-totals" style="width:100%">
		<?php foreach ( (array) $tenders as $t ) : ?>
			<tr>
				<td class="cntr-r-label"><?php echo esc_html( ! empty( $t['is_change'] ) ? 'Change' : ucfirst( (string) ( $t['method'] ?? '' ) ) ); ?></td>
				<td class="cntr-r-value"><?php echo esc_html( (string) ( $t['amount'] ?? '' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>
	<?php if ( null !== $cntr_balance && 0 !== bccomp( $cntr_balance, '0', 4 ) ) : ?>
		<hr class="cntr-r-hr">
		<table class="cntr-r-totals" style="width:100%">
			<tr>
				<td class="cntr-r-label">Balance on account</td>
				<td class="cntr-r-value"><?php echo esc_html( $cntr_balance ); ?></td>
			</tr>
		</table>
	<?php endif; ?>
	<?php if ( $footer_text ) : ?>
		<div class="cntr-r-footer cntr-r-center"><?php echo esc_html( $footer_text ); ?></div>
	<?php endif; ?>
</body>
</html>
