# Counter POS terminal — redesign implementation spec

**Audience:** the engineer (or Claude session) implementing the redesign.
**Plugin version at time of audit:** `counter` v0.1.29.
**Design source:** `Counter POS.dc.html` (Claude Design canvas, 9 screens, EN/BN, 1024/1366).

This file is the contract. It records what is broken today, what the design gets
wrong, what must be decided, and the order to build in — with the exact self-test
checks to add at each step.

**Working agreement**

- Build in the phases below, **in order**. One phase per commit.
- Every phase ends with its self-test checks **added and passing**. A phase is not
  done because the screen looks right; it is done when `wp counter selftest` proves it.
- Never skip, disable, or weaken an existing check to get green.
- If a phase's design and a stated constraint conflict, **stop and ask.** Do not
  silently pick one.

---

# Part 1 — Defects found

## 1.1 Plugin defects (present in v0.1.29, independent of the redesign)

### D1 — 19 terminal strings can never be translated  · severity: high

`assets/pos.js` declares 207 default keys; `templates/pos.php` passes only 188. The
19 below exist **only** as English fallbacks in `pos.js` and are never wrapped in
`__()`, so no locale can ever translate them. This directly defeats the plugin's own
stated U2 goal — *"a cashier should be able to run the terminal entirely in Bengali."*

| Key | English default | Where it shows |
|---|---|---|
| `panelGridTitle` | `Products` | product grid panel heading |
| `priceGroupLabel` | `Prices:` | price-group picker label |
| `priceGroupRegister` | `Register price` | price-group default option |
| `priceGroupActive` | `Cart re-priced for %name%` | price-group active notice |
| `gridAllCategories` | `All` | first category chip |
| `lowStockBadge` | `Low stock` | product tile badge |
| `footerItemsLabel` | `Items` | totals footer |
| `footerDiscountLabel` | `Discount` | totals footer |
| `footerTaxLabel` | `Order tax` | totals footer |
| `footerShippingLabel` | `Shipping` | totals footer |
| `footerRoundOffLabel` | `Round off` | totals footer |
| `footerTotalLabel` | `Total` | totals footer |
| `footerEditAria` | `Edit %field%` | footer edit button aria-label |
| `orderDiscountTitle` | `Order discount` | order-adjust modal |
| `orderTaxTitle` | `Order tax` | order-adjust modal |
| `orderShippingTitle` | `Shipping` | order-adjust modal |
| `amountLabel` | `Amount` | order-adjust modal field |
| `stockInUnit` | `%qty% %unit% left` | cart line stock hint |
| `editSubtotalAria` | `Line subtotal` | cart line subtotal input |

**Fix:** add all 19 to the `'strings'` array in `templates/pos.php`, each wrapped in
`__( '…', 'counter' )`, with a `/* translators: */` comment wherever a `%…%`
placeholder appears. Then add them to the `.po` and recompile the `.mo`.

**Root cause:** nothing enforces parity. See T1 in Part 4 — add that check first, in
Phase 0, so the redesign cannot re-introduce this defect while adding ~40 new labels.

### D2 — 113 of 537 Bengali translations are empty  · severity: high

`languages/counter-bn_BD.po` has 537 msgid/msgstr pairs; **113 have an empty
`msgstr`**, including core terminal vocabulary: `Offline`, `Cart`, `Owes`, `Limit`,
`Available`, `Out of stock`, `Decrease quantity`, `Increase quantity`,
`Scan or type SKU / barcode / name`, `Total:`, `Usual:`, `Clear customer`,
`Walk-in — F6 to attach a customer`, `Oldest due %n% days`.

A Bengali cashier today sees a **half-English till**. `test_i18n` does not catch this
— its check 2 proves only that *one* known string (`Add`) translates.

**Fix:** translate all 113. The design file is a gift here — its `T.bn` block already
contains professional Bengali for most terminal vocabulary (`অফলাইন`, `বাকি`, `সীমা`,
`উপলব্ধ`, `স্টক নেই`, `স্ক্যান করুন বা SKU / বারকোড / নাম লিখুন`). **Reuse it** rather
than re-translating. Recompile the `.mo` afterwards — `test_i18n` check 4 asserts the
`.mo` is newer than the `.po`.

### D3 — `alert()` is the capability-denial UI  · severity: medium

`requireCap()` (`assets/pos.js`) calls `alert()`. On a full-screen kiosk till a native
modal is jarring, unstyled, un-translatable in its button label, and blocks the render
loop. `test_pos_wiring` already forbids `prompt()` as a handler UI — `alert()` slipped
through the same reasoning.

**Fix:** replace with an in-page toast/banner using the design's warning treatment
(`--c-danger-soft` + `--c-danger`, icon plus text). Keep the message strings as they
are; they are already translated.

### D4 — "Saved" has no backing computation  · severity: medium

`footerTotals()` returns `{ items, discount, tax, shipping, roundOff, total }`, where
`discount` is the **order-level** discount only. Line-level discounts are already
subtracted inside `lineTotal()` and are never summed anywhere.

The design's footer shows **three** figures — `SUBTOTAL`, `SAVED`, `TOTAL` — plus a
separate `Discount` row. In the design's own sample data these disagree: line 3 carries
a −৳24.00 line discount, the footer `Discount` row shows −৳24.00, and `SAVED` shows
৳24.00. One discount is being rendered as two different things.

**Fix:** extend `footerTotals()` with `lineDiscounts` (sum of `l.discount`) and
`saved = lineDiscounts + discount`. Render `SAVED` from `saved`, and keep the footer
`Discount` row bound to the order-level `discount` only. See Q1 — confirm this reading
before building.

## 1.2 Design defects (things the design gets wrong about the plugin)

### D5 — The design links Google Fonts  · severity: high · **will fail an existing test**

The canvas `<helmet>` contains:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:…" rel="stylesheet">
```

The till must ring sales offline — `assets/sw.js` precaches the shell for exactly that
reason. An external font request turns the terminal into an unstyled or blank screen
the moment the shop's connection drops, which at a counter is the moment it matters.

This is not a judgement call: **`test_terminal_assets` already asserts "pos.js contains
no external host reference."** Carrying the design's font link into the implementation
fails the existing suite.

**Fix:** ship the Noto Sans Bengali woff2 files in `assets/fonts/` and declare them with
`@font-face` in `pos.css`. Extend the existing external-host check to cover `pos.css`
too (see T2).

### D6 — The price-group picker is missing from the design  · severity: high

The plugin has a per-sale price-group picker (feature B2, `cart.priceGroupId`,
bootstrapped via `CFG.priceGroups`) that **re-prices the entire cart**. It appears
nowhere in the design — no picker, no active-group indicator.

Dropping it silently removes a working pricing feature. **Fix:** place the picker in
the ticket header strip beside the customer chip, and keep the "Cart re-priced for
%name%" notice. Render it only when `CFG.priceGroups.length` — matching current behaviour.

### D7 — The document banner is missing from the design  · severity: high

The plugin shows `Editing Draft #123 — Pay to finalize` with a clear-× whenever
`cart.documentOrderId` is set (feature B6). The design has `Draft` / `Quotation` /
`Suspend` / `Parked` buttons but **no banner**.

Without it a cashier editing a saved draft has no indication they are not on a fresh
sale — they will ring a second sale onto someone else's quotation.

**Fix:** design a banner strip in the design's language (accent-soft background, icon,
text, clear-×) directly above the totals footer.

### D8 — Six modals are undesigned  · severity: medium

The design covers 9 screens. The plugin has ~15 modal surfaces. **Undesigned:**
quick-add (`New item`), expense, no-sale, held-sales (`Parked`), drafts/quotations list
(`Documents`), and the search-fields popover.

Their trigger buttons are all present in the design, so these are omissions, not
removals. **Fix:** extrapolate each from the design's established modal shell (icon
chip + title + × ; scrollable body; footer with Cancel + tinted confirm). Do not
invent new visual patterns.

### D9 — Header shows data the bootstrap does not expose  · severity: low

The design header renders **`Register 2`** and a cashier name **`Nusrat J.`**

- `window.CNTR` exposes `registerPrefix` (`'R1'`) but **no register name**, though the
  `cntr_registers` table has a `name` column.
- The bootstrap exposes **no current-user name at all** — `templates/pos.php` calls
  `current_user_can()` six times but never `wp_get_current_user()`.

**Fix:** add `registerName` and `cashierName` to the bootstrap array. Both are
static-for-the-session, matching the existing reasoning for `units` / `priceGroups` /
`caps`. Escape both on render.

### D10 — Design keypad is decorative  · severity: medium

Every `press`, `add`, `pick` and `remove` handler in the canvas is `() => {}`. The
keypad, product tiles and tender rows do nothing. That is correct for a mockup, but it
means **the design specifies no input model**: it does not say which field the keypad
types into, how focus moves between the search box and a modal field, or what `C` and
`⌫` clear.

This is the single largest piece of undesigned behaviour. See Q2.

## 1.3 Conflicts between the design and the redesign brief

### D11 — The design is light-themed; the brief specified dark

`docs/pos-redesign-prompt.md` §3 says keep the dark theme, because every existing modal
assumes it. The design ships a **light** palette (`--c-bg:#eef1f7`, white surfaces).

The design wins — it is the approved artefact, and it is internally consistent across
all 9 screens. But this makes the CSS work a **full repaint, not a restyle**: every one
of the ~1,040 lines of `pos.css` is in scope, and the existing `:root` tokens
(`--cntr-bg` … `--cntr-danger`) are replaced wholesale by the design's `--c-*` set.

Budget accordingly, and note it in the changelog as a deliberate reversal.

---

# Part 2 — Questions to resolve before Phase 3

Answer these with the shop owner. Do not guess.

- **Q1 — `SAVED`:** is it (a) line discounts + order discount, or (b) line discounts
  only, with the footer `Discount` row covering the order level? Spec assumes **(a)**.
- **Q2 — keypad target:** when no modal is open, does the keypad type into the search
  box (so `5` `0` `0` `Enter` looks up SKU 500), or does it set a quantity multiplier
  for the next scan (the classic grocery `3` `×` behaviour)? Spec assumes **search box**,
  because that matches the existing single-input model and adds no new state.
- **Q3 — `New item` dimming:** the design renders `F5 New item` at `opacity:.4`,
  which in this codebase means *capability denied*. Confirm that is intentional
  (i.e. the mockup depicts a cashier without `cntr_manage_stock`) and not a styling
  accident. Spec assumes intentional.
- **Q4 — product grid:** the design offers two variants — `Grid: drawer` (full-height
  overlay) and `Grid: tab` (panel above the ticket). Pick one. Spec assumes **drawer**,
  because at 1024×768 the tab variant costs 160px of ticket height.

---

# Part 3 — Phased implementation

Each phase lists scope, files, and the self-test checks that must be added and passing
before the phase is committed.

## Phase 0 — Guardrails (no visual change)

Build the tests that make the rest of the work safe. **Nothing renders differently.**

- Add **T1** (STRINGS parity), **T2** (external hosts in CSS), **T3** (translation
  completeness) — see Part 4.
- Fix **D1**: add the 19 missing keys to `templates/pos.php`.
- Fix **D2**: translate the 113 empty `msgstr` entries, reusing the design's `T.bn`
  block. Recompile the `.mo`.

**Done when:** T1, T2, T3 pass, and the full existing suite is still green.
**Commit:** `fix: close the STRINGS translation gap and complete the Bengali catalogue`

## Phase 1 — Design tokens and type scale

- Replace the `:root` block in `pos.css` with the design's palette, verbatim from
  `frameStyle`: `--c-bg #eef1f7`, `--c-surface #ffffff`, `--c-panel #f7f9fd`,
  `--c-border #d7dfec`, `--c-line #e8edf6`, `--c-text #152033`, `--c-muted #5f6f88`,
  `--c-accent #1f5fd6`, `--c-accent-soft #e8f0ff`, `--c-success #0b8a4b`,
  `--c-success-soft #e3f6ec`, `--c-danger #d02a2a`, `--c-danger-soft #fdeaea`,
  `--c-warn #b3740a`, `--c-warn-soft #fdf1dd`.
- Ship Noto Sans Bengali woff2 in `assets/fonts/`, declare via `@font-face` (**D5**).
- Establish the scale: interactive ≥16px, cart names 19px, grand total 56px (44px at
  ≤1024), touch targets ≥56px.

**Done when:** T2 passes with the font files in place; no page-level horizontal scroll
at 1024×768 and 1366×768.
**Commit:** `refactor: adopt the approved light palette and self-hosted Bengali font`

## Phase 2 — Shell: header, three-zone grid, total strip

- Header per design: brand, register, shift, cashier, timestamp, net badge, four
  **labelled** toolbar buttons (currently icon-only emoji).
- Add `registerName` + `cashierName` to the bootstrap (**D9**).
- Replace all five emoji icons (💼 ❎ ⊖ ↶ 🔍) with the design's inline SVG paths.
- Footer total strip: Items / Subtotal / Saved / Total + the F2 Pay button.
- Extend `footerTotals()` with `lineDiscounts` and `saved` (**D4**, per Q1).

**Done when:** **T4** (no emoji in pos.js) and **T5** (`saved` arithmetic) pass.
**Commit:** `feat(pos): redesigned header, zone layout and total strip`

## Phase 3 — Ticket panel

- Numbered lines, 19px names, unit chip, stock hint, discount badge, out-of-stock badge,
  right-aligned line total, 56px − / + / delete controls.
- Selected line = 5px accent keyline **plus** `--c-accent-soft` background. Never colour
  alone.
- Keep the editable subtotal input and its back-solve behaviour.
- Add Prev / Next line-nav buttons (design) bound to the existing `moveSelection()`.
- **Restore the price-group picker** (**D6**) and the **document banner** (**D7**).

**Done when:** **T6** (both restored elements render when their state is set) passes.
**Commit:** `feat(pos): redesigned ticket panel with price group and document banner`

## Phase 4 — Action pad and keypad

- 2-column action grid: Qty, Discount, New item, Customer, Hold, Resume, Return,
  No-sale, Void, Products — each an icon + label + F-key badge.
- Capability-gated buttons dim to `opacity:.4`, never hidden.
- Keypad wired per Q2. `C` clears the field, `⌫` deletes one character, `Enter`
  submits the focused context.
- Replace `alert()` in `requireCap()` with the in-page toast (**D3**).

**Done when:** **T7** (every `keyboardActions()` action has a pad button carrying its
bound key) and **T8** (no `alert()`/`prompt()` in pos.js) pass.
**Commit:** `feat(pos): touch action pad and on-screen keypad`

## Phase 5 — Modals

One commit per modal, designed first, then the six undesigned ones (**D8**) built from
the established shell. Order: **tender** (highest value, fully designed) → qty →
discount → customer → return → X-report → close register → then quick-add, expense,
no-sale, held sales, documents, search-fields.

Tender specifics: 7 method buttons at 76px with icons; split-tender rows; Due / Taken /
Remaining block; **Change in the large green panel**; credit warnings preserved verbatim.

**Done when:** **T9** (every modal id in `render()` has a renderer and a close path)
passes, and every existing modal check is still green.

## Phase 6 — Responsive, cache, changelog

- Verify 1024×768 and 1366×768; keep the 720px and 480px breakpoints working.
- Bump `CNTR_VERSION` in `counter/counter.php` — **both** the `Version:` header and the
  `define()`; `test_version()` asserts they match.
- Bump `CACHE_NAME` in `assets/sw.js` from `cntr-pos-shell-v1`.
- Changelog entry in `readme.txt` in the voice of existing entries. Note the
  dark→light reversal (**D11**).

**Done when:** the full suite is green and **T10** (cache-buster bumped) passes.

---

# Part 4 — Self-test checks to add

House idiom, from `includes/Admin/Selftest.php`:

```php
private function test_<name>(): void {
    $this->check(
        'test_<name>: <what is being proven, in plain words>',
        (bool) $condition,
        wp_json_encode( [ /* evidence, so a failure is diagnosable */ ] )
    );
}
```

Rules: one `check()` per proposition; the label states the proposition, not the action;
the third argument carries enough evidence to debug a red run without re-reading code.
Per `HANDOVER.md`, **never** assert on the suite's total check count.

| # | Test | Proves | Phase |
|---|---|---|---|
| **T1** | `test_pos_strings_parity` | Every key in `pos.js`'s `STRINGS` default object exists in `templates/pos.php`'s `'strings'` array, and vice versa. Parse both with regex; fail listing the symmetric difference. **This is the highest-value test in the file** — it is the check whose absence caused D1. | 0 |
| **T2** | extend `test_terminal_assets` | `pos.css` contains no external host (`http://`, `https://`, `//fonts.`), matching the existing `pos.js` check. Catches D5 permanently. | 0 |
| **T3** | extend `test_i18n` | No `msgstr ""` against a non-empty `msgid` in `counter-bn_BD.po`. Fail with the count and first 5 offenders. Catches D2. | 0 |
| **T4** | `test_pos_no_emoji_icons` | `pos.js` contains no emoji/pictographic HTML entity (`&#1F…;`, `&#128…;`, `&#10062;`, `&#8854;`, `&#8630;`, `&#128269;`) — icons must be inline SVG. | 2 |
| **T5** | `test_pos_saved_total` | `footerTotals()` exposes `lineDiscounts` and `saved`, and `saved === lineDiscounts + discount`. Assert against a constructed cart, not by reading source. | 2 |
| **T6** | `test_pos_wiring` (extend) | `cart.priceGroupId` and `cart.documentOrderId` are each referenced in `render()`'s markup — the picker and banner survived the redesign. | 3 |
| **T7** | `test_pos_keybar_parity` | Every action in `keyboardActions()` has a corresponding pad button in `render()`, and every key in the default `pos.keyboard_map` maps to a real action. Catches a rebind that silently orphans a button. | 4 |
| **T8** | extend `test_pos_wiring` | `pos.js` contains no `alert(` or `prompt(` — extends the existing `prompt()` rule to cover D3. | 4 |
| **T9** | `test_pos_modal_coverage` | Every `id="cntr-*"` modal container emitted by `render()` has a matching `render*()` function and a close path. Catches a modal orphaned by the rewrite. | 5 |
| **T10** | extend `test_version` | `CACHE_NAME` in `sw.js` is not the value shipped in the previous release — the shell cache was actually invalidated. | 6 |

---

# Part 5 — Constraints that do not change

Repeated from `docs/pos-redesign-prompt.md` because they are the ones that break the
plugin. Full text there; these are the non-negotiables:

1. **No build step, no framework, no npm.** Vanilla ES2020 template literals + plain CSS.
2. **No external hosts, ever.** The till sells offline. Enforced by T2.
3. **`STRINGS` is hand-synced across `pos.php` and `pos.js`.** Enforced by T1.
4. **`escapeHtml()` every interpolated value.** Currently clean — a scan for unescaped
   product/customer fields found zero hits. Keep it that way.
5. **Element ids are a contract.** `render()` writes `innerHTML`, then re-binds by
   `getElementById` / `querySelectorAll`. Rename an id and you must move its listener in
   the same edit.
6. **Preserve the search box value/caret restore** across the `innerHTML` rebuild — a
   scanner mid-keystroke depends on it.
7. **Capability gating: dim, never hide.**
8. **Keyboard shortcuts keep working** and are user-configurable via `pos.keyboard_map`.
   Show the bound key on each button.
9. **No page scroll.** Panels scroll internally; `min-height: 0` on flex parents.
10. **Bump both cache-busters.** Enforced by T10.

# Definition of done

- All 6 phases committed, in order, each with its tests.
- `wp counter selftest` green — compare the **labelled pass/fail list**, never a total.
- The 4 questions in Part 2 answered by a human, and the answers recorded in
  `docs/decisions.md`.
- A written statement of what was verified, how, and what could not be checked.
