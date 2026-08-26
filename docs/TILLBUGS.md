# Counter v0.1.20 — till defects found by running the screen

Found by booting the real `assets/pos.js` in headless Chromium and driving it with real key
events. All three reproduce on the shipped v0.1.20 build. The 465-check self-test suite passes
with every one of them present.

Harness: `tests/pos-harness.html` + `tests/pos-dom.mjs`.

---

## 1. Every second item added by name search silently fails

**Severity: blocking.** This is the ordinary way a cashier builds a basket.

### Reproduce

Type `Chinigura` → Enter. Line added, box clears. Type `Soyabin` → Enter. **Nothing happens, and
the box does not clear.** Type `Sugar` on top of it and the box now reads `SoyabinSugar`.

Observed, human-speed typing, unmodified v0.1.20:

```
"Chinigura" -> 1 result -> cart 1 line  | searchbox=""
"Soyabin"   -> 1 result -> cart 1 line  | searchbox="Soyabin"
"Sugar"     -> 1 result -> cart 1 line  | searchbox="SoyabinSugar"
TOTAL: ৳ 620.00      (should be ৳ 940.00)
```

### Cause

`assets/pos.js:795`, in `addHighlightedResult()`:

```js
suppressNextEnter = true; // same reasoning as the exact-match path in render()'s 'input' listener
```

`suppressNextEnter` exists for the **scanner**: the search box's `input` listener auto-adds on an
exact code match, and the scanner's trailing Enter has to be swallowed so it doesn't fire twice
(`pos.js:2727`, `pos.js:2732` — both correct).

But `addHighlightedResult()` is reached **from inside the Enter handler itself**
(`pos.js:866`). That Enter is already being consumed. There is no trailing keystroke to
suppress, so the flag stays armed and eats the **next** Enter the cashier presses — the one
meant for the next product. The handler that eats it (`pos.js:848-857`) returns before
`addHighlightedResult()` can clear the box, which is why the text stays behind and the next
query concatenates onto it.

The comment is the tell: it reasons about the `input` listener's path, which does have a trailing
Enter, and was carried over to a path that does not.

### Fix

Delete line 795. Verified: with that one line removed, all three items add, the box clears every
time, and the total is correct.

```
"Chinigura" -> cart 1 line | searchbox=""
"Soyabin"   -> cart 2 lines | searchbox=""
"Sugar"     -> cart 3 lines | searchbox=""
TOTAL: ৳ 940.00
```

Do **not** reset the flag in the Enter handler instead — that would re-break the scanner path it
legitimately serves. The scan paths at 2727/2732 must keep setting it.

### Regression test

`tests/pos-dom.mjs`: add three products by name search in sequence; assert the cart has three
lines, the search box is empty after each, and the total matches. This is the assertion that
would have caught it.

---

## 2. Enter does not submit the customer lookup form

**Severity: friction, on a till that is otherwise fully keyboard-driven.**

`F6` → type the phone → Enter does nothing. The operator must click **Find** with the mouse.
Every other surface on the till is completable from the keyboard, and P1.13's whole premise is
*"A cash sale must be completable without touching the mouse."*

Observed: after `F6`, typing `01711223344` and pressing Enter, `#cntr-customer-lookup` is still
in the DOM and the modal is unchanged. Clicking it proceeds normally.

### Fix

Bind Enter on `#cntr-customer-phone` to the same handler as the Find button. Check the other
modals added in Phase F for the same gap — quantity, discount, no-sale and quick-add should all
submit on Enter.

---

## 3. No product ever has a barcode

**Severity: high, and silent — it will look like "the scanner is broken."**

`Stock\Catalog::reindex()` hardcodes the barcode in both places it writes one:

- `includes/Stock/Catalog.php:70` — `'barcode' => '', // P3.1 assigns the real source for this field`
- `includes/Stock/Catalog.php:97` — `''` passed into the `barcode` column of the INSERT

Nothing anywhere else populates it. `includes/Docs/Barcode.php` is a barcode **rendering**
library (Code128 / EAN-13 SVG for labels), not an assignment source. The `// P3.1 assigns the
real source` comment is still unresolved in v0.1.20.

Consequence: `buildIndex()` in `pos.js` builds `byBarcode` from `r.barcode`, which is always an
empty string, so **the barcode map is always empty**. `lookupProduct()` falls through to the SKU
map, so scanning only resolves when the scanned code happens to equal the product's SKU.

That is quietly consistent for products created at the till — `Pos\Terminal::quick_add()` puts
the typed barcode straight into `set_sku()` (`Terminal.php:150`). It breaks for any product
imported or created in WooCommerce with an internal SKU and a separate manufacturer EAN, which is
the normal case for a grocery.

### Fix

Decide the real source for `barcode` and populate it in `reindex()`:

- If SKU **is** the barcode for this shop, say so explicitly, drop the dead `barcode` field from
  the payload and the column, and update the search placeholder — right now it advertises
  "Scan or type SKU / barcode / name" and one of those three does nothing.
- Otherwise add a barcode meta field on the product and read it in `reindex()`.

This is a shop-data decision. Ask before picking.

---

## Also worth a look, not bugs

- **Tender amounts render at 4 decimal places.** The row pre-fills `620.0000` and normalises a
  typed `500` to `500.0000`, while every other figure on the screen is formatted `৳ 620.00`.
  Internally 4dp is correct; the input should display 2.
- **`roundingStep` still has no visible effect** at the tender screen. It is read now
  (unlike v0.1.8) but a cash row does not visibly round.
- **The customer panel is a tall empty column** once attached — roughly six lines of content in
  a full-height panel. Worth reflowing, or giving the "usual items" strip that space.
