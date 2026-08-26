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

await p.keyboard.press('F6'); await p.waitForTimeout(300);
await p.click('#cntr-customer-phone');
await p.keyboard.type('01711223344', { delay: 50 });
await p.click('#cntr-customer-lookup'); await p.waitForTimeout(900);

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
console.log(failures === 0 ? `ALL PASS (${names.length + 1} checks)` : `${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
