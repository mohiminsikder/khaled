import { chromium } from 'playwright-core';

const execPath = process.env.PW_CHROME_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const b = await chromium.launch({ executablePath: execPath, args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'] });
const p = await b.newPage({ viewport: { width: 1360, height: 880 }, deviceScaleFactor: 2 });
p.on('pageerror', e => console.log('PAGEERROR:', e.message));

let failures = 0;
const assert = (cond, label) => {
  if (cond) {
    console.log('PASS', label);
  } else {
    failures++;
    console.log('FAIL', label);
  }
};

await p.goto('file://' + process.cwd() + '/pos-harness.html');
await p.waitForTimeout(1400);
const shot = async n => { await p.screenshot({ path: n + '.png' }); console.log('shot', n); };
const cart = async () => p.$$eval('.cntr-pos-cart li', e => e.length).catch(() => 0);

// -- A2: Enter submits the customer lookup, not just the Find button
// (docs/TILLBUGS.md #2 — F6 -> type phone -> Enter did nothing).
await p.keyboard.press('F6'); await p.waitForTimeout(300);
await p.click('#cntr-customer-phone');
await p.keyboard.type('01711223344', { delay: 50 });
await p.keyboard.press('Enter'); await p.waitForTimeout(900);
const attachedName = await p.textContent('.cntr-customer-strip-name').catch(() => null);
assert('Karim Uddin' === (attachedName || '').trim(), `A2: Enter on the phone field attaches the customer (got "${attachedName}")`);

// -- A1: every second name-search item must add, not silently fail
// (docs/TILLBUGS.md #1 — addHighlightedResult() left a stale
// suppressNextEnter armed, eating the next product's Enter). No retry
// workaround here: if the box doesn't clear or the count doesn't advance,
// that IS the regression, and must fail loudly, not be typed around.
const names = ['Chinigura', 'Soyabin', 'Sugar'];
for (let i = 0; i < names.length; i++) {
  const q = names[i];
  await p.click('#cntr-search');
  const cur = await p.inputValue('#cntr-search');
  if (cur) for (let j = 0; j < cur.length; j++) await p.keyboard.press('Backspace');
  await p.keyboard.type(q, { delay: 80 });
  await p.waitForTimeout(350);
  await p.keyboard.press('Enter'); await p.waitForTimeout(300);

  const boxValue = await p.inputValue('#cntr-search');
  assert(boxValue === '', `A1: search box is empty after adding "${q}" (was "${boxValue}")`);

  const lines = await cart();
  assert(lines === i + 1, `A1: cart has ${i + 1} line(s) after adding "${q}" (has ${lines})`);
}
const total = (await p.textContent('.cntr-pos-total')).trim();
assert(total.includes('940.00'), `A1: total is ৳940.00 after three items (was "${total}")`);
console.log('cart:', await cart(), total);
await shot('10-billing-cart');

// -- A2, continued: the same gap audited across the other Phase F modals.
await p.keyboard.press('F5'); await p.waitForTimeout(300);
await p.fill('#cntr-quick-add-name', 'Probe Item');
await p.fill('#cntr-quick-add-price', '50');
await p.click('#cntr-quick-add-price');
await p.keyboard.press('Enter'); await p.waitForTimeout(500);
const linesAfterQuickAdd = await cart();
assert(4 === linesAfterQuickAdd, `A2: Enter submits quick-add and adds a cart line (has ${linesAfterQuickAdd})`);
assert(await p.isHidden('#cntr-quick-add'), 'A2: quick-add modal closes after Enter');

await p.keyboard.press('F10'); await p.waitForTimeout(300);
await p.click('#cntr-no-sale-reason');
await p.keyboard.type('Register test', { delay: 40 });
await p.keyboard.press('Enter'); await p.waitForTimeout(500);
assert(await p.isHidden('#cntr-no-sale'), 'A2: Enter submits the no-sale modal and closes it');

// printReceipt() focuses the print iframe's own window; nothing ever
// hands focus back in headless Chrome (no real print dialog to return
// from), so the next keyboard shortcut would go nowhere without this.
await p.click('.cntr-pos-header').catch(() => {}); await p.waitForTimeout(200);

// -- B1: the product tile grid — renders from the fixture catalogue, a
// category chip filters it, tapping a tile adds a line, and a
// below-threshold tile (Basmati Rice, fixture low_stock_amount=20 against
// sellable_qty=18) carries a text marker, not colour alone.
const tileCount = async () => p.$$eval('.cntr-grid-tile', e => e.length).catch(() => 0);
assert(7 === (await tileCount()), `B1: the grid renders all 7 fixture products (has ${await tileCount()})`);

const lowStockTile = await p.$$eval(
  '.cntr-grid-tile',
  els => els.some(el => el.textContent.includes('Basmati Rice') && el.querySelector('.cntr-grid-tile-lowstock'))
);
assert(lowStockTile, 'B1: the below-threshold tile (Basmati Rice) carries the low-stock marker');

await p.click('.cntr-grid-chip:has-text("Rice")'); await p.waitForTimeout(300);
assert(3 === (await tileCount()), `B1: the "Rice" category chip filters to its 3 products (has ${await tileCount()})`);
await p.click('.cntr-grid-chip:has-text("All")'); await p.waitForTimeout(300);
assert(7 === (await tileCount()), `B1: the "All" chip restores every product (has ${await tileCount()})`);

const linesBeforeTileTap = await cart();
await p.click('.cntr-grid-tile:has-text("Red Lentil")'); await p.waitForTimeout(300);
assert(linesBeforeTileTap + 1 === (await cart()), 'B1: tapping a tile adds a cart line');
// Remove it again so the tender demo below still matches its own totals.
await p.click(`.cntr-cart-remove[data-idx="${linesBeforeTileTap}"]`); await p.waitForTimeout(300);

// -- B2: the price-group picker — lists active groups, and switching
// re-prices every matching line in the open cart.
const groupOptionCount = await p.$$eval('#cntr-price-group option', els => els.length);
assert(3 === groupOptionCount, `B2: the picker lists active groups plus "Register price" (has ${groupOptionCount} options)`);

const chinigura = () => p.locator('.cntr-cart-line', { hasText: 'Chinigura' }).locator('.cntr-cart-price');
const priceBefore = (await chinigura().textContent()).trim();
await p.selectOption('#cntr-price-group', '2'); await p.waitForTimeout(400);
const priceAfterWholesale = (await chinigura().textContent()).trim();
assert(priceAfterWholesale.includes('550.00') && priceAfterWholesale !== priceBefore, `B2: switching to Wholesale re-prices the matching line (was "${priceBefore}", now "${priceAfterWholesale}")`);
assert(await p.textContent('.cntr-price-group-active').then(t => t.includes('Wholesale')).catch(() => false), 'B2: the picker says so — an active-group indicator names it');

// Back to the register price so the tender demo below matches its own totals.
await p.selectOption('#cntr-price-group', '0'); await p.waitForTimeout(400);
const priceAfterReset = (await chinigura().textContent()).trim();
assert(priceAfterReset.includes('620.00'), `B2: switching back to "Register price" restores it (now "${priceAfterReset}")`);

// -- B3: per-line unit selection and the editable subtotal. Sugar 1kg
// (already in the cart from the A1 block, qty=1 kg @ ৳135.00) has two
// fixture units: kg (multiplier 1, default) and g (multiplier 0.001).
const sugarLine = () => p.locator('.cntr-cart-line', { hasText: 'Sugar' });
const sugarQty = async () => (await sugarLine().locator('.cntr-cart-qty').textContent()).trim();
const sugarSubtotal = async () => (await sugarLine().locator('.cntr-cart-subtotal').inputValue()).trim();

assert('1' === (await sugarQty()), `B3: Sugar starts at qty 1 kg (has ${await sugarQty()})`);
await sugarLine().locator('.cntr-cart-unit').selectOption('202'); // grams
await p.waitForTimeout(300);
assert('1000' === (await sugarQty()), `B3: switching to grams converts qty by the multiplier (1 kg -> ${await sugarQty()} g)`);
assert('135.00' === (await sugarSubtotal()), `B3: the subtotal is unchanged by a unit switch alone (has ${await sugarSubtotal()})`);

await sugarLine().locator('.cntr-cart-subtotal').fill('200.00');
await sugarLine().locator('.cntr-cart-subtotal').press('Tab'); // fires 'change'
await p.waitForTimeout(300);
const totalAfterOvertype = (await p.textContent('.cntr-pos-total')).trim();
assert(totalAfterOvertype.includes('1,055.00') || totalAfterOvertype.includes('1055.00'), `B3: overtyping the subtotal back-solves the price and the new total sums it in (total now "${totalAfterOvertype}")`);

// Restore Sugar to its original kg/135.00 state — switching back to a unit
// re-prices from THAT unit's own price, discarding the back-solved one,
// so the tender demo below still matches its own hardcoded totals.
await sugarLine().locator('.cntr-cart-unit').selectOption('201');
await p.waitForTimeout(300);
assert('1' === (await sugarQty()) && '135.00' === (await sugarSubtotal()), `B3: switching back to kg restores qty and price (qty=${await sugarQty()}, subtotal=${await sugarSubtotal()})`);

// -- B4: order-level discount, tax, shipping, and the round-off footer.
// The cart carries 4 lines here — Chinigura/Soyabin/Sugar from A1 (৳940)
// plus the A2 quick-add "Probe Item" (৳50) — so the items subtotal this
// section works against is ৳990.00, not the ৳940 the A1 block asserted on
// its own 3-line cart. Fixture config: discountCeilingPct=10 (so ৳99 is
// the cap on this base), roundingStep=1.
const footerTotal = () => p.textContent('.cntr-pos-total').then(t => t.trim());
const footerRow = (label) => p.locator('.cntr-footer-row', { hasText: label }).first();
const openAdjust = async (field) => { await p.click(`.cntr-footer-edit[data-field="${field}"]`); await p.waitForTimeout(300); };
const applyAdjust = async () => { await p.click('#cntr-order-adjust-apply'); await p.waitForTimeout(300); };

await openAdjust('discount');
await p.fill('#cntr-order-adjust-amount', '50');
await applyAdjust();
const totalAfterDiscount = await footerTotal();
assert(totalAfterDiscount.includes('940.00'), `B4: a ৳50 order discount on ৳990 reduces the total to ৳940.00 (has "${totalAfterDiscount}")`);

// Above the 10% ceiling (৳99 on this ৳990 base) — refused, with a visible
// reason, and the total already on the order is left exactly as it was.
await openAdjust('discount');
await p.fill('#cntr-order-adjust-amount', '150');
await applyAdjust();
const warningText = ((await p.textContent('#cntr-order-adjust-warning').catch(() => '')) || '').trim();
assert(warningText.length > 0, `B4: a discount above the ceiling is refused with a visible reason (warning: "${warningText}")`);
await p.click('#cntr-order-adjust-cancel'); await p.waitForTimeout(300);
const totalAfterRefusedDiscount = await footerTotal();
assert(totalAfterRefusedDiscount.includes('940.00'), `B4: the refused discount left the total unchanged (has "${totalAfterRefusedDiscount}")`);

// Order tax adds straight to the total (no ceiling) — ৳940.30 pre-round
// rounds to the nearest ৳1 step, so the DISPLAYED total stays ৳940.00 and a
// Round off row of -0.30 appears instead.
await openAdjust('tax');
await p.fill('#cntr-order-adjust-amount', '0.30');
await applyAdjust();
const totalAfterTax = await footerTotal();
assert(totalAfterTax.includes('940.00'), `B4: order tax of ৳0.30 rounds away under the ৳1 step, so the total stays ৳940.00 (has "${totalAfterTax}")`);
const roundOffAfterTax = ((await footerRow('Round off').textContent().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
assert(roundOffAfterTax.includes('0.30'), `B4: round-off follows the ৳1 step and shows the -0.30 gap (row: "${roundOffAfterTax}")`);

// Shipping also adds straight to the total.
await openAdjust('shipping');
await p.fill('#cntr-order-adjust-amount', '10.00');
await applyAdjust();
const totalAfterShipping = await footerTotal();
assert(totalAfterShipping.includes('950.00'), `B4: ৳10 shipping adds straight to the total (has "${totalAfterShipping}")`);

// The footer sums correctly: items - discount + tax + shipping + roundOff.
const footer = await p.evaluate(() => window.CNTR._pos.footerTotals());
const summed = footer.items - footer.discount + footer.tax + footer.shipping + footer.roundOff;
assert(Math.abs(summed - footer.total) < 0.005, `B4: the footer sums correctly (items=${footer.items} discount=${footer.discount} tax=${footer.tax} shipping=${footer.shipping} roundOff=${footer.roundOff} total=${footer.total}, summed=${summed})`);

// Reset back to zero so the tender demo below matches its own totals.
await openAdjust('discount');
await p.fill('#cntr-order-adjust-amount', '0');
await applyAdjust();
await openAdjust('tax');
await p.fill('#cntr-order-adjust-amount', '0');
await applyAdjust();
await openAdjust('shipping');
await p.fill('#cntr-order-adjust-amount', '0');
await applyAdjust();
const totalAfterReset = await footerTotal();
assert(totalAfterReset.includes('990.00'), `B4: resetting discount/tax/shipping back to 0 restores ৳990.00 (has "${totalAfterReset}")`);

await p.keyboard.press('F2'); await p.waitForTimeout(500);
await p.click('#cntr-tender-add-row'); await p.waitForTimeout(250);
await p.click('#cntr-tender-add-row'); await p.waitForTimeout(350);
const setRow = async (i, method, amt) => {
  if (method) { await p.selectOption(`.cntr-tender-row:nth-of-type(${i + 1}) .cntr-tender-row-method`, method); await p.waitForTimeout(300); }
  await p.fill(`.cntr-tender-row:nth-of-type(${i + 1}) .cntr-tender-row-amount`, amt); await p.waitForTimeout(300);
};
await setRow(0, null, '500');
await setRow(1, 'bkash', '200');
await setRow(2, 'credit', '240');
await p.click('h2').catch(() => {}); await p.waitForTimeout(500);
await shot('11-split-tender-credit');
console.log('SUMMARY:', (await p.textContent('.cntr-tender-summary')).replace(/\s+/g, ' ').trim());
await p.click('#cntr-tender-cancel'); await p.waitForTimeout(300); // close the modal so B5's header buttons are reachable

// -- B5: the live X-report (💼) and register close (❎). Fixture served by
// pos-harness.html's own /shift/x-report and /shift/close stubs — a fixed
// snapshot unrelated to this file's own cart/tender state above.
await p.evaluate(() => { window.__req.length = 0; }); // only care about requests from here on
await p.click('#cntr-xreport-btn'); await p.waitForTimeout(400);
const xReportText = ((await p.textContent('#cntr-xreport')) || '').replace(/\s+/g, ' ').trim();
assert(xReportText.includes('2,400.00'), `B5: the X-report shows total sales from the live snapshot (text: "${xReportText}")`);
assert(xReportText.includes('2,250.00'), `B5: the X-report shows expected cash from the live snapshot (text: "${xReportText}")`);
assert(xReportText.includes('TEST-WIDGET-1'), `B5: the X-report lists products sold by SKU (text: "${xReportText}")`);
await p.click('#cntr-xreport-close'); await p.waitForTimeout(300);
assert(await p.isHidden('#cntr-xreport'), 'B5: the X-report modal closes');

await p.click('#cntr-close-register-btn'); await p.waitForTimeout(400);
const preCountText = ((await p.textContent('#cntr-close-register')) || '').replace(/\s+/g, ' ').trim();
assert(preCountText.includes('2,250.00'), `B5: the close-register modal shows expected cash before a count is entered (text: "${preCountText}")`);
await p.fill('#cntr-close-register-counted', '2245'); await p.waitForTimeout(300);
const varianceText = (await p.textContent('#cntr-close-register-variance').catch(() => '')) || '';
assert(varianceText.includes('Short') && varianceText.includes('5.00'), `B5: entering a counted amount shows the variance before confirming (text: "${varianceText}")`);

await p.click('#cntr-close-register-submit'); await p.waitForTimeout(500);
const closeCall = await p.evaluate(() => (window.__req || []).find((r) => r.url.includes('/shift/close')));
let closeBody = null;
try { closeBody = closeCall ? JSON.parse(closeCall.opts.body) : null; } catch (e) { closeBody = null; }
assert(!!closeCall, 'B5: confirming the close posts to /shift/close');
assert(!!closeBody && 42 === closeBody.shift_id && '2245' === closeBody.counted_cash, `B5: the close posts the right shift_id and counted_cash (body: ${JSON.stringify(closeBody)})`);
assert(await p.isHidden('#cntr-close-register'), 'B5: the close-register modal closes after confirming');

await b.close();
console.log(failures === 0 ? 'ALL PASS' : `${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
