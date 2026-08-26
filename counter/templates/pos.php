<?php
/**
 * Full-screen till. No theme, no admin bar — deliberately does not call
 * wp_head()/wp_footer(), which would pull in the active theme's own hooks.
 * Only the scripts/styles Counter itself enqueues (via Pos\Assets, gated on
 * cntr_is_pos_screen) are printed, through the WP_Scripts/WP_Styles registry
 * directly rather than the theme template hierarchy.
 */

defined( 'ABSPATH' ) || exit;

$main_id = \Counter\Stock\Locations::default_id();

// Which register this till physically is. Deliberately no register-picker
// UI at all — a real till is a dedicated machine bookmarked to its own
// /pos/?register=<id>; falls back to the first active register otherwise.
// (F9 — confirmed still the intended design, not a leftover gap.)
$requested_register_id = isset( $_GET['register'] ) ? absint( wp_unslash( $_GET['register'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route selection, not a state change
$register               = $requested_register_id ? \Counter\Pos\Registers::get( $requested_register_id ) : null;
if ( ! $register ) {
	$active_registers = \Counter\Pos\Registers::all( 'active' );
	$register          = $active_registers[0] ?? null;
}
$register_id     = $register ? (int) $register['id'] : 0;
$register_prefix = $register ? (string) $register['prefix'] : 'R0';

$bootstrap = [
	'restUrl'             => esc_url_raw( rest_url( 'counter/v1' ) ),
	'nonce'                => wp_create_nonce( 'wp_rest' ),
	'version'              => CNTR_VERSION, // F9 — read by pos.js's own daily perf-summary POST, not a display value
	'defaultLocationId'    => $main_id,
	'registerId'           => $register_id,
	'registerPrefix'       => $register_prefix,
	'shiftId'              => 0, // resolved client-side via GET /shift/current, then opened if none
	'weightBarcodePrefix'  => (string) \Counter\Settings::get( 'pos.weight_barcode_prefix', '2' ),
	'searchFields'         => (string) \Counter\Settings::get( 'pos.search_fields', 'sku,barcode,name' ),
	'discountCeilingPct'   => (string) \Counter\Settings::get( 'pos.discount_ceiling_pct', 0 ),
	'roundingStep'         => (string) \Counter\Settings::get( 'cash.rounding_step', 1 ),
	// Capability gates the terminal itself has to enforce client-side —
	// voiding a line, discounting, overriding a price, no-sale and
	// quick-add never round-trip the server before acting, so pos.js needs
	// to know the answer up front rather than trying-and-failing.
	'caps'                 => [
		'cntr_void_line'      => current_user_can( 'cntr_void_line' ),
		'cntr_discount_line'  => current_user_can( 'cntr_discount_line' ),
		'cntr_price_override' => current_user_can( 'cntr_price_override' ),
		'cntr_no_sale'        => current_user_can( 'cntr_no_sale' ),
		'cntr_manage_stock'   => current_user_can( 'cntr_manage_stock' ),
	],
	// F7 (COUNTERFRONTEND.md) — the quick-add form's own unit picker.
	// Shop-wide and rarely changes mid-shift, so baked into the bootstrap
	// once at page load rather than a dedicated REST route — same
	// static-for-the-session shape as registerPrefix/caps above.
	'units'                => array_map(
		static fn( $u ) => [ 'id' => (int) $u['id'], 'code' => (string) $u['code'], 'name' => (string) $u['name'] ],
		\Counter\Stock\Units::all( 'active' )
	),
	// B2 — the price-group picker's own options. Same shape/reasoning as
	// units above: a shop's price groups are hand-curated and rarely
	// change mid-shift, so baked in once rather than a dedicated route.
	// Which one is ACTIVE for a given sale is a client-side concern
	// (cart.priceGroupId) — this is only ever the list to choose from.
	'priceGroups'          => array_map(
		static fn( $g ) => [ 'id' => (int) $g['id'], 'name' => (string) $g['name'], 'code' => (string) $g['code'] ],
		\Counter\Pricing\Groups::all( 'active' )
	),
	// U2 (COUNTERFRONTEND.md) — "a cashier should be able to run the
	// terminal entirely in Bengali" (P8.3), never actually possible before
	// this: pos.js had no translation mechanism at all. Every key here is
	// read by pos.js's own STRINGS object (assets/pos.js) — the SAME keys,
	// kept in sync by hand since there is no build step to generate one
	// from the other. A %word% inside a value is a placeholder pos.js
	// fills in with fmt(); left untouched by a translation, moved if the
	// translated sentence needs it in a different position.
	'strings'              => [
		'netOnline'                => __( 'Online', 'counter' ),
		'netOffline'               => __( 'Offline', 'counter' ),
		'outboxLocked'             => __( 'Offline too long — reconnect to keep selling', 'counter' ),
		'shiftLabel'               => __( 'Shift', 'counter' ),
		'panelCustomerTitle'       => __( 'Customer', 'counter' ),
		'panelCartTitle'           => __( 'Cart', 'counter' ),
		'walkInPlaceholder'        => __( 'Walk-in — F6 to attach a customer', 'counter' ),
		'customerOwes'             => __( 'Owes', 'counter' ),
		'customerLimit'            => __( 'Limit', 'counter' ),
		'customerAvailable'        => __( 'Available', 'counter' ),
		/* translators: %n% is a number of days */
		'customerOldestDue'        => __( 'Oldest due %n% days', 'counter' ),
		'clearCustomerAria'        => __( 'Clear customer', 'counter' ),
		'usualItemsLabel'          => __( 'Usual:', 'counter' ),
		'searchPlaceholder'        => __( 'Scan or type SKU / barcode / name', 'counter' ),
		'cartTotal'                => __( 'Total:', 'counter' ),
		'outOfStockBadge'          => __( 'Out of stock', 'counter' ),
		'decreaseQtyAria'          => __( 'Decrease quantity', 'counter' ),
		'increaseQtyAria'          => __( 'Increase quantity', 'counter' ),
		'removeLineAria'           => __( 'Remove line', 'counter' ),
		'keyPay'                   => __( 'Pay', 'counter' ),
		'keyQty'                   => __( 'Qty', 'counter' ),
		'keyDiscount'              => __( 'Discount', 'counter' ),
		'keyNewItem'               => __( 'New item', 'counter' ),
		'keyCustomer'              => __( 'Customer', 'counter' ),
		'keyHold'                  => __( 'Hold', 'counter' ),
		'keyResume'                => __( 'Resume', 'counter' ),
		'keyReturn'                => __( 'Return', 'counter' ),
		'keyNoSale'                => __( 'No-sale', 'counter' ),
		'keyVoid'                  => __( 'Void', 'counter' ),

		'searchNoMatches'          => __( 'No matches for', 'counter' ),

		'attachCustomerTitle'      => __( 'Attach customer', 'counter' ),
		'customerPhoneLabel'       => __( 'Customer phone', 'counter' ),
		'findBtn'                  => __( 'Find', 'counter' ),
		'cancelBtn'                => __( 'Cancel', 'counter' ),
		'multipleMatches'          => __( 'More than one match — pick the right one.', 'counter' ),
		'lastOrderPrefix'          => __( 'Last order', 'counter' ),
		/* translators: %phone% is the phone number that was searched */
		'noCustomerFound'          => __( 'No customer found for %phone%.', 'counter' ),
		'billAsWalkin'             => __( 'Bill as walk-in', 'counter' ),
		'noNamePlaceholder'        => __( '(no name)', 'counter' ),

		'takePaymentTitle'         => __( 'Take payment —', 'counter' ),
		'addAnotherMethod'         => __( 'Add another method', 'counter' ),
		'tenderDue'                => __( 'Due:', 'counter' ),
		'tenderTaken'              => __( 'Taken:', 'counter' ),
		'tenderRemaining'          => __( 'Remaining:', 'counter' ),
		'tenderChange'             => __( 'Change:', 'counter' ),
		'takePaymentPrint'         => __( 'Take payment & print', 'counter' ),
		'creditOnAccount'          => __( 'Credit (on account)', 'counter' ),
		'removeTenderRowAria'      => __( 'Remove tender row', 'counter' ),
		'offlineCreditNote'        => __( "Offline — a credit tender's limit is checked once this sale syncs, not now.", 'counter' ),
		/* translators: %after% is a formatted money amount */
		'accountAfterSaleNoLimit'  => __( 'On account after this sale: %after% (no credit limit set).', 'counter' ),
		/* translators: %after% and %limit% are formatted money amounts */
		'accountAfterSaleWithLimit' => __( 'On account after this sale: %after% of %limit% limit.', 'counter' ),
		/* translators: %available% is a formatted money amount */
		'exceedsAvailableCredit'   => __( "Exceeds this customer's available credit (%available% left).", 'counter' ),
		'creditExceedsDue'         => __( 'Credit cannot exceed the total due.', 'counter' ),
		'methodCash'               => __( 'Cash', 'counter' ),
		'methodCard'               => __( 'Card', 'counter' ),
		'methodBkash'              => __( 'bKash', 'counter' ),
		'methodNagad'              => __( 'Nagad', 'counter' ),
		'methodRocket'             => __( 'Rocket', 'counter' ),
		'methodBank'               => __( 'Bank', 'counter' ),
		'methodChange'             => __( 'Change', 'counter' ),

		'changeQtyTitle'           => __( 'Change quantity —', 'counter' ),
		'qtyLabel'                 => __( 'Quantity', 'counter' ),
		'applyBtn'                 => __( 'Apply', 'counter' ),
		'removeLineConfirm'        => __( 'Remove this line?', 'counter' ),

		'discountTitle'            => __( 'Discount —', 'counter' ),
		'amountOffLabel'           => __( 'Amount off (৳)', 'counter' ),
		'percentOffLabel'          => __( 'Percent off (%)', 'counter' ),
		'applyDiscountBtn'         => __( 'Apply discount', 'counter' ),
		'overridePriceLabel'       => __( 'Override price to (৳)', 'counter' ),
		'applyOverrideBtn'         => __( 'Apply override', 'counter' ),
		/* translators: %pct% and %ceiling% are percentages, e.g. "11.1" */
		'discountAboveCeiling'     => __( "That's %pct%% off — above the %ceiling%% ceiling.", 'counter' ),

		'noSaleTitle'              => __( 'No sale — open drawer', 'counter' ),
		'reasonLabel'              => __( 'Reason', 'counter' ),
		'openDrawerPrint'          => __( 'Open drawer & print', 'counter' ),
		'reasonRequired'           => __( 'A reason is required.', 'counter' ),
		'noSaleFailed'             => __( 'Could not record the no-sale — try again once the till is back online.', 'counter' ),
		'noSaleSlipTitle'          => __( 'NO SALE — DRAWER OPENED', 'counter' ),

		'quickAddTitle'            => __( 'Quick-add a product', 'counter' ),
		'nameLabel'                => __( 'Name', 'counter' ),
		'priceLabel'               => __( 'Price (৳)', 'counter' ),
		'barcodeSkuLabel'          => __( 'Barcode / SKU', 'counter' ),
		'unitLabel'                => __( 'Unit', 'counter' ),
		'openingQtyLabel'          => __( 'Opening quantity', 'counter' ),
		'addProductBtn'            => __( 'Add product', 'counter' ),
		'quickAddValidation'       => __( 'A name and a positive price are required.', 'counter' ),
		'quickAddFailed'           => __( 'Could not add this product — try again.', 'counter' ),

		'heldSalesTitle'           => __( 'Held sales', 'counter' ),
		'justNow'                  => __( 'just now', 'counter' ),
		/* translators: %n% is a number of minutes */
		'minsAgo'                  => __( '%n%m ago', 'counter' ),
		/* translators: %h% is hours, %n% is minutes */
		'hoursMinsAgo'             => __( '%h%h %n%m ago', 'counter' ),
		'replaceCartConfirm'       => __( 'This will replace the current cart with the held ticket. Continue?', 'counter' ),
		'walkIn'                   => __( 'Walk-in', 'counter' ),
		'itemSingular'             => __( 'item', 'counter' ),
		'itemPlural'               => __( 'items', 'counter' ),

		'returnExchangeTitle'      => __( 'Return / exchange', 'counter' ),
		'receiptNumberLabel'       => __( 'Original receipt number', 'counter' ),
		'couldNotFindReceipt'      => __( 'Could not find that receipt.', 'counter' ),
		'returnTitlePrefix'        => __( 'Return — receipt', 'counter' ),
		'ofPrefix'                 => __( 'of', 'counter' ),
		'remainingSuffix'          => __( 'left', 'counter' ),
		'refundTotalLabel'         => __( 'Refund total:', 'counter' ),
		'methodLabel'              => __( 'Method', 'counter' ),
		'refundPrintBtn'           => __( 'Refund & print', 'counter' ),
		'selectAtLeastOneItem'     => __( 'Select at least one item to return.', 'counter' ),
		'registerOfflineReturn'    => __( 'This register is offline — a return needs a live connection. Try again once reconnected.', 'counter' ),
		'returnRefused'            => __( 'The return was refused.', 'counter' ),
		'returnReceiptTitle'       => __( 'RETURN — against receipt', 'counter' ),
		'refundedLabel'            => __( 'Refunded:', 'counter' ),
		'reasonPrefix'             => __( 'Reason:', 'counter' ),

		'capDenyDiscountLine'      => __( "You don't have permission to discount this line.", 'counter' ),
		'capDenyNoSale'            => __( "You don't have permission to record a no-sale.", 'counter' ),
		'capDenyVoidLine'          => __( "You don't have permission to void a line.", 'counter' ),
		'capDenyManageStock'       => __( "You don't have permission to quick-add a product.", 'counter' ),

		'clearCartConfirm'         => __( 'Clear the entire cart?', 'counter' ),

		'provisionalBillTitle'     => __( 'PROVISIONAL BILL — NOT A VAT INVOICE', 'counter' ),
		'offlineSaleNotice'        => __( 'This sale was rung offline. Its official Mushak 6.3 challan issues once the till reconnects and this receipt syncs.', 'counter' ),
		'receiptLabel'             => __( 'Receipt:', 'counter' ),
		'offlinePendingSync'       => __( '(offline — pending sync)', 'counter' ),
		'paidNowLabel'             => __( 'Paid now:', 'counter' ),
		/* translators: %amount% is a formatted money amount */
		'onAccountOfflineNote'     => __( 'On account: %amount% — the credit limit will be checked when this sale syncs, not now.', 'counter' ),

		'noShiftOpenTitle'         => __( 'No shift is open on this register', 'counter' ),
		'openingFloatLabel'        => __( 'Opening float (৳)', 'counter' ),
		'openShiftBtn'             => __( 'Open shift', 'counter' ),
	],
];

do_action( 'wp_enqueue_scripts' ); // fires Pos\Assets::maybe_enqueue(), gated true only here via cntr_is_pos_screen
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
	<title><?php esc_html_e( 'Counter', 'counter' ); ?></title>
	<script>window.CNTR = <?php echo wp_json_encode( $bootstrap ); ?>;</script>
	<?php wp_print_styles(); ?>
</head>
<body class="cntr-pos-body">
	<div id="cntr-pos-root" aria-live="polite">
		<noscript><?php esc_html_e( 'JavaScript is required to use the till.', 'counter' ); ?></noscript>
	</div>
	<?php
	wp_print_scripts();
	wp_print_footer_scripts();
	?>
</body>
</html>
