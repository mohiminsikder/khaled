<?php
/**
 * A packing slip — what to put in the box, not what it cost. Rendered via a
 * hidden iframe + print(), same mechanism as templates/receipt-79.php.
 *
 * @var \WC_Order $order Set by Fulfilment::packing_slip_html() before this include.
 */
defined( 'ABSPATH' ) || exit;

$shop_name = (string) \Counter\Settings::get( 'shop.name' );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	@page { size: 210mm 297mm; margin: 15mm; }
	* { box-sizing: border-box; }
	body { font-family: 'Noto Sans Bengali', 'Segoe UI', sans-serif; font-size: 13px; color: #000; }
	.cntr-ps-title { text-align: center; font-size: 18px; font-weight: 700; margin-bottom: 2px; }
	.cntr-ps-sub { text-align: center; margin-bottom: 14px; }
	.cntr-ps-box { border: 1px solid #000; padding: 8px; margin-bottom: 12px; }
	.cntr-ps-box h4 { margin: 0 0 4px; }
	table.cntr-ps-items { width: 100%; border-collapse: collapse; }
	table.cntr-ps-items th, table.cntr-ps-items td { border: 1px solid #000; padding: 6px 8px; text-align: left; }
	table.cntr-ps-items th { background: #eee; }
	.cntr-ps-qty { width: 60px; text-align: center; }
</style>
</head>
<body>
	<div class="cntr-ps-title"><?php echo esc_html( $shop_name ?: __( 'Packing Slip', 'counter' ) ); ?></div>
	<div class="cntr-ps-sub">
		<?php esc_html_e( 'Packing Slip', 'counter' ); ?> &mdash; #<?php echo (int) $order->get_id(); ?>
		&nbsp;|&nbsp; <?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date( 'd-M-Y' ) : '' ); ?>
	</div>

	<div class="cntr-ps-box">
		<h4><?php esc_html_e( 'Ship to', 'counter' ); ?></h4>
		<div><?php echo esc_html( trim( $order->get_formatted_shipping_full_name() ) ?: trim( $order->get_formatted_billing_full_name() ) ); ?></div>
		<div><?php echo wp_kses_post( nl2br( $order->get_shipping_address_1() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address() ) ); ?></div>
		<?php if ( $order->get_billing_phone() ) : ?><div><?php esc_html_e( 'Phone', 'counter' ); ?>: <?php echo esc_html( $order->get_billing_phone() ); ?></div><?php endif; ?>
	</div>

	<table class="cntr-ps-items">
		<thead><tr><th><?php esc_html_e( 'Item', 'counter' ); ?></th><th class="cntr-ps-qty"><?php esc_html_e( 'Qty', 'counter' ); ?></th></tr></thead>
		<tbody>
			<?php foreach ( $order->get_items( 'line_item' ) as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item->get_name() ); ?><?php echo '0' === (string) wc_format_decimal( $item->get_total(), 2 ) ? ' <em>(' . esc_html__( 'free', 'counter' ) . ')</em>' : ''; ?></td>
					<td class="cntr-ps-qty"><?php echo esc_html( (string) $item->get_quantity() ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</body>
</html>
