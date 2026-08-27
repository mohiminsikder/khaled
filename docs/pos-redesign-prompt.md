# Counter POS terminal — redesign prompt

Copy everything between the `---BEGIN PROMPT---` and `---END PROMPT---` markers into a
fresh Claude Code session opened on the Counter plugin repository (or paste it alongside
the `counter/` plugin folder). The inspiration screenshot (`Retail`-style till) should be
attached to that session as well; the prompt already tells Claude how much of it to take.

---BEGIN PROMPT---

# Task: redesign the Counter POS terminal (`/pos/`) for a non-technical cashier

You are redesigning the customer-facing till screen of **Counter**, a WooCommerce
point-of-sale / stockroom plugin for a Bangladeshi retail shop. The terminal works today
but reads like an engineer's dashboard: dense 11–13px text, tiny icon-only buttons, a
function-key legend a new cashier cannot decode, and no visual weight on the two things
that actually matter at a counter — *what is in the cart* and *what the customer owes*.

**Goal:** a shopkeeper or counter staff member with no computer training should be able
to sit down at this screen and ring a sale correctly, on a touchscreen, without being
taught keyboard shortcuts. Every action must be a large, labelled, icon-bearing button.
This is a **visual and interaction redesign only — do not change what any control does.**

## 1. Read these first, in this order

| File | Lines | What it is |
|---|---|---|
| `counter/assets/pos.js` | ~4,300 | The whole terminal. `render()` (~line 3766) builds the main screen; ~18 `render*Modal()` functions build the overlays. |
| `counter/assets/pos.css` | ~1,040 | Every style. Dark theme, CSS custom properties at `:root`. |
| `counter/templates/pos.php` | 353 | Full-screen bootstrap. Emits `window.CNTR` — config, capabilities, and the **`strings` map**. |
| `counter/includes/Pos/Terminal.php` | — | The `/pos/` rewrite rule and template load. |
| `counter/includes/Pos/Assets.php` | — | Enqueue, gated on the `cntr_is_pos_screen` filter. |
| `counter/assets/sw.js` | 78 | Service worker that **precaches `pos.js` and `pos.css`**. |
| `counter/HANDOVER.md` | 70 | Self-test suite contract. |

Before writing a line of CSS, produce a written inventory of **every interactive element
currently on screen** and state where it lands in your new layout. Nothing may be dropped
silently. The current inventory is at least:

- **Header:** app title, online/offline badge, "offline too long" lock badge, shift label
  (`R1-000042`), and four icon-only buttons — X-report (💼), close register (❎),
  record expense (⊖), return/lookup (↶).
- **Customer panel:** price-group `<select>`, customer name / phone / Owes / Limit /
  Available / "oldest due N days", clear-customer ×, and the walk-in placeholder.
- **Cart panel:** per line — name, qty, unit `<select>`, stock-in-unit hint, unit price,
  discount badge, out-of-stock badge, **editable subtotal input**, −, +, × buttons.
  Selected line is marked with a left keyline plus a background shift.
- **Product grid panel:** category filter chips + product tiles (name, SKU, price, stock,
  "Low stock" badge).
- **"Usual:" strip** — the attached customer's frequent items, one tap each.
- **Search row:** search-fields magnifier (🔍), the big scan/search input, cart total,
  and a floating keyboard-navigable result list.
- **Totals footer:** Items, Total, and editable Discount / Tax / Shipping rows (✎ each),
  plus a round-off row.
- **Document banner:** "Editing Draft #123 — Pay to finalize" with a clear ×.
- **Checkout bar:** Draft, Quotation, Suspend, Parked.
- **Key bar:** Pay, Qty, Discount, New item, Customer, Hold, Resume, Return, No-sale, Void.
- **Modals** (ids are wired by `render()`; keep them all): `cntr-tender`, `cntr-quick-add`,
  `cntr-return`, `cntr-customer`, `cntr-qty`, `cntr-discount`, `cntr-no-sale`, `cntr-held`,
  `cntr-order-adjust`, `cntr-xreport`, `cntr-close-register`, `cntr-documents`,
  `cntr-expense`, `cntr-search-fields`, plus the boot-time "open shift / opening float" screen.

## 2. What to take from the reference screenshot — and what to leave

The attached screenshot is a 2007-era grocery till. **Take the layout logic, not the look.**

**Take:**
- A dedicated **action pad** on the right: large square/rectangular buttons, each with a
  short ALL-CAPS or sentence-case label *and* an icon, arranged on a predictable grid.
- An **on-screen numeric keypad** (7 8 9 / 4 5 6 / 1 2 3 / . 0 00, plus Enter, Back, Clear
  and ▲ ▼ line-navigation) so a touchscreen till needs no physical keyboard.
- A **receipt-shaped ticket** on the left that reads like the paper the customer will get —
  one line per item, price right-aligned, voided/discounted lines visibly marked.
- A **huge total** in a colour-blocked strip along the bottom, with secondary figures
  (item count, savings, subtotal) beside it at smaller weight.
- A **status strip** across the top: register, shift, cashier, date/time, connection.

**Leave behind:** the grey bevelled skeuomorphism, the drop shadows on every key, the
cramped 4-column button grid, and the 2007 typography. Also **do not invent** controls the
plugin does not have — the screenshot's SIGN OFF, SECURE, CUSTOMER ID, PRICE INQUIRY,
MANAGER FUNCTIONS and COUPON have **no counterpart in this codebase** and must not appear.

## 3. Design direction

**Theme.** Keep the dark theme as the default — it is the right call for a counter under
fluorescent light, and every modal already assumes it. Redefine the palette at `:root`
rather than sprinkling literals; the existing tokens are `--cntr-bg`, `--cntr-panel`,
`--cntr-border`, `--cntr-text`, `--cntr-muted`, `--cntr-accent`, `--cntr-danger`. Add
tokens as needed (success, warning, surface elevation, focus ring) — never a bare hex in a
rule. Add a light-theme token block behind `@media (prefers-color-scheme: light)` **only if
it costs nothing**; dark is the shipping default either way.

**Type scale.** The cashier reads this from ~60cm, often standing. Nothing interactive
below **16px**; cart item names **18–20px**; the grand total **48px+**; secondary metadata
14px minimum. Keep `font-variant-numeric: tabular-nums` on everything numeric so columns of
money line up.

**Touch targets.** Minimum **56×56px** for any button a cashier taps during a sale; the
primary action pad buttons should be **80px tall or more**. The current 24px ✎ edit buttons
and 32px header icons are unusable on a touchscreen — fix them.

**Icons.** Every action button gets an icon **and** a text label — never an icon alone. Use
**inline SVG** drawn in the markup (currentColor stroke, 1.75–2px weight, 24px viewBox).
Do **not** use emoji (the current 💼 ❎ ⊖ ↶ 🔍 render differently on every device and are
invisible to a colour-blind cashier at distance), and do **not** add an icon font or any
external icon library — see the offline constraint below.

**Colour coding, with words.** Money-in green, refunds/voids red, held/parked amber,
credit-on-account blue — but the existing rule holds absolutely: **never colour alone.**
Every state that matters carries a text label too ("Offline", "Low stock", "Out of stock",
"Short by ৳50"). The current code already honours this; do not regress it.

**Hierarchy.** Three zones, always visible, never scrolling the page itself:
1. **Left/centre — the ticket.** The cart is the hero. Give it the most space.
2. **Right — the action pad + keypad.** Fixed position, same buttons in the same place
   every time, so muscle memory forms.
3. **Bottom — the total strip.** Grand total, item count, and the single biggest button on
   the screen: **PAY**.

The product grid and category chips should stay reachable but must not compete with the
ticket — consider a collapsible panel or a tab beside search results.

## 4. Hard constraints — violating any of these breaks the plugin

1. **No build step, no framework, no npm.** The terminal is vanilla ES2020 in one file,
   rendered by template literals into `innerHTML`, plus one plain CSS file. Do not
   introduce React, Tailwind, a bundler, a preprocessor, or a `package.json`.
2. **No external hosts, ever.** The till must ring sales offline (`sw.js` precaches the
   shell; the catalogue lives in IndexedDB). No Google Fonts, no CDN, no remote icon
   sprite, no analytics. Every asset is inlined or shipped in `assets/`.
3. **Keep the Bengali font stack.** `'Noto Sans Bengali'` must stay first in the
   `font-family` on `html, body` — a cashier can run this terminal entirely in Bengali
   (`languages/counter-bn_BD.po`), and Latin fallbacks follow it.
4. **The `STRINGS` contract is hand-synced.** Every visible label comes from
   `window.CNTR.strings` (built in `templates/pos.php`) and is mirrored key-for-key in the
   `STRINGS` object at the top of `pos.js` as an English fallback. **Any new label you add
   must be added in both places**, wrapped in `__( '…', 'counter' )` on the PHP side, and
   appended to `languages/counter-bn_BD.po`. Placeholders use the `%name%` form consumed by
   `fmt()`. Never hardcode a user-visible English string in `pos.js` markup.
5. **`escapeHtml()` every interpolated value.** Product names, customer names, SKUs and
   category names all come from the database. The current code is disciplined about this —
   match it exactly in any markup you rewrite.
6. **Element ids and `data-idx` attributes are a contract.** `render()` writes markup, then
   immediately re-attaches listeners by `getElementById` / `querySelectorAll` on those
   exact selectors. If you rename `cntr-search`, `cntr-checkout-draft`, `cntr-cart-remove`,
   `cntr-footer-edit[data-field]`, etc., you must update the matching listener in the same
   edit. Prefer keeping the existing ids and changing only classes and structure.
7. **Preserve the search-box value/caret restore.** `render()` deliberately saves
   `prevValue`/`prevCaret` from `#cntr-search` and restores them after the `innerHTML`
   rebuild. A scanner mid-keystroke depends on this. Do not remove it.
8. **Capability gating stays.** `CFG.caps` decides whether discount, void, price override,
   no-sale, quick-add and close-register are available. The established pattern is
   **dim, never hide** (`.cntr-key-disabled`, `opacity: 0.4`) so pressing it still explains
   itself via `requireCap()`. Carry that into every new button.
9. **Keyboard shortcuts must keep working.** `F2` Pay, `F3` Qty, `F4` Discount, `F5` New
   item, `F6` Customer, `F7` Hold, `F8` Resume, `F9` Return, `F10` No-sale, `Esc` Void,
   `↑`/`↓` line select — and the map is **user-configurable** via the `pos.keyboard_map`
   setting, read through `keyForAction()`. An experienced cashier is faster on keys than on
   glass; the redesign adds touch, it does not remove keys. Show the bound key **on** each
   action button (a small superscript badge), which also replaces the current key-bar
   legend with something a beginner can actually connect to an action.
10. **The on-screen keypad is additive.** It must feed the same code paths as typing — the
    search/scan input and the qty/discount/tender modal fields. Do not build a parallel
    input state machine.
11. **No page scroll.** `html, body { overflow: hidden }` and a `100vh` flex column. Panels
    scroll internally (`min-height: 0` on the flex parents — the current CSS gets this
    right). Target **1024×768 and 1366×768** landscape first; keep the existing
    `max-width: 720px` and `480px` breakpoints working for a tablet till.
12. **Bump the cache-busters.** `pos.js`/`pos.css` are enqueued with `CNTR_VERSION` and
    precached by the service worker. Bump `CNTR_VERSION` in `counter/counter.php` (the
    `Version:` header **and** the `define()` — `test_version()` asserts they match) and
    bump `CACHE_NAME` in `assets/sw.js` from `cntr-pos-shell-v1`, or every till will run new
    PHP against cached old JavaScript.
13. **Keep the self-test green.** `wp counter selftest` must still pass. Compare the
    labelled pass/fail list, not the total count — `HANDOVER.md` explains why the total is a
    property of a run, not of the plugin.
14. **Match the house comment style.** `pos.js`, `pos.css` and `pos.php` carry short
    comments tagged with the task id that motivated the code (`F1`, `B3`, `U2`, `P7.6`) and
    explain *why*, not *what*. New code should read like it was written by the same hand —
    but do not invent fake task ids; use a plain descriptive comment where you have none.

## 5. Currency, locale and payment reality

- Currency is the **Bangladeshi taka (৳)**, formatted by the existing `formatMoney()`.
  Do not switch to `$` or add a locale library.
- Tender methods are **Cash, Card, bKash, Nagad, Rocket, Bank** plus **Credit (on account)**
  — mobile-money methods are first-class here, not an afterthought. In the tender screen,
  give each method a large button with its own icon; split tender (multiple rows totalling
  the due amount) must survive the redesign.
- The tender screen's **Due / Taken / Remaining / Change** figures are the second most
  important numbers on the terminal after the grand total. Make Change unmissable.
- Credit sales show the customer's Owes / Limit / Available and warn before exceeding the
  limit, with an offline caveat. Keep every one of those warnings.
- Receipts are **Mushak 6.3** VAT challans; an offline sale prints a "PROVISIONAL BILL —
  NOT A VAT INVOICE" slip. Those strings stay exactly as they are.

## 6. Deliverables

1. The written element inventory from §1, with each item's new home.
2. A rewritten `assets/pos.css` — token-driven, commented, no orphaned rules. Delete
   selectors you have genuinely made dead; list them.
3. Updated `render()` and the modal renderers in `assets/pos.js` for the new structure,
   plus the new inline SVG icon helpers and the on-screen keypad.
4. Updated `strings` in `templates/pos.php`, the mirrored `STRINGS` in `pos.js`, and new
   entries in `languages/counter-bn_BD.po`.
5. Bumped `CNTR_VERSION` (header + define) and `CACHE_NAME` in `sw.js`.
6. A short changelog entry in `counter/readme.txt` under a new version heading, in the
   voice of the existing entries.
7. A statement of what you verified and how — including a `wp counter selftest` run if the
   environment allows one, and an honest note of anything you could not check.

## 7. How to work

Work **incrementally and reviewably**, not as one 4,000-line rewrite:

1. Inventory + a written layout plan (ASCII wireframe is fine). **Stop and show me this
   before writing code.**
2. Design tokens and the type/spacing scale in `pos.css`.
3. Shell: header status strip, three-zone grid, total strip.
4. The ticket/cart panel.
5. The action pad + on-screen keypad, with key badges and capability dimming.
6. Modals, one at a time, starting with tender.
7. Responsive pass, then the version/cache/changelog/self-test pass.

At each step, tell me what changed and what it cost. If a constraint above and a design
goal genuinely conflict, **say so and ask** rather than quietly picking one — for example,
if the on-screen keypad cannot coexist with the product grid at 1024×768, I would rather
decide that than discover it at the counter.

---END PROMPT---

## Why the prompt says what it says

Notes for the person using the prompt — not part of the prompt itself.

- **Three files carry the entire UI.** `templates/pos.php`, `assets/pos.js` and
  `assets/pos.css`. Everything else in the plugin is REST routes, stock ledger, reports and
  admin screens. A redesign that stays inside those three files (plus the `.po`, the version
  constant and `sw.js`) cannot break the accounting.
- **The `STRINGS` double-declaration is the biggest trap.** The same ~200 keys exist in PHP
  and in JS with no build step to keep them aligned. An LLM that adds a label in one place
  only produces a terminal that shows `undefined` to a Bengali cashier.
- **`render()` rebuilds via `innerHTML` and re-binds listeners by id.** This is the second
  trap: renaming a class is free, renaming an id silently kills a button.
- **Offline is a hard product requirement, not a nice-to-have.** The service worker
  precaches the shell and the catalogue lives in IndexedDB. Any CDN font or icon library
  turns a working till into a blank screen the moment the shop's internet drops — which, at
  a counter, is the exact moment it matters.
- **The screenshot is a layout reference, not a style reference.** Its real lesson is
  *button pad + keypad + receipt + giant total*, which is why the prompt extracts those four
  ideas and explicitly rejects the rest — including six controls the plugin has no code for.
