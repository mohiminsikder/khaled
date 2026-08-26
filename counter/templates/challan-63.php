<?php
/**
 * Mushak 6.3 challan (tax invoice) — A4, printed via a hidden iframe +
 * print(), same mechanism as templates/receipt-79.php.
 *
 * NOT the official NBR-issued Mushak 6.3 form graphic — a compliant-style
 * layout containing every field Direction requires (serial, both BINs,
 * itemised taxable value, VAT, date). Per this task's own Manual step, this
 * layout is UNSIGNED — pending the shop's actual VAT consultant reviewing a
 * printed copy on paper. Do not represent it as approved until that has
 * genuinely happened; see docs/decisions.md.
 *
 * @var array      $challan One row from cntr_challan_register.
 * @var \WC_Order  $order   Set by Challan::render_html() before this include.
 */
defined( 'ABSPATH' ) || exit;

$shop_name    = (string) \Counter\Settings::get( 'shop.name' );
$shop_address = (string) \Counter\Settings::get( 'shop.address' );
$shop_phone   = (string) \Counter\Settings::get( 'shop.phone' );
$shop_bin     = (string) $challan['bin'];

$lines = json_decode( (string) $challan['lines_json'], true ) ?: [];
$is_reversal = 'reversal' === $challan['kind'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	@page { size: 210mm 297mm; margin: 15mm; }
	* { box-sizing: border-box; }
	body { font-family: 'Noto Sans Bengali', 'Segoe UI', sans-serif; font-size: 12px; color: #000; }
	.cntr-ch-title { text-align: center; font-size: 16px; font-weight: 700; margin-bottom: 2px; }
	.cntr-ch-subtitle { text-align: center; font-size: 12px; margin-bottom: 10px; }
	.cntr-ch-parties { display: flex; justify-content: space-between; margin-bottom: 10px; }
	.cntr-ch-box { width: 48%; border: 1px solid #000; padding: 6px; }
	.cntr-ch-box h4 { margin: 0 0 4px; font-size: 12px; }
	table.cntr-ch-items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
	table.cntr-ch-items th, table.cntr-ch-items td { border: 1px solid #000; padding: 4px 6px; text-align: left; }
	table.cntr-ch-items th { background: #eee; }
	table.cntr-ch-totals { width: 40%; margin-left: auto; border-collapse: collapse; }
	table.cntr-ch-totals td { padding: 2px 6px; }
	table.cntr-ch-totals .cntr-ch-label { text-align: left; }
	table.cntr-ch-totals .cntr-ch-value { text-align: right; }
	.cntr-ch-grand { font-weight: 700; font-size: 14px; }
	.cntr-ch-reversal-banner { border: 2px solid #b00; color: #b00; padding: 6px; text-align: center; font-weight: 700; margin-bottom: 10px; }
	.cntr-ch-sign { margin-top: 40px; display: flex; justify-content: space-between; }
	.cntr-ch-sign div { width: 40%; border-top: 1px solid #000; padding-top: 4px; text-align: center; }
</style>
</head>
<body>
	<div class="cntr-ch-title">মূসক-৬.৩ / Mushak 6.3 — Tax Invoice</div>
	<div class="cntr-ch-subtitle">
		Serial: <strong><?php echo esc_html( $challan['serial'] ); ?></strong>
		&nbsp;|&nbsp; Date: <?php echo esc_html( wp_date( 'd-M-Y', strtotime( (string) $challan['issued_at'] ) ) ); ?>
	</div>

	<?php if ( $is_reversal ) : ?>
		<div class="cntr-ch-reversal-banner">
			REVERSAL — cancels challan seq #<?php echo (int) $challan['reverses_id']; ?>. The original challan is unchanged and remains on record.
		</div>
	<?php endif; ?>

	<div class="cntr-ch-parties">
		<div class="cntr-ch-box">
			<h4>Seller</h4>
			<?php if ( $shop_name ) : ?><div><?php echo esc_html( $shop_name ); ?></div><?php endif; ?>
			<?php if ( $shop_address ) : ?><div><?php echo esc_html( $shop_address ); ?></div><?php endif; ?>
			<?php if ( $shop_phone ) : ?><div>Phone: <?php echo esc_html( $shop_phone ); ?></div><?php endif; ?>
			<div>BIN: <?php echo esc_html( $shop_bin ?: '—' ); ?></div>
		</div>
		<div class="cntr-ch-box">
			<h4>Buyer</h4>
			<div><?php echo esc_html( $challan['buyer_name'] ); ?></div>
			<div>BIN: <?php echo esc_html( $challan['buyer_bin'] ?: '—' ); ?></div>
		</div>
	</div>

	<table class="cntr-ch-items">
		<thead><tr><th>Description</th><th>Quantity</th><th>Value (৳)</th></tr></thead>
		<tbody>
			<?php foreach ( $lines as $l ) : ?>
				<tr>
					<td><?php echo esc_html( $l['name'] ?? '' ); ?></td>
					<td><?php echo esc_html( $l['qty'] ?? '' ); ?></td>
					<td><?php echo esc_html( $l['total'] ?? '' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<table class="cntr-ch-totals">
		<tr><td class="cntr-ch-label">Taxable value</td><td class="cntr-ch-value">৳<?php echo esc_html( $challan['taxable_value'] ); ?></td></tr>
		<tr><td class="cntr-ch-label">Supplementary duty</td><td class="cntr-ch-value">৳<?php echo esc_html( $challan['sd_amount'] ); ?></td></tr>
		<tr><td class="cntr-ch-label">VAT</td><td class="cntr-ch-value">৳<?php echo esc_html( $challan['vat_amount'] ); ?></td></tr>
		<?php if ( '0.0000' !== $challan['rounding'] ) : ?>
		<tr><td class="cntr-ch-label">Rounding</td><td class="cntr-ch-value">৳<?php echo esc_html( $challan['rounding'] ); ?></td></tr>
		<?php endif; ?>
		<tr class="cntr-ch-grand"><td class="cntr-ch-label">Total</td><td class="cntr-ch-value">৳<?php echo esc_html( $challan['total'] ); ?></td></tr>
	</table>

	<div class="cntr-ch-sign">
		<div>Prepared by</div>
		<div>Authorised signature</div>
	</div>
</body>
</html>
