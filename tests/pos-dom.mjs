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

await b.close();
console.log(failures === 0 ? 'ALL PASS' : `${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
