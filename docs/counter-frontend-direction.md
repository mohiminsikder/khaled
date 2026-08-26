# Counter v0.1.8 — Frontend Build Direction

Execution brief for the next frontend pass. Read this before touching `assets/pos.js`.

Companion page (same content, formatted): the published artifact "Counter Till-First Build Direction".

---

## 1. Verdict

| Area | State |
|---|---|
| `COUNTERIMPLEMENTATION.md` | Sound. It specified the missing pieces. Not the problem. |
| PHP backend | Good. Ledger discipline, credit limits, FIFO costing, HPOS, phone normalisation — all real and self-tested. |
| Till UI (`assets/pos.js`) | **Shell.** 5 keyboard actions are empty functions; no name search; no split tender; no credit tender. |
| wp-admin | 17 screens in one flat list; 9 back-office areas have no screen. The earlier audit read this correctly. |

**Correction to the earlier audit.** It concluded the plan is "fully built and self-tested" and that
everything left is "the next phase, not a defect in what shipped." True for wp-admin. **False for the
till.** P1.13 named F3/F4/F5/F10 in its keyboard-map table; P1.14 named split tenders in its own commit
message. They shipped as empty function bodies and a single-row form.

---

## 2. Root cause — why the suite is green

`test_terminal_assets()` is five assertions: `/pos/` isn't a 404; the HTML contains `window.CNTR` and
the asset tags; `pos.js` has no external host; `pos.js` has no `console.log`; the catalogue endpoint
honours the role.

None of them invoke a UI interaction. The logic tests that do pass reach the code through
`window.CNTR._pos` — an export hatch handing the suite `submitSale`, `searchByText` etc. directly.
**The tests call the functions; the screen never does.**

P1.13 predicted it: *"Manual — this is the bulk of the verification and cannot be done from a terminal"*,
listing *"Every key in the map, in order, on the real machine."* That is the one step a coding agent
structurally cannot perform, and it is where this landed.

---

## 3. Confirmed defect inventory (till)

| Key | P1.13 says | v0.1.8 does | State |
|---|---|---|---|
| F2 | Tender screen — **split**, card, bKash | One method, one amount. No split, no credit. | Partial |
| F3 | Change qty on selected line | `changeSelectedQty()` — empty body | **Stub** |
| F4 | Line discount / price override, audited | `lineDiscountPrompt()` — empty body | **Stub** |
| F5 | Product form: name, price, barcode, unit, opening qty | Opens `<div id="cntr-quick-add" hidden></div>` — never filled | **Stub** |
| F6 | Attach customer by phone | `prompt()`. Never calls `/customers/lookup`. | **Stub** |
| F7/F8 | Hold / resume parked sale | LIFO `pop()`, memory only. No ticket choice; refresh loses all. | Partial |
| F9 | Return or exchange | Genuinely built. | OK |
| F10 | No-sale, open drawer, audited | `noSale()` — empty body | **Stub** |
| Esc | Void line, *then* cart | `lines.pop()` — silent, no confirm | Partial |

Additional:

- **Name search unreachable.** `searchByText()` exists and works, but is only wired into
  `window.CNTR._pos` for tests. The search box calls `lookupProduct()` — exact barcode/SKU only.
  Typing "coca" does nothing, ever.
- **Dead config.** `discountCeilingPct`, `roundingStep`, `searchFields` are sent in `window.CNTR`
  by `templates/pos.php` and read by nothing. Cash rounding never happens.
- **Quick-add flag orphaned.** `_cntr_quick_added` is written, never read. P1.13's required
  Health-page listing does not exist.
- **Focus never restored.** The only `.focus()` in the file targets the print iframe. `render()`
  replaces `root.innerHTML` wholesale and relies on `autofocus`, which does not reliably re-fire on
  re-inserted nodes. After printing, focus is in the iframe.
- **Cart rows inert.** Each `<li>` has a `data-idx` and nothing listens. No selection, no per-line
  remove/qty, no line total — so "the selected line" F3/F4 act on cannot be selected.

---

## 4. PRIORITY 1 — Billing a standing customer

Currently impossible. Not awkward — impossible.

### Backend already finished

- `Pos\Customers::normalise()` — folds `+8801711…` / `8801711…` / `01711…` / `1711…` to one key.
- `Customers::lookup()` — returns **every** candidate, newest first. Its own comment: *"a household
  shares a number; the till shows a picker rather than guessing which one is right."*
- `Tenders::VALID_METHODS` already contains `'credit'`. A shortfall against an identified customer
  holding `cntr_credit_sale` becomes a real ledger debit.
- `CustomerLedger` — `check_limit()`, `balance()`, `record_payment()`, `aging()`, `statement()`.
  Limit is checked *before* the order is created and refuses with a readable message.

### Why it's unreachable

- The tender dropdown lists cash/card/bkash/nagad/rocket/bank — **credit is absent**.
- F6 is a `prompt()` storing a raw string; it never calls `/customers/lookup`. So
  `Rest\Sale::resolve_customer()` silently takes `matches[0]` — precisely the household guess the
  backend author wrote a picker comment to prevent.

### Build

**One backend addition** — `GET /customers/{id}/profile` in `Rest/Customer.php`, guard `cntr_use_pos`.
Returns `balance`, `credit_limit`, `available`, `oldest_due_days` from `CustomerLedger`, plus the
customer's ~12 most-bought items. Needed because `cntr_customer_index` holds only `phone_norm`,
`customer_id`, `display_name`, `last_order_at` — the till cannot currently see what anyone owes.

**Frontend:**

1. **Persistent customer panel** (not a modal): name, phone, owes, limit, **available credit**, oldest
   due age. Who this is and how much credit is left must never be more than one glance away.
2. **Household picker** replacing `prompt()` — render every row `lookup` returns; never auto-pick.
3. **Multi-row tender form.** `submitSale()` already accepts an array; only the form is single-row.
   Live Taken / Remaining / Change. Submit disabled until Remaining is 0.
4. **Credit tender row**, hidden entirely unless a customer is attached *and* the operator holds
   `cntr_credit_sale`.
5. **Warn before the server refuses** — compare the credit row against `available` from the profile
   and block inline with the reason. `check_limit()` stays the authority; this is courtesy.
6. **Apply `roundingStep`** to the cash row (already in `window.CNTR`, currently ignored).
7. **Print the new balance on the receipt** — a credit customer should leave holding what they owe.
8. **"Usual items" strip** — tap-to-add from the profile response. This is the "as per their need" half.

### Raise before building, do not decide silently

Price tiers are bound to the **register**, not the customer: `Groups::price_at_register()` reads
`price_group_id` from the register's `settings_json`. There is **no customer→price-group link anywhere
in the schema** (verified across all references). A wholesale regular at the retail till gets retail
prices, and no frontend work changes that. If "as per their need" includes their own pricing, that
needs a schema decision — a `price_group_id` on the customer overriding the register's when a customer
is attached. It changes what a sale costs; get a decision first.

---

## 5. PRIORITY 2 — Rest of the till (frontend only, no schema, no new endpoints)

- **Name search with a result list.** Debounce ~120 ms. Exact barcode/SKU still auto-adds instantly —
  never slow the scanner path. Otherwise call the existing `searchByText()` and render a
  keyboard-navigable list: name, SKU, price, stock. This is wiring, not new capability.
- **Live cart rows.** Selection (↑/↓ and click), per-row +/−, remove, line-total column. Then F3/F4
  have something to act on. F4 must respect `discountCeilingPct`, gate on the capability, and write
  `line.discount` (the field exists and nothing sets it).
- **F5 quick-add form.** Fill `#cntr-quick-add`: name, price, barcode, unit, opening qty. On success
  add straight to cart. `POST /quick-add` and `submitQuickAdd()` are both done.
- **Held sales.** F8 opens a list (customer, items, total, age) instead of `pop()`. Persist to
  IndexedDB. Per plan: flag a held item that has since gone out of stock.
- **Focus discipline.** Restore focus to the search box after every render, modal close and print.
- **Esc** — void the *selected line*, then the cart on a second press with confirm.
- **F10 no-sale** — capability gate, reason prompt, audit write, print the drawer-kick slip.
- **Visible function-key bar** along the bottom. The plan's answer to discoverability was "tape a card
  to the counter"; a row of labels costs nothing.

---

## 6. PRIORITY 3 — wp-admin

Confirmed: 1 `add_menu_page` + 16 `add_submenu_page`, one flat list in registration order; 9 areas with
no screen. The earlier audit's order (group sidebar → locations/registers → employees → purchasing →
transfers/batches/stocktake → settings) is sound. Two refinements:

- **Build the shared entity picker first, not seventh.** Every screen ahead of it needs to resolve a
  product/supplier/employee by name. Building it first means six screens consume it instead of six
  screens shipping raw ID fields and being retrofitted.
- **Settings ranks higher than "low daily-use" suggests.** `pos.weight_barcode_prefix`,
  `pos.discount_ceiling_pct` and `cash.rounding_step` govern till behaviour. Once §5 actually reads
  them, they stop being dormant config.

---

## 7. Build order

1. **Close the verification gap first.** Add a DOM-level test path before feature code, or the next
   pass repeats this one. A headless load of `/pos/` dispatching real key events and asserting on
   rendered output would have caught all five stubs. Chromium + Playwright are already available.
   Without it, "green" keeps meaning "the exports work."
2. **Cart rows, focus, search results.** Foundation for everything else.
3. **Standing customer: panel, picker, profile endpoint.** (Priority 1)
4. **Multi-row tender with credit.** (Priority 1) — the point a standing customer becomes billable.
5. **F3, F4, F5, F10, held-sale list.** Small once step 2 exists.
6. **Raise the pricing question, then wp-admin.** Get the customer-price-group decision before
   starting admin screens; it may add a column.

---

## 8. Scope

Steps 1–5 are **not new scope** — they finish P1.13 and P1.14 as written, plus the customer-profile
endpoint (the single genuinely new backend piece, needed only so the till can display a balance).

Step 6 and the wp-admin work **are** new scope beyond `COUNTERIMPLEMENTATION.md`, as the earlier audit
described.

Nothing here asks for a backend rewrite. The PHP is why this list is short.
