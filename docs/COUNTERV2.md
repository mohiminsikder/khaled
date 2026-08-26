# COUNTER v2 — Full Implementation Plan

**From:** v0.1.20 · **To:** v1.0.0
**Companions:** `COUNTERIMPLEMENTATION.md` (architecture, invariants, schema — still authoritative),
`COUNTERFRONTEND.md` (Phase F/U, complete), `COUNTERGOLIVE.md`, `TILLBUGS.md`
**Reference:** `SuperShopTeardown` — a live UI/function sweep of Glory IT V6.5 (UltimatePOS family),
34 pages, 85 routes. Used as a **feature and structure reference, not a design to copy.**

---

## 0. How to use this file

Same contract as `COUNTERIMPLEMENTATION.md`. Tasks run **in order** within a phase; phases run in
order. Each task carries:

```
### <id> — <title>
**Goal**      one sentence: what a person can do afterwards that they cannot do now
**Depends**   tasks that must be green first
**Backend**   PHP work, or "none — this is wiring"
**Frontend**  JS/CSS/markup work
**Files**     every file you may touch
**Schema**    table changes, or "none"
**Self-test** harness checks by name and count
**Manual**    what only a human at the till can confirm
**Commit**    the exact commit message
**Done when** the bar
```

### 0.1 Rules of engagement

1. **The five invariants in `COUNTERIMPLEMENTATION.md` §2 are not negotiable.** Stock truth is the
   ledger; every quantity write is relative; Counter is the only thing that moves stock; one write
   per sale and it is idempotent; money truth is the order.
2. **WooCommerce remains the system of record** for products, orders, customers, tax and refunds.
   SuperShop owns its own product and order tables; Counter must not grow a parallel set.
3. **No build step at the till.** `assets/pos.js` stays one plain-JS file, no framework, no CDN,
   no external host. `test_terminal_assets()` enforces it and the terminal must run offline.
4. **Bump `CNTR_VERSION`** in `counter.php` on every rebuild — `Pos\Assets` uses it as the cache
   buster and `test_version()` asserts it equals the `Version:` header. Roles are only written on
   activation and version bump, so a capability change without a bump silently does nothing.
5. **Use existing capabilities.** 41 exist in `Capabilities.php`. Add one only when a task's own
   Backend line says so.
6. **`tests/` never ships.** Excluded from the release zip.
7. **One task, one commit.** Do not batch.

### 0.2 What is already true — verified, do not re-derive

Read directly from the v0.1.20 source:

| | |
|---|---|
| Split tender with credit | **Works.** `Tenders::VALID_METHODS` includes `credit`; multi-row UI shipped in Phase F |
| Customer identity | `Customers::normalise()` folds all four BD phone forms; `lookup()` returns every candidate |
| Credit ledger | `CustomerLedger` — limit, check, balance, payment, aging, statement |
| FIFO costing | `consume_fifo()`, frozen to `_cntr_cogs` at sale time |
| Offline | IndexedDB catalogue, durable outbox, UUID idempotency, 72 h age lock, failures screen |
| Purchasing | POs, partial receiving, landed cost, supplier ledger — **built and tested, no screen** |
| Stock | Transfers, batches with lot/expiry, stocktakes — **built and tested, no screen** |
| Seeded on activation | Main location, Register R1, price groups (retail/wholesale/online), counters, payment accounts |
| Roles | 5 roles registered; `Counter Terminal` deliberately holds one capability |
| Audit | `Audit::log()` writes; **nothing reads it back** |
| Reports | valuation, margin by product, dead stock, reorder, near expiry, movement history, P&L, CSV |

And what is **absent**, verified by search returning nothing:

- Cash flow, balance sheet, trial balance
- Loyalty / reward points / coupons
- Sale-side draft, quotation and sales-order documents
- Customer→price-group link (tiers bind to the **register**, not the customer)
- Hourly sales grain — `cntr_sales_daily` PK is `(day, channel, location_id)`
- Any populated `barcode` — `Catalog::reindex()` hardcodes `''` in both the payload and the column

---

## 1. Decisions required before code

Five. Each changes what gets built. **Get answers, record them in `docs/decisions.md`, then start.**

### D1 — Does a customer carry a price tier?

`Groups::price_at_register()` reads `price_group_id` from the register's `settings_json`. There is
no customer→group link anywhere in the schema. A wholesale regular at the retail till gets retail
prices.

SuperShop puts a price-group picker on the till, changeable per sale, which re-prices the open cart.

- **(a)** Picker only — cashier selects the group per sale. Frontend only.
- **(b)** Picker plus a `price_group_id` on the customer that auto-selects on attach. Adds a column
  and changes what a sale costs.

**Recommendation: (b).** A regular's tier should not depend on the cashier remembering.

### D2 — Who may sell on account (বাকি)?

`cashier_caps()` and `supervisor_caps()` both omit `cntr_credit_sale`. A real cashier cannot bill a
standing customer at all today. Also: `cntr_refund` **is** granted to cashiers, which most shops
restrict. Answer both.

### D3 — What is the barcode?

`Catalog::reindex()` writes `''` and nothing populates it. Scanning resolves by SKU only.

- **(a)** SKU **is** the barcode. Drop the dead `barcode` field and column, fix the placeholder
  text. Honest and free.
- **(b)** A separate product meta field, populated in `reindex()`. Needed if products carry
  manufacturer EANs distinct from internal SKUs — the normal grocery case.

### D4 — Loyalty: build, or remove the affordance?

SuperShop has reward points redeemable at the till. Counter has none. The customer identity and
ledger it would build on exist. Either commit to Phase E, or do not draw the row.

### D5 — Where does the back office live?

Counter's 17 screens are wp-admin submenus. SuperShop has its own shell — sidebar, top bar, 85
routes.

- **(a)** Stay in wp-admin, adopt SuperShop's *density* inside it. Conventional, cheaper, no
  duplicate chrome.
- **(b)** A dedicated full-screen Counter shell for the back office.

**Recommendation: (a).** WordPress users expect wp-admin, and a second shell means maintaining a
second navigation, permission surface and responsive layout for no functional gain. The information
density is what matters, and it is achievable either way.

---

## 2. Verification — the part that has failed twice

v0.1.8 shipped five stubbed handlers past 445 green checks. v0.1.20 shipped a blocking search bug
past 465. Both for the same reason: **the PHP suite tests files and functions, never the screen.**
The logic tests reach code through `window.CNTR._pos`, an export hatch. The tests call the
functions; the screen never does.

Three layers, all required, before any Phase B task:

| Layer | Runs | Catches | Built in |
|---|---|---|---|
| **A — source guards** | existing `Selftest` | stubs, unwired functions, dead config | shipped (`test_pos_wiring()`) |
| **B — DOM harness** | headless Chromium | broken interactions, wrong rendered state | `tests/` — **shipped, extend it** |
| **C — manual** | the shop's real till | scanner, printer, drawer, feel | §6 |

`tests/pos-harness.html` and `tests/pos-dom.mjs` exist and found the v0.1.20 bug. **Every Phase B
task adds its assertions there**, through rendered markup only. An assertion that reaches into
`window.CNTR._pos` reproduces the exact blind spot this exists to cover.

Chromium is at `/opt/pw-browsers/`; never run `playwright install`. `sleep` is blocked in the
sandbox — use `page.waitForTimeout()`.

Per `HANDOVER.md`: **never assert on a total check count.** Guards legitimately skip checks on a
live install. Read the labelled pass/fail list.

---

# PHASE A — Fix and go live

Nothing else matters until a shop can actually open the till and bill correctly. All of this is
small; most of it is already specified in `TILLBUGS.md` and `COUNTERGOLIVE.md`.

### A1 — Every second name-search item silently fails

**Goal** A cashier can add five items in a row by name and get five lines.
**Depends** nothing. **First.**
**Backend** none
**Frontend** delete `assets/pos.js:795` (`suppressNextEnter = true` inside `addHighlightedResult()`)
**Files** `assets/pos.js`
**Schema** none

`suppressNextEnter` exists for the scanner: the input listener auto-adds on an exact code match and
the trailing Enter must be swallowed (`pos.js:2727`, `2732` — both correct). But
`addHighlightedResult()` is reached **from inside the Enter handler**, so that Enter is already
consumed. The flag stays armed and eats the next product's Enter; the handler returns before the box
is cleared, so text accumulates and the next query concatenates.

Verified: removing that one line fixes it — three items add, box clears, total correct. **Do not**
reset the flag in the Enter handler instead; that re-breaks the scanner path.

**Self-test** DOM: add three products by name in sequence; assert three cart lines, empty search box
after each, correct total.
**Manual** none.
**Commit** `counter: pos: stop the result-list Enter suppressing the next one`
**Done when** The three-item DOM assertion passes.

### A2 — Enter does not submit the customer lookup

**Goal** `F6` → type phone → Enter attaches the customer, no mouse.
**Depends** A1
**Backend** none
**Frontend** bind Enter on `#cntr-customer-phone` to the Find handler; audit the quantity, discount,
no-sale and quick-add modals for the same gap
**Files** `assets/pos.js`
**Self-test** DOM: Enter on the phone field attaches; the same for every modal added in Phase F.
**Commit** `counter: pos: submit every till modal on Enter`

### A3 — Decide and populate the barcode

**Goal** Scanning resolves the way the shop's own barcodes are printed.
**Depends** **D3 answered**
**Backend** per D3(a) remove the field from the payload and the column; per D3(b) read a product meta
field in `Catalog::reindex()` and bump `catalog_rev` so terminals re-pull
**Frontend** fix the search placeholder to match what actually resolves
**Files** `includes/Stock/Catalog.php`, `assets/pos.js`, `includes/Install.php` (D3a only)
**Schema** D3(a) drops the `barcode` column · D3(b) none
**Self-test** `test_catalog_barcode()` — 4 checks: a product with a barcode indexes it; the delta
carries it; the terminal's `byBarcode` map is non-empty after sync; a product without one still
resolves by SKU.
**Manual** Scan twenty items off the shelf.
**Commit** `counter: catalog: populate the barcode field from its real source`

### A4 — Capability grants

**Goal** The cashier at the till can do what the shop intends, and no more.
**Depends** **D2 answered**
**Backend** grant `cntr_credit_sale` to the chosen role; move `cntr_refund` per the answer
**Files** `includes/Capabilities.php`, `counter.php` (version bump — **roles only write on
activation and version bump**)
**Self-test** extend `test_caps()` — 3 checks: the chosen role holds `cntr_credit_sale`; a plain
cashier's `can_credit` matches the decision; refund sits where D2 put it.
**Manual** Log in as a real cashier — not an administrator — and confirm the credit row appears.
**Commit** `counter: caps: grant credit-sale and place refund per the shop's decision`

### A5 — The till has no link anywhere

**Goal** Someone who has never seen Counter can find the till.
**Depends** nothing
**Backend** admin-bar node gated on `cntr_use_pos` or `cntr_terminal_access`; a dashboard panel
listing each active register with its own `/pos/?register=<id>` URL and a copy control
**Files** `includes/Admin/Menu.php`, `includes/Admin/Screens/Dashboard.php`
**Self-test** `test_pos_entry_points()` — 3 checks: the dashboard contains a link to
`home_url('/pos/')`; the admin-bar node registers for a user with `cntr_use_pos`; absent without it.
**Commit** `counter: admin: surface the till with admin-bar and dashboard entry points`

### A6 — First-run panel and the install guide

**Goal** A new owner can tell whether the shop is ready to sell.
**Depends** A5
**Backend** a dismissible dashboard panel reporting **real state**: seeded location and register,
payment accounts, till URL, cashier count, product count, shift open or not — each missing item
linking to where it is fixed
**Files** `includes/Admin/Screens/Dashboard.php`, `docs/install-guide.md`, `docs/counter-card.md`,
`readme.txt`
**Docs** the guide must cover the **Chrome `--kiosk-printing` shortcut** and the printer as Windows
default — P1.14 is explicit that this is machine setup, not code, and it is the difference between a
six-second sale and a twenty-second one. Also: the drawer checkbox in the printer driver; creating a
cashier via Users → Add New with the **Counter Cashier** role (the roles are registered, it is simply
written down nowhere); bookmarking each till to its own register.
**Self-test** `test_first_run_panel()` — 3 checks: reports the seeded register; reports zero cashiers
on an install with none; does not render once dismissed.
**Commit** `counter: admin: first-run readiness panel, install guide and counter card`

## PHASE A acceptance

- [ ] `test_pos_wiring()` 9/9; no new failures against the v0.1.20 baseline
- [ ] `node tests/pos-dom.mjs` green, no skips
- [ ] A real cashier account bills a standing customer on split tender with a credit remainder,
      reconciled against Receivables
- [ ] The manual key pass in §6 completed on the shop's own hardware

---

# PHASE B — The till, to SuperShop parity

The teardown's own verdict on SuperShop's POS: *"the part of the product that was actually designed
rather than generated from a CRUD scaffold."* Its strongest idea is **five ways to conclude a
transaction from one bar — express cash, express card, credit, multi-tender, suspend — plus two ways
to save it as a non-sale document, with no mode switch.** Counter has three of the five.

### B1 — Product tile grid

**Goal** A cashier who does not know the name or the barcode can still sell.
**Depends** A1
**Backend** none — every catalogue row is already in IndexedDB with name, SKU, price and
`sellable_qty`
**Frontend** right-hand scrolling grid: name, SKU, price, stock in the unit of measure. Category
chips filter it. Tap adds to cart. Low-stock rows carry a text marker, **not colour alone** —
`pos.css` already gets this right for the online/offline badge and that standard holds.
**Files** `assets/pos.js`, `assets/pos.css`
**Self-test** DOM: the grid renders from the fixture catalogue; a category chip filters it; tapping
a tile adds a line; a below-threshold tile carries its marker.
**Commit** `counter: pos: product tile grid with category filter`

### B2 — Price group picker

**Goal** A wholesale customer is billed at wholesale.
**Depends** B1, **D1 answered**
**Backend** D1(a): expose the active groups and a re-price endpoint. D1(b): add `price_group_id` to
the customer, return it on `/customers/{id}/profile`, auto-select on attach.
**Frontend** picker beside the customer strip; changing it **re-prices the open cart** and says so.
**Files** `includes/Pricing/Groups.php`, `includes/Rest/Customer.php`, `assets/pos.js`
**Schema** D1(b) adds one column
**Self-test** `test_price_group_at_till()` — 5 checks: the picker lists active groups; switching
re-prices every line; a group with no override falls back to the WooCommerce price with the online
filter suppressed; D1(b) — attaching a customer selects their group; a sale records the group used.
**Manual** Bill the same basket as retail and as wholesale; confirm both totals and the receipt.
**Commit** `counter: pos: price group picker that re-prices the open cart`

### B3 — Per-line unit and editable subtotal

**Goal** Loose goods and multi-unit products bill correctly without mental arithmetic.
**Depends** B1
**Backend** none — `Stock\Units` already holds units and conversion multipliers per product
**Frontend** per-line unit dropdown from the product's configured units; editable subtotal that
back-solves the unit price; live stock under the line in the selected unit
**Files** `assets/pos.js`, `assets/pos.css`
**Self-test** DOM: switching unit converts qty and subtotal by the multiplier; overtyping a subtotal
back-solves the unit price; the line total still sums into the cart total.
**Manual** Sell 1.5 kg of something priced per kg.
**Commit** `counter: pos: per-line unit selection and editable subtotal`

### B4 — Order-level discount and the totals footer

**Goal** "Round it down to two thousand" takes one action.
**Depends** B3, **D4 answered**
**Backend** the order-level discount must reach `Orders\Builder` as a real WooCommerce discount, not
a fudged line
**Frontend** two-line totals footer — Items and Total, then Discount (−), Order tax (+), Shipping (+)
and Round off, each with a pencil opening its own modal. **Enforce `discountCeilingPct`.** Round off
is display-only and follows `cash.rounding_step`. Draw the reward-points row **only if D4 says
build**.
**Files** `assets/pos.js`, `assets/pos.css`, `includes/Orders/Builder.php`
**Self-test** DOM: an order discount reduces the total; one above the ceiling is refused with a
reason; round-off follows the step; the footer sums correctly. Plus `test_order_discount()` — 3
checks: the discount reaches the order; it is excluded from COGS; the receipt prints it.
**Commit** `counter: pos: order-level discount, tax, shipping and round-off footer`

### B5 — Close the register from the till, with a live X-report

**Goal** A cashier reconciles their own drawer without opening WordPress.
**Depends** A6
**Backend** `Pos\Shifts` already closes a shift server-side. Add an X-report endpoint: sell and
expense **per payment method**, then opening float, total sales, total refund, total expense, and
expected cash. Store the counted figure and the variance on close.
**Frontend** the `💼` toolbar icon opens the live X-report; `❎` closes the register — count entry,
variance shown before confirming, then print. **Print the arithmetic in words** underneath, as
SuperShop does: `opening + cash sale − cash refund − cash expense = expected`. Below it, products
sold by SKU.
**Files** `includes/Pos/Shifts.php`, `includes/Rest/Shift.php`, `assets/pos.js`, `assets/pos.css`
**Schema** `cntr_shifts` gains `counted_cash`, `variance`
**Self-test** `test_x_report()` — 7 checks: per-method sell totals match the tender rows; `is_change`
rows are excluded; expense is subtracted; expected cash equals the printed formula; a variance is
stored on close; closing twice is refused; a closed shift is immutable.
**Manual** Close a real shift, count the drawer, confirm the variance matches a hand count.
**Commit** `counter: pos: live X-report and register close at the till`

### B6 — Draft, quotation and suspend

**Goal** Five ways to conclude, two ways to park — the teardown's strongest single finding.
**Depends** B4
**Backend** **new.** A sale document that is not yet a sale. Draft and quotation are WooCommerce
orders in a Counter-owned status that **never touch the stock ledger** and are invisible to
`sales_daily` and P&L until finalised. Suspend stays client-side and durable (Phase F persisted held
sales to IndexedDB) — a parked ticket is not a commitment and **never reserves stock**.
**Frontend** Draft, Quotation, Suspend on the checkout bar; a list for each; resume into the cart.
**Files** `includes/Orders/Channel.php`, `includes/Orders/Builder.php`, `includes/Rest/Sale.php`,
`assets/pos.js`
**Schema** none — status only
**Self-test** `test_sale_documents()` — 8 checks: a draft writes no ledger move; a quotation writes
none; neither appears in `sales_daily`; neither appears in P&L; finalising a draft writes exactly one
ledger move; finalising is idempotent by UUID; a suspended cart survives a reload; a suspended cart
reserves nothing.
**Manual** Park three tickets, serve someone else, resume the middle one.
**Commit** `counter: sell: draft and quotation documents, durable suspend`

### B7 — Add expense and sell-return from the till

**Goal** The cashier never leaves the till for the two things that interrupt a shift.
**Depends** B5
**Backend** none — `Reports\Expenses` and the returns path both exist; `F9` return already works
**Frontend** the `⊖` toolbar action opens a full expense document — location, category, reference
(auto if blank), date, expense-for, tax, amount, note, plus a payment block with method and account
and a running due. The `↶` action takes an invoice number and lands on that sale's return form.
**Files** `assets/pos.js`, `assets/pos.css`
**Self-test** DOM: the expense modal posts and the X-report's expense total moves; an invoice lookup
loads the right sale.
**Commit** `counter: pos: add expense and sell-return lookup at the till`

### B8 — Quick-add with a selling price

**Goal** A product created at the till is priced correctly.
**Depends** B1
**Backend** `POST /quick-add` gains a required `price`
**Frontend** the form gains selling price, unit, category and alert quantity

> **Teardown defect #13.** SuperShop's quick-add has *no selling-price field at all* — new products
> silently inherit a business-wide 20% default margin, "which will silently be wrong for most real
> items." Counter's endpoint already takes a price; do not copy the omission.

**Files** `includes/Pos/Terminal.php`, `assets/pos.js`
**Self-test** extend `test_quick_add()` — 3 checks: a missing price is refused; the created product
carries the given price; it appears in the Health incomplete list until category and cost are set.
**Commit** `counter: pos: quick-add requires a selling price`

### B9 — Configurable search fields and keyboard map

**Goal** The shop tunes the till without a developer.
**Depends** B1
**Backend** settings keys already exist (`pos.search_fields`, `pos.weight_barcode_prefix`,
`pos.discount_ceiling_pct`, `cash.rounding_step`); add the keyboard map as one more
**Frontend** the magnifier opens "Search products by" — the fields the autocomplete queries, exposed
**to the cashier** rather than buried in settings, which the teardown singles out as well judged.
Keyboard bindings read from settings rather than hardcoded.

> **Not adopted: configuration by subtraction.** SuperShop ships eighteen "Disable…" toggles *plus*
> duplicate per-role lockouts — two mechanisms for one job, with the default posture "everything on,
> switch off what this shop shouldn't have." Counter gates on the capabilities the roles already
> carry. One mechanism.

**Files** `includes/Settings.php`, `assets/pos.js`, and the Settings screen (C8)
**Self-test** DOM: turning a search field off removes it from matching; a rebound key fires the new
action.
**Commit** `counter: pos: configurable search fields and keyboard map`

## PHASE B acceptance

- [ ] Five ways to conclude a sale and two ways to park it, all reachable from one bar, no mode switch
- [ ] Every Phase B DOM assertion green, no skips
- [ ] A shift opened, sold through, and closed with a counted drawer — entirely at the till

---

# PHASE C — Back office

This is `COUNTERFRONTEND.md` U5, scoped properly. Nine areas have no screen while their backends are
built and tested. **Most of these tasks are forms over working machinery, not new capability.**

Shell per **D5**. Every list screen shares one template, learned once:

- **Filters** — a collapsed accordion
- **Card header** — title left, primary `+ Add` right
- **Toolbar** — show N, exports, search
- **Rows** — one Actions menu per row
- **Footer** — totals on financial tables, then paging

> **Teardown defect #14.** Every export button in SuperShop renders permanently disabled — gated
> behind a permission not granted even to admin. Counter's exports are enabled by role from the
> start, and `cntr_export` already exists.

### C1 — The shared list template and entity picker

**Goal** Every screen after this is assembly.
**Depends** Phase A
**Backend** a list-table base class: filters, paging, sort, export via `cntr_export`
**Frontend** the anatomy above; `Admin\EntityPicker` (shipped in U4) is the name-search widget
**Files** `includes/Admin/ListTable.php` (new), `includes/Admin/EntityPicker.php`
**Self-test** `test_list_template()` — 4 checks: filters round-trip; paging is stable across a sort;
export respects `cntr_export`; a user without it sees no export buttons.
**Commit** `counter: admin: shared list template with filters, paging and gated export`

### C2 — Products

**Goal** Stock is managed without leaving Counter.
**Depends** C1
**Backend** none — WooCommerce is the product master; this reads and links
**Frontend** tabs (All products · Stock report · Variations · Low stock); columns for image, SKU,
category, brand, unit cost, selling price, stock per location, type, tax; row actions
**Files** `includes/Admin/Screens/Products.php` (new)
**Self-test** `test_products_screen()` — 4 checks: a variable product shows per-variation stock;
low-stock filter matches `reorder_list()`; unit cost is hidden without `cntr_view_cost`; the picker
resolves by name.
**Commit** `counter: admin: products screen with stock and variation tabs`

### C3 — Suppliers, purchase orders, receiving

**Goal** The purchasing half of the business becomes operable. **The largest task here.**
**Depends** C1, C2
**Backend** none — `Purchasing\Suppliers`, `Orders`, `Receiving`, `SupplierLedger` are all built and
self-tested, including near-duplicate vendor detection and landed-cost distribution
**Frontend** three screens. The purchase line grid captures, per row: quantity, unit cost before
discount, discount %, unit cost before tax, line total, **margin %** and **unit selling price** — so
buying and selling price are set in one keystroke sequence with margin as the link. Editing margin
recalculates price and vice versa; both write through to the price group on save. Receiving is a
scan-driven partial-receive with lot, expiry and a short/complete status per line, then landed cost,
then labels, then post to stock.
**Files** `includes/Admin/Screens/Suppliers.php`, `PurchaseOrders.php`, `Receiving.php` (all new)
**Self-test** `test_purchase_screens()` — 6 checks: margin and selling price stay consistent both
directions; a partial receive leaves the PO open; landed cost distributes by received value; posting
writes one ledger move per line; a batch is created with lot and expiry; the supplier ledger debit
equals the landed total.
**Manual** Receive a real delivery, short one line, print labels, reconcile the supplier bill.
**Commit** `counter: admin: suppliers, purchase orders and receiving`

### C4 — All sales

**Goal** Every sale document, till or desk, in one place.
**Depends** C1, B6
**Backend** none
**Frontend** the widest table: date, invoice, customer, total, payment status, due, method, status,
shipping. Filter accordion: location, customer, payment status, date range, cashier, shipping status,
register/shift, method. Row actions: View · Edit · Print invoice · Packing slip · Delivery note ·
Challan · View payments · Sell return · Invoice URL.

> **Teardown defect #7.** SuperShop's Sales Order list reads "No data available" while the dashboard
> reports four open orders — the default date filter silently excludes them. **Every date filter here
> defaults to a range that includes the data**, and a filter that returns nothing says which filter
> is responsible.

**Files** `includes/Admin/Screens/Sales.php` (new)
**Self-test** `test_sales_screen()` — 4 checks: the default range includes today's sales; a filter
returning zero rows names the responsible filter; totals match `sales_daily`; row actions honour
their capabilities.
**Commit** `counter: admin: all-sales list with filters and row actions`

### C5 — Locations, registers, transfers, batches, stocktake

**Goal** A second till or a second branch can exist without a database insert.
**Depends** C1
**Backend** none — `Stock\Locations`, `Pos\Registers`, `Transfers`, `Batches`, `Stocktake` all built
and tested
**Frontend** five screens. Locations and registers are create/edit/deactivate — **the precondition
for everything multi-site.** Stocktake is the count sheet → variance flow P2.9's own Direction
describes. Batches browse by lot and expiry with a near-expiry filter.

> **Teardown defect #10.** SuperShop allows two locations both named "Pharmacy", distinguishable only
> by landmark. Refuse duplicate location and register names.

**Files** `includes/Admin/Screens/Locations.php`, `Transfers.php`, `Batches.php`, `Stocktake.php`
(all new)
**Self-test** `test_stock_screens()` — 6 checks: a duplicate location name is refused; a new register
gets a unique prefix; a transfer moves stock and nothing else; a stocktake variance writes an
adjustment with a reason; near-expiry matches at a boundary date; deactivating a location with stock
is refused.
**Manual** Create a second register, bookmark it, sell from both.
**Commit** `counter: admin: locations, registers, transfers, batches and stocktake`

### C6 — Employees, and roles and permissions

**Goal** Onboarding a cashier is a form, and who may do what is visible.
**Depends** C1, A4
**Backend** `People\Employees` exists. The roles UI writes capabilities.
**Frontend** employee create/edit with PIN assignment and a WordPress user link. A permissions matrix
— capability rows, role columns — over `Capabilities.php`.

> This screen is why A4 exists. Two wrong default grants sat unnoticed because roles were code-only.

**Files** `includes/Admin/Screens/Employees.php`, `Roles.php` (new), `includes/Capabilities.php`
**Self-test** `test_roles_screen()` — 5 checks: the matrix reflects the live role; a change persists
across a request; the Administrator role cannot be edited or deleted; `cntr_manage_settings` cannot
be granted from here; a change is audited.
**Commit** `counter: admin: employees and a roles-and-permissions matrix`

### C7 — Settings

**Goal** The system is tuned without SSH.
**Depends** C1, B9
**Backend** none — a form over `Settings::get()/set()`
**Frontend** tabs: Business · POS · Tax · Documents · Backup. The POS tab carries the keyboard map
and the till toggles from B9.
**Files** `includes/Admin/Screens/Settings.php` (new)
**Self-test** `test_settings_screen()` — 4 checks: a saved value round-trips; an invalid value is
refused with a reason; the screen requires `cntr_manage_settings`; changing a POS key bumps the
catalogue revision where the terminal needs to notice.
**Commit** `counter: admin: settings screen over the existing store`

### C8 — Print labels with a live preview

**Goal** A "designer" shows the design.
**Depends** C2
**Backend** `Docs\Labels` and `Docs\Barcode` (Code128 / EAN-13 SVG) both exist
**Frontend** replace fourteen raw millimetre fields with: product picker, label count, price group,
tax inclusive/exclusive, barcode type, sheet size, per-field toggles each with a point size — and a
**live preview that redraws as those change**. What prints is what is shown.
**Files** `includes/Admin/Screens/LabelDesigner.php`
**Self-test** `test_label_preview()` — 3 checks: the preview renders the selected fields; a barcode
type change re-renders; the sheet count matches the requested label count.
**Manual** Print one sheet and hold it against a shelf.
**Commit** `counter: admin: label designer with a live preview`

---

# PHASE D — Reporting and money

### D1 — Hourly grain

**Goal** "When is the shop busy" is answerable.
**Depends** Phase A
**Backend** `cntr_sales_daily` PK is `(day, channel, location_id)` — no hour. Add an `hour` column to
the key, or a sibling `cntr_sales_hourly`. Prefer the sibling: it keeps daily queries unchanged and
the rollup already rebuilds from orders.
**Files** `includes/Install.php`, `includes/Reports/Rollup.php`
**Schema** `cntr_sales_hourly`; `CNTR_DB_VER` bump
**Self-test** `test_hourly_rollup()` — 4 checks: hourly sums equal the daily row; a rebuild is
idempotent; the shop-local day boundary is respected (the timezone comment in `Rollup.php:205`
already handles this — do not regress it); an empty hour returns zero, not a missing row.
**Commit** `counter: reports: hourly sales rollup`

### D2 — Trending products and peak hours

**Goal** The dashboard answers what sells and when.
**Depends** D1
**Backend** a top-sellers query by units, revenue and margin over a range
**Frontend** dashboard panels
**Files** `includes/Reports/Reports.php`, `includes/Admin/Screens/Dashboard.php`
**Self-test** `test_trending()` — 3 checks: ranking by units and by revenue differ where they should;
the range filter is honoured; margin comes from frozen COGS, not a recomputed cost.
**Commit** `counter: reports: trending products and peak hours`

### D3 — Register report

**Goal** Till sessions are auditable.
**Depends** B5
**Backend** one row per shift, one column per payment method, transaction counts, plus the drawer
variance B5 stores
**Files** `includes/Reports/Reports.php`, `includes/Admin/Screens/RegisterReport.php` (new)
**Self-test** `test_register_report()` — 4 checks: per-method totals match the tender rows; counts
match transactions; variance matches what close stored; an open shift appears as open.
**Commit** `counter: reports: register report with drawer variance`

### D4 — Cash flow and balance sheet

**Goal** The owner can see money move.
**Depends** C3
**Backend** **new.** Cash flow is a running ledger: date, account, description (transaction type,
counterparty, reference, added by), method, debit, credit, account balance and total balance — both
running. The balance sheet is a summary: supplier due against customer due, closing stock, every
account balance.

> **Teardown defects #4, #9, #15.** SuperShop carries 1,168 payments linked to no account — invisible
> to cash flow and balances. Its P&L prints a **negative COGS** because closing stock exceeds opening
> plus purchases, with the formula shown but never guarded. Its balance sheet shows ৳4.84M liability
> against ৳606.89M assets and makes no attempt to balance.
>
> Counter must: refuse a tender without an account (`Tenders` already does); **guard the COGS
> formula** and report the anomaly instead of printing it; and label the balance sheet honestly as a
> summary, not a double-entry statement — or not ship it.

**Files** `includes/Reports/CashFlow.php`, `BalanceSheet.php` (new), screens
**Schema** none — reads existing ledgers
**Self-test** `test_cash_flow()` — 6 checks: the running balance is consistent row to row; every
payment resolves to an account; a negative COGS is reported as an anomaly and not printed as a
figure; the balance sheet's own totals are internally consistent; closing stock matches the ledger;
a date range change does not break the carry-forward.
**Commit** `counter: money: cash flow ledger and balance sheet, with a guarded COGS`

### D5 — Activity log

**Goal** `Audit::log()` has a reader.
**Depends** C1
**Backend** a paged query over `cntr_audit_log`
**Frontend** subject, action, actor, resulting state as chips; filters by actor, action and range
**Files** `includes/Admin/Screens/ActivityLog.php` (new)
**Self-test** `test_activity_log()` — 3 checks: a discount write appears; it requires
`cntr_view_audit`; the range filter is honoured.
**Commit** `counter: admin: activity log over the existing audit table`

---

# PHASE E — Decisions deferred, not forgotten

Do not start these inside Phase A–D.

### E1 — Loyalty (per D4)

Points earned per sale, redeemable at the till against an identified customer. The foundation —
customer identity, a working ledger, an order-level discount from B4 — all exists. This is a
feature, not a rearchitecture. **If D4 says no, remove the reward-point row from B4 rather than
leaving a label over nothing.**

### E2 — Payment integration

Counter **records** payments; it does not process them. A tender is method + amount + reference.
There is no card-terminal integration, no NFC, no QR generation. The gateway map in
`Screens/Accounts.php` reconciles *online* WooCommerce orders and is not a counter integration.

A real bKash/Nagad merchant QR needs a merchant account and each provider's API. **Scope it as its own
project or accept the boundary** — for a cash-and-bKash shop the boundary is usually correct, and it
keeps the installation out of card-data scope, which is a stronger position than storing card data
well. Do not describe Counter as "PCI compliant"; the accurate claim is that it is out of card-data
scope.

### E3 — Customer-facing display

A second screen showing the itemised cart, total, a payment QR and loyalty balance. Cheap once E1 and
E2 land — it is a read-only view of state the till already holds — and near-useless before them.

---

## 3. What we deliberately do not take from SuperShop

| Pattern | Why not |
|---|---|
| Configuration by subtraction — 18 "Disable…" toggles plus duplicate role lockouts | Two mechanisms for one job. Counter gates on capabilities. |
| Its own product, order and customer tables | WooCommerce is the system of record. A parallel set is the thing this plugin exists to avoid. |
| Permanently disabled exports (#14) | Gate on `cntr_export` and grant it. |
| Broken product imagery everywhere (#1, #2) | Ship the image or ship no image element. |
| Quick-add with no selling price (#13) | B8 requires one. |
| Unguarded negative COGS (#9) | D4 guards and reports it. |
| Date filters that hide their own data (#7) | C4 defaults to a range that includes it. |
| Duplicate location names (#10), duplicate units (#11) | C5 refuses duplicates. |
| Placeholder custom-field labels shipped to production — QQ, WW, EE (#5) | Do not ship unrenamed fields. |
| A balance sheet that does not balance (#15) | D4 labels it a summary or does not ship it. |

## 4. What we take, and credit

Five ways to conclude a sale from one bar with no mode switch · the X-report that prints its own
arithmetic in words · cost → margin → selling price captured in one purchase row · the filter
accordion and one list template learned once · configurable search fields exposed to the cashier
rather than buried · per-location invoice layouts, separately for till and desk · an unsaved-cart
guard on navigation.

---

## 5. Sequencing

```
A1 A2 → A3(D3) → A4(D2) → A5 → A6         Phase A · fix and go live
                    ↓
B1 → B2(D1) → B3 → B4(D4) → B5 → B6 → B7   Phase B · the till
     B8, B9 in parallel after B1
                    ↓
C1 → C2 → C3        (largest)              Phase C · back office
     C4(after B6), C5, C6(after A4), C7, C8
                    ↓
D1 → D2 → D3(after B5) → D4(after C3) → D5 Phase D · reporting
                    ↓
E1(D4) · E2 · E3                           Phase E · deferred
```

Phase A is days. Phase B is the till and should not be rushed — it is the screen a person touches all
day. Phase C is the largest by volume and the least risky by nature, because almost every task is a
form over machinery that is already built and self-tested. Phase D is small once D1 lands.

## 6. The manual pass — cannot be skipped, cannot be automated

`COUNTERIMPLEMENTATION.md` P1.13 specified this and it was never run. That is how v0.1.8 shipped a
hollow till and v0.1.20 shipped a blocking search bug. Run it on the shop's own hardware at the end
of Phase A, and again at the end of Phase B.

**Scanner** — twenty items off the shelf resolve at the right price and unit · a scan into a focused
text field does not land in it · a hand-typed SKU is not treated as a scan · a weight barcode parses.

**Offline** — unplug: search works, a sale completes and queues · reconnect: it drains and the receipt
number is unchanged.

**Keyboard** — every key in the map, in order, on the real machine.

**Paper and drawer** — receipt prints with **no dialog**, correct ৳, correct Bengali, correct width ·
drawer opens on a cash sale and **not** on a credit sale · `F10` prints the slip and kicks the drawer
· a reprint is identical.

**The standing customer** — attach a real regular by phone, right person and balance · a household
number shows a picker, not a guess · bill part cash, part bKash, remainder on account · the receipt
shows the new balance · Receivables shows the debit and aging moves · take a part payment, the balance
drops · an over-limit credit sale is refused with a message a cashier can act on.

**The shift** — open with a float, sell through, run the X-report, count the drawer, close, and
confirm the variance matches a hand count.
