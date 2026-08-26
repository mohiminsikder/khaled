# COUNTER — Frontend Remediation Plan

**Target:** Counter v0.1.8 → v0.2.0
**Companion to:** `COUNTERIMPLEMENTATION.md` (the original build plan — still authoritative for
architecture, invariants and schema)
**Scope:** the till (`/pos/`) first, wp-admin second. Functional defects before visual work.

---

## 0. How to use this file

Same contract as `COUNTERIMPLEMENTATION.md`. Work tasks **in order**. Each task carries:

```
### F<n> — <title>
**Goal**        one sentence, what a person can do afterwards that they cannot do now
**Depends on**  tasks that must be green first
**Files**       every file you may touch
**Direction**   what to build and why
**Self-test**   the harness checks to add, by name and count
**Manual**      what only a human at the till can confirm
**Commit**      the exact commit message
**Done when**   the bar
```

### 0.1 Rules of engagement

1. **Do not refactor the PHP backend.** It is correct, tested and not the problem. If a task seems
   to need a backend change beyond the one endpoint named in F2, stop and ask.
2. **Do not rewrite `pos.js` as a framework app.** No React, no build step, no CDN, no external
   host. `test_terminal_assets()` check 3 enforces this and it is a real constraint — the till runs
   offline. Plain JS, one file, same IIFE.
3. **Do not add `console.log`.** Check 4 enforces it.
4. **Bump `CNTR_VERSION`** in `counter.php` on every rebuild. `Pos\Assets` uses it as the cache
   buster; skipping it means new PHP runs against cached old JS and presents as a failed fix.
   `test_version()` asserts the constant equals the `Version:` header — change both.
5. **Every capability gate uses an existing capability.** Do not invent new ones. The ones this
   plan needs already exist in `Capabilities.php`: `cntr_credit_sale`, `cntr_discount_line`,
   `cntr_price_override`, `cntr_no_sale`, `cntr_void_line`, `cntr_hold_sale`, `cntr_manage_stock`,
   `cntr_use_pos`, `cntr_terminal_access`.
6. **Dev-only test files never ship.** Anything under `tests/` is excluded from the release zip.
   The plugin the shop installs contains exactly what v0.1.8 contained, plus the changed source.
7. **One task, one commit.** Do not batch.

### 0.2 What is already true — do not re-derive it

Verified by direct read of the v0.1.8 source. Take these as given:

- `Tenders::VALID_METHODS` already contains `'credit'`. The credit path is built and tested.
- `CustomerLedger` has `check_limit()`, `balance()`, `record_payment()`, `aging()`, `statement()`.
- `Customers::normalise()` folds all four Bangladeshi phone forms onto one key.
- `Customers::lookup()` returns **every** candidate for a phone, newest first.
- `searchByText()` exists in `pos.js`, works, and is wired only into the test export.
- `submitSale(tenders)` already accepts an **array** of tender rows.
- `POST /quick-add` and `submitQuickAdd()` are both finished.
- `cart.lines[]` already carries `discount` and `note` fields. Nothing sets them.
- `window.CNTR` already carries `discountCeilingPct`, `roundingStep`, `searchFields`. Nothing
  reads them.

---

## 1. The verification problem — read before writing any code

This plan exists because v0.1.8 shipped a hollow till while the self-test suite was green. That is
not an accident and it will happen again unless the harness changes first.

**Why it happened.** `test_terminal_assets()` is five assertions: `/pos/` is not a 404; the HTML
contains `window.CNTR` and the asset tags; `pos.js` has no external host; `pos.js` has no
`console.log`; the catalogue endpoint honours the role. **None of them invoke a UI interaction.**
The logic tests that pass reach the code through `window.CNTR._pos` — an export hatch at the bottom
of `pos.js` handing the suite `submitSale`, `searchByText` and a dozen others directly. The tests
call the functions. The screen never does. An empty function body passes every existing check.

`COUNTERIMPLEMENTATION.md` P1.13 said so itself: *"Manual — this is the bulk of the verification and
cannot be done from a terminal"*, listing *"Every key in the map, in order, on the real machine."*
That manual pass is the one step a terminal agent structurally cannot perform, and it is exactly
where this landed.

### 1.1 Three layers, all required

| Layer | Runs where | Catches | Added in |
|---|---|---|---|
| **A — source guards** | existing PHP `Selftest` | stubs, unwired functions, dead config | F0 |
| **B — DOM harness** | headless Chromium, dev machine | broken interactions, wrong rendered state | F0 |
| **C — manual** | the shop's real till | scanner, printer, drawer, feel | §4 |

Layer A is cheap and lives in the harness the project already runs. Layer B is the one that would
have caught all five stubs. Layer C cannot be automated and must still be done.

### 1.2 How the existing harness works

- `includes/Admin/Selftest.php` — **71** `private function test_*()` methods, **445**
  `$this->check()` call sites.
- `run()` calls each `test_*()` method in sequence. **A new test method must be added to `run()`
  or it never executes.**
- Signature: `$this->check( string $label, bool $pass, string $detail = '' )`.
- Label convention: `'test_method_name: what this asserts'`.
- The suite runs **only** from the Health screen: `wp-admin/admin.php?page=counter-health&selftest=1`.
  There is no WP-CLI command for it. Do not add one in this plan.
- Per `HANDOVER.md`: **never assert on a total check count.** Guards legitimately skip checks on a
  live install. Compare the labelled pass/fail list, not a number.

---

## 2. Decision required before F4 — do not decide this alone

**Price tiers bind to the register, not the customer.** `Pricing\Groups::price_at_register()` reads
`price_group_id` out of the register's `settings_json`. There is **no customer→price-group link
anywhere in the schema** — verified across every reference in the codebase.

Consequence: a wholesale regular who walks up to the retail till gets retail prices, and no amount
of frontend work changes that.

If "bill a standing customer as per their need" is meant to include *their own pricing*, that needs
a schema decision — a `price_group_id` on the customer, overriding the register's when a customer is
attached. **It changes what a sale costs. Raise it with the owner and get an answer before starting
F4.** Do not build it silently, and do not assume it is out of scope.

Record the answer in `docs/decisions.md` either way.

---

# PHASE F — Functional

Nothing in this phase is a styling task. The goal is a till where every advertised action does the
thing it says. Ugly but correct is the target; Phase U makes it good.

---

### F0 — Close the verification gap

**Goal** A stubbed handler or an unwired function fails a test instead of shipping.
**Depends on** nothing. **This is first.** Every task after it is verified by it.
**Files** `includes/Admin/Selftest.php`, `tests/pos-harness.html` *(new, dev-only)*,
`tests/pos-dom.mjs` *(new, dev-only)*, `tests/README.md` *(new)*

**Direction**

**Layer A — source guards in the PHP harness.** Add `test_pos_wiring()` and register it in `run()`
immediately after `test_terminal_assets()`. It reads `assets/pos.js` as a string and asserts
structure. This is deliberately crude and deliberately effective: it is the check that would have
caught v0.1.8.

Nine checks:

1. No handler named in the keyboard map has an empty body. Extract each of
   `changeSelectedQty`, `lineDiscountPrompt`, `noSale`, `openQuickAdd`, `attachCustomerPrompt`,
   `holdSale`, `resumeSale`, `voidLineThenCart` and assert the body contains at least one
   statement that is not a comment.
2. `pos.js` contains no `prompt(` call — a browser prompt is never the shipped UI for any of these.
3. `searchByText` is referenced somewhere **other** than the `window.CNTR._pos` export line.
4. The tender method list in `pos.js` contains `credit`.
5. `discountCeilingPct` is referenced outside `templates/pos.php`.
6. `roundingStep` is referenced outside `templates/pos.php`.
7. `searchFields` is referenced outside `templates/pos.php`, **or** is removed from the bootstrap
   in `templates/pos.php`. (Dead config is a defect either way — read it or drop it.)
8. `pos.js` contains at least one `.focus()` call that is not the print iframe's.
9. Every `id="cntr-…"` container that a handler reveals has markup written into it somewhere —
   no handler un-hides a permanently empty div.

**Layer B — the DOM harness.** Chromium and Playwright are already available in this environment;
`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers` is set and `playwright install` must **not** be run.

`tests/pos-harness.html` is a standalone page that boots the real, unmodified `pos.js`:

- An inline `<script>` **before** the `pos.js` tag sets `window.CNTR` to a fixture bootstrap
  (`restUrl`, `nonce`, `registerId: 1`, `registerPrefix: 'T1'`, `defaultLocationId: 1`,
  `discountCeilingPct: 10`, `roundingStep: 1`, …). `pos.js` reads `const CFG = window.CNTR || {}`
  at IIFE entry, so this must be set first or the harness tests nothing.
- The same inline script installs a `window.fetch` stub that answers every route the terminal calls
  at boot — `/shift/current`, `/catalog`, `/customers/lookup`, `/customers/{id}/profile`, `/sale` —
  from an in-page fixture table, and records every request for assertion.
- It seeds IndexedDB with a fixture catalogue: at least one item with a barcode, one with a SKU
  only, three sharing the word "rice" in the name, and one weight-barcode item.
- It stubs `window.print` so no dialog appears.
- `<div id="cntr-pos-root">` exactly as `templates/pos.php` provides it.

`tests/pos-dom.mjs` drives that page with Playwright and asserts **through the DOM only** — never
through `window.CNTR._pos`. That restriction is the entire point; an assertion that reaches into
the export hatch reproduces the v0.1.8 failure.

Ship F0 with these DOM tests green against current v0.1.8 behaviour:

- The page renders a cart list, a search input and a total.
- Typing a known barcode adds a line.
- Scanning (a fast keystroke burst + Enter) adds a line without the code landing in the input.
- Scanning the same code twice yields one line at qty 2, not two lines.

Then add one **expected-to-fail** test per remaining F-task, marked skipped with the task id.
Un-skip each as its task lands. The skip list is the plan's progress bar.

`tests/README.md`: how to run (`node tests/pos-dom.mjs`), the rule that assertions go through the
DOM, and the rule that `tests/` is excluded from the release zip.

**Self-test** `test_pos_wiring()` — 9 checks as listed. Several **will fail on v0.1.8 and that is
correct** — they are the specification for F1–F9. Do not weaken a check to make it pass; the task
that fixes it makes it pass.
**Manual** none.
**Commit** `counter: test: pos source guards and headless DOM harness`
**Done when** `test_pos_wiring()` runs and reports honestly (failures expected), and
`node tests/pos-dom.mjs` runs the four baseline assertions green.

---

### F1 — Cart line model, row selection and focus discipline

**Goal** A cashier can select a line, change its quantity from the row, remove it, and never lose
the search box.
**Depends on** F0
**Files** `assets/pos.js`, `assets/pos.css`

**Direction**

This is the foundation F5 (`F3`/`F4` keys) sits on. Today every `<li>` carries a `data-idx` and
nothing listens for it, so "the selected line" those keys act on cannot be selected.

- Add `cart.selectedIdx` (default: the last line added).
- Render each row with: name, qty, unit price, **line total**, and a discount indicator when
  `line.discount` is non-zero. Line total is `unitPrice × qty − discount`.
- `↑` / `↓` move the selection. Click selects. The selected row gets a visible state — a left
  keyline plus a background shift, not colour alone.
- Per-row `+` / `−` and a remove control.
- **Fix the render/focus bug.** `render()` replaces `root.innerHTML` wholesale and relies on the
  `autofocus` attribute, which does not reliably re-fire on re-inserted nodes; the only `.focus()`
  call in the whole file targets the print iframe. Add an explicit `restoreFocus()` that returns
  focus to the search input, and call it at the end of `render()`, on every modal close, and after
  every print. Preserve the search box's current value and caret position across a re-render.
- **Fix `Esc`.** P1.13 says *"Void the line, then the cart."* Today it is a silent `lines.pop()`.
  Make it: first press voids the **selected** line (gated on `cntr_void_line`); second press on an
  empty selection asks to clear the cart and only then clears.

**Self-test** Extend `test_pos_wiring()` — check 8 (a non-iframe `.focus()`) now passes.
DOM harness, un-skip 6: selection defaults to the newest line; `↓` moves it; clicking row 2
selects row 2; `+` raises that row's qty and the total; remove drops the row; focus is on the search
input after adding a line, after closing the tender modal, and after a print.
**Manual** Add five lines, arrow through them, adjust the middle one, confirm the total tracks.
**Commit** `counter: pos: selectable cart rows, line totals and focus discipline`
**Done when** The six DOM assertions pass and typing works immediately after every action.

---

### F2 — Customer profile endpoint

**Goal** The till can see what a customer owes and what they usually buy.
**Depends on** F0
**Files** `includes/Rest/Customer.php`

**Direction**

The **only** backend addition in this plan. It exists because `cntr_customer_index` holds
`phone_norm`, `customer_id`, `display_name`, `last_order_at` and nothing else — the till literally
cannot display a balance today.

`GET /customers/(?P<id>\d+)/profile`, permission `Router::guard_any([ 'cntr_use_pos',
'cntr_terminal_access' ])`.

Returns:

```
customer_id, display_name, phone
balance            CustomerLedger::balance()
credit_limit       CustomerLedger::credit_limit()
available          credit_limit − balance, floored at 0; null if no limit is set
oldest_due_days    from CustomerLedger::aging(), 0 when nothing is open
can_credit         current_user_can('cntr_credit_sale') && customer_id > 0
usual_items[]      up to 12: product_id, variation_id, name, sku, price, times_bought
```

`usual_items` comes from this customer's completed orders. **Cap the window** — last 180 days, and
`LIMIT` the underlying query. This endpoint is called when a customer is attached at the counter,
so it must not become a slow scan on a shop with two years of history. If the query cannot be made
fast against HPOS order tables with the indexes that exist, return an empty `usual_items` and say
so in `docs/decisions.md` rather than shipping a slow counter interaction — the balance is the
part that matters; the usual-items strip is the part that is nice.

Money fields are strings at 4dp, matching every other money value in this codebase. Do not return
floats.

**Self-test** `test_customer_profile()`, registered in `run()` — 6 checks: an unknown customer id
returns 404, not an empty 200; a customer with no ledger rows returns `balance` `"0.0000"`;
`available` equals limit minus balance; `available` is `null` when no limit is set; the route is
refused without `cntr_use_pos`; `can_credit` is false for a user lacking `cntr_credit_sale`.
Use the suite's existing fixture-customer helpers and clean up per §11.2 rule 6.
**Manual** none.
**Commit** `counter: rest: customer profile with balance, limit and usual items`
**Done when** `test_customer_profile()` is 6/6 green.

---

### F3 — Attach a customer properly

**Goal** `F6` identifies a real person instead of storing a typed string.
**Depends on** F1, F2
**Files** `assets/pos.js`, `assets/pos.css`

**Direction**

Today `attachCustomerPrompt()` is a browser `prompt()` that stores a raw phone into
`cart.customer.phone` and never calls the lookup endpoint. `Rest\Sale::resolve_customer()` then
re-normalises server-side and silently takes `matches[0]` — precisely the household guess the
backend author wrote a picker comment to prevent:

> *"A household shares a number; the till shows a picker rather than guessing which one is right."*

Build the picker that comment describes.

- `F6` opens a real in-page modal with a phone field, not `prompt()`.
- On submit, `GET /customers/lookup?phone=…`.
  - **0 results** → offer "Bill as walk-in" or a minimal new-customer form (name + phone).
  - **1 result** → attach it, and still show what was attached.
  - **2+ results** → render every row: name, last order date. **Never auto-pick.**
- On attach, call `GET /customers/{id}/profile` and hold the response in `cart.customer`.
- Send `customer.customer_id` in the sale payload, not just the phone, so the server stops guessing.
  `resolve_customer()` already accepts a phone; extend the client payload to carry the resolved id
  and have `resolve_customer()` prefer it when present. *(This is the one small `Rest/Sale.php`
  change the plan permits — it removes a guess, it does not change sale logic.)*
- A **persistent customer strip** in the header — not a modal, not a toast. Name, phone, balance,
  **available credit**, oldest-due age. Who this is and how much credit is left must never be more
  than one glance away.
- Clearing the customer is one visible action, and the cart survives it.
- `holdSale()` / `resumeSale()` already carry `cart.customer` — keep the profile with the ticket.

**Self-test** `test_pos_wiring()` checks 2 and 3 now pass (no `prompt(`).
DOM harness, un-skip 5: two candidates render two rows and nothing is attached until one is
clicked; a single candidate attaches and the strip shows the name; zero candidates offers walk-in;
the strip shows `available`; the sale payload carries `customer_id`.
**Manual** Attach a real regular by phone. Confirm the name and balance are the right person's.
**Commit** `counter: pos: customer lookup, household picker and identity strip`
**Done when** The five DOM assertions pass and no `prompt()` remains in `pos.js`.

---

### F4 — Split tenders and the credit row

**Goal** A standing customer can be billed: part cash, part bKash, remainder on account.
**Depends on** F3, and the §2 decision answered
**Files** `assets/pos.js`, `assets/pos.css`

**Direction**

**This is the task the whole plan is for.** After it, a shop representative can do the thing they
currently cannot do at all.

`submitSale(tenders)` already takes an array. `Tenders::VALID_METHODS` already contains `'credit'`.
`CustomerLedger::check_limit()` already refuses an over-limit sale before the order exists. Only
the form is single-row, and its dropdown omits credit. P1.14's own words:

> *"WooCommerce records one payment method per order; a Bangladeshi counter routinely takes half
> cash and half bKash."*

Rebuild the `F2` tender modal:

- **Multiple rows.** Each row is method + amount + remove. "Add another method" appends one.
- **Live arithmetic**, always visible: Due, Taken, **Remaining**, Change.
- **Submit is disabled until Remaining is 0.** Cash over the due becomes a `change` row
  automatically, as it already does.
- **The credit row is hidden entirely** unless a customer is attached **and** `can_credit` is true
  from the profile. A credit sale to a walk-in is meaningless — the backend refuses it, so the UI
  must never offer it.
- **Warn before the server refuses.** Compare the credit amount against `available` from the
  profile; if it exceeds, mark the row and block submit with the reason inline.
  `CustomerLedger::check_limit()` remains the authority — this is courtesy, not enforcement, and
  the server's refusal must still be surfaced legibly if it happens.
- **Show the consequence**: "On account after this sale: ৳X of ৳Y limit."
- **Apply `roundingStep`** to the cash row. It is already in `window.CNTR` and currently ignored.
- **Print the new balance on the receipt.** A credit customer should leave holding what they now
  owe. `Docs\Templates` supports the line; add it to the receipt builder.
- Keyboard-complete: rows reachable and submittable without the mouse.

**Offline note.** A credit sale queued offline cannot have its limit checked at the till. The
outbox path already exists; the limit check happens server-side on drain, so an over-limit offline
credit sale will fail on replay and land in `OutboxFailures`. Do **not** try to enforce the limit
locally as if it were authoritative. Do surface it: if the terminal is offline and a credit row is
used, say plainly that the limit will be checked when the sale syncs.

**Self-test** `test_pos_wiring()` check 4 (credit in the method list) and check 6 (`roundingStep`
read) now pass.
DOM harness, un-skip 8: two rows summing to the due enable submit; a short sum leaves submit
disabled with Remaining shown; cash over the due produces a `change` row in the payload; the credit
row is absent with no customer attached; the credit row is absent when `can_credit` is false; a
credit amount over `available` blocks submit; the submitted payload is an array of the right rows;
cash is rounded to `roundingStep`.
**Manual — the real acceptance test.** Bill a real standing customer: some cash, some bKash, the
rest on account. Confirm the receipt prints the new balance, the Receivables screen shows the debit,
and the aging report moves. Then take a part payment against it and confirm the balance drops.
**Commit** `counter: pos: split tender rows with credit, rounding and limit feedback`
**Done when** The eight DOM assertions pass **and** the manual credit sale reconciles against the
Receivables screen.

---

### F5 — The three empty keys: F3, F4, F10

**Goal** Every key on the printed card does what the card says.
**Depends on** F1
**Files** `assets/pos.js`, `assets/pos.css`

**Direction**

All three are currently empty function bodies with only a comment inside.

- **`F3` — `changeSelectedQty()`.** A numeric entry against the selected line. Accept decimals
  (loose goods are weighed). Zero removes the line, with a confirm. Re-render and re-total.
- **`F4` — `lineDiscountPrompt()`.** Gate on `cntr_discount_line`; a price override gates on
  `cntr_price_override`. Offer both an amount and a percentage. **Enforce
  `discountCeilingPct`** — currently sent to the client and read by nothing. Write `line.discount`
  (the field exists in the cart model and nothing has ever set it). The audit trail is already
  handled server-side on submission; do not add a second one client-side.
- **`F10` — `noSale()`.** Gate on `cntr_no_sale`. Ask for a reason, then print the small slip that
  kicks the drawer. Per P1.14 the drawer only opens by printing, so the slip **is** the mechanism —
  and the plan treats the resulting paper trail as a feature, not a workaround. The reason goes in
  the sale payload path that already writes the audit row.

Where a capability is missing, the key does nothing visible and says why — never a silent no-op.

**Self-test** `test_pos_wiring()` check 1 now passes for all three handlers.
DOM harness, un-skip 7: `F3` changes the selected line's qty and the total; qty 0 asks first;
`F4` applies an amount discount; `F4` refuses a discount above the ceiling; `F4` does nothing
without the capability and shows a reason; `F10` requires a reason; `F10` is refused without
`cntr_no_sale`.
**Manual** Every key on the card, in order, on the real machine. Confirm the drawer opens on `F10`
and the slip prints.
**Commit** `counter: pos: line quantity, capped line discount and audited no-sale`
**Done when** No handler in the keyboard map has an empty body, and the manual key pass is clean.

---

### F6 — Product search by name

**Goal** A cashier who does not know the barcode can still find the item.
**Depends on** F1
**Files** `assets/pos.js`, `assets/pos.css`

**Direction**

`searchByText()` already exists, filters name and SKU, caps at 20 results, and is correct. It is
wired **only** into `window.CNTR._pos` for the test suite. The search box calls `lookupProduct()` —
an exact barcode/SKU match. Type "coca" and nothing happens, ever. This is wiring, not new
capability, and it is the cheapest large win in the plan.

- **Never slow the scanner path.** An exact barcode or SKU hit still adds instantly and
  synchronously, exactly as today. Only a non-matching string falls through to text search.
- Debounce the text path ~120 ms.
- Render a keyboard-navigable result list: name, SKU, price, and stock at this location.
- `↑`/`↓` move, `Enter` adds, `Esc` closes. Clicking a result adds it.
- Respect `searchFields` from `window.CNTR` — currently dead config. If honouring it is not
  practical, **remove it from the bootstrap** in `templates/pos.php` rather than leaving it
  plumbed and ignored. Check 7 accepts either, and dead config is a defect either way.
- Everything runs against the in-memory index. **Never** call the REST catalogue endpoint on the
  hot path of typing — P1.13 is explicit and the budget is 5 ms.

**Self-test** `test_pos_wiring()` checks 3 and 7 now pass.
DOM harness, un-skip 6: a known barcode still adds instantly with no list shown; "rice" shows
three results; `↓`+`Enter` adds the second; a no-match string shows an empty state, not a silent
nothing; the weight-barcode item still parses through the scanner path; no `/catalog` request is
made while typing.
**Manual** Search twenty items by name off the shelf. Every one resolves.
**Commit** `counter: pos: name search with keyboard-navigable results`
**Done when** The six DOM assertions pass and the scanner path is measurably unchanged.

---

### F7 — The quick-add form

**Goal** New stock can be sold the moment it arrives.
**Depends on** F1
**Files** `assets/pos.js`, `assets/pos.css`, `includes/Admin/Health.php`

**Direction**

`openQuickAdd()` un-hides `<div id="cntr-quick-add" class="cntr-modal" hidden></div>` — a div that
is **never filled with anything**. `POST /quick-add` and `submitQuickAdd()` are both finished.
P1.13 calls this *"the single most-used feature of the system this plan replaces."*

- Fill the modal: name, price, barcode, unit, opening quantity. Gate on `cntr_manage_stock`.
- On success, add the new product straight into the cart — that is the whole point.
- Surface the endpoint's 422 legibly (a name and a positive price are required).

**Also close the orphaned flag.** `Terminal::quick_add()` writes `_cntr_quick_added` and
**nothing anywhere reads it**. P1.13 requires: *"Products created this way are flagged and listed on
a Health-page screen so somebody completes their category, tax class and cost later."* Add that
list to `Health.php` — product, created date, and which of category / tax class / cost is still
missing.

**Self-test** `test_pos_wiring()` check 9 (no permanently-empty revealed container) now passes.
`test_quick_add_listing()`, registered in `run()` — 3 checks: a product with `_cntr_quick_added`
appears in the Health list; one without it does not; a quick-added product whose category, tax
class and cost are all set is reported complete.
DOM harness, un-skip 4: `F5` renders a form with five fields; submitting posts to `/quick-add`;
the created product lands in the cart; a blank name shows the error and does not post.
**Manual** Quick-add a real item at the counter and sell it in the same sale.
**Commit** `counter: pos: quick-add product form and Health incomplete-product list`
**Done when** The DOM assertions pass and the Health list shows real quick-added products.

---

### F8 — Held sales

**Goal** Three parked tickets can be resumed in any order and survive a refresh.
**Depends on** F1, F3
**Files** `assets/pos.js`, `assets/pos.css`

**Direction**

`resumeSale()` is a LIFO `pop()` against an in-memory array. With three customers parked you can
only reach the last one, and a page refresh vaporises all three.

- `F8` opens a **list**: customer name, item count, total, age. Choose any ticket.
- **Persist to IndexedDB**, alongside the catalogue and outbox stores. A refresh or a crash must
  not lose parked tickets.
- Keep the attached customer profile with the ticket.
- P1.13's unmet line: *"Say so in the UI if a held item later goes out of stock."* Check stock on
  resume against the in-memory index and flag the affected row.
- Held sales still **never reserve stock**. That is an invariant, not a preference.

**Self-test** DOM harness, un-skip 5: holding twice then opening `F8` lists both; choosing the
first resumes the first, not the last; a reload preserves held tickets; a resumed ticket keeps its
customer; an out-of-stock line is flagged on resume.
**Manual** Park three tickets, serve someone else, resume the middle one.
**Commit** `counter: pos: held-sale picker with IndexedDB persistence`
**Done when** All five DOM assertions pass, including across a reload.

---

### F9 — Loose ends

**Goal** No dead config, no silent no-ops, no lies in the code comments.
**Depends on** F1–F8
**Files** `assets/pos.js`, `templates/pos.php`, `includes/Pos/Terminal.php`

**Direction**

- Confirm every `window.CNTR` key is now either read by `pos.js` or removed from the bootstrap.
- Every capability-gated action that is unavailable says why. No silent no-ops anywhere.
- Fix the comments that no longer describe the code. `Terminal::quick_add()` says a Health screen
  is *"not built in this task"* — F7 built it. `templates/pos.php` says *"No register-picker UI in
  this task"* — still true, leave it, but confirm it reads as a deliberate choice rather than an
  oversight.
- Run the full `Selftest` suite from the Health screen and read the **labelled list**, not the
  total. Per `HANDOVER.md`, a lower count than 445 is legitimate; a *failure* is not.

**Self-test** `test_pos_wiring()` — all 9 green. Full suite: no new failures against the v0.1.8
baseline. Every previously-skipped DOM test un-skipped and passing.
**Manual** none.
**Commit** `counter: pos: remove dead config and align stale comments`
**Done when** `test_pos_wiring()` is 9/9 and the DOM suite has no skips left.

---

## PHASE F acceptance

Before starting Phase U, all of this must hold:

- [ ] `test_pos_wiring()` 9/9.
- [ ] `test_customer_profile()` 6/6, `test_quick_add_listing()` 3/3.
- [ ] Full self-test suite: no failures new since v0.1.8.
- [ ] `node tests/pos-dom.mjs` — no skipped tests remaining.
- [ ] No `prompt(` and no `console.log` in `pos.js`.
- [ ] **The manual key pass in §4 completed on the shop's real hardware.**
- [ ] **A real standing customer billed on split tender with a credit remainder, reconciled against
      the Receivables screen.**

The last two are not optional and cannot be done from a terminal. Phase F is not finished without
them.

---

# PHASE U — Interface

Only start once Phase F acceptance is signed off. Everything here assumes the behaviour already
works.

---

### U1 — Till layout

**Goal** The two things that matter — who this is, what is owed — read at a glance.
**Files** `assets/pos.css`, `assets/pos.js` (markup structure only, no behaviour change)

**Direction** The existing dark palette in `pos.css` is decent and worth keeping — it is not
"unstyled". What is missing is structure. Target shape:

```
┌─ Counter ─────────────────────── ONLINE ─ Shift R1-000042 ─┐
│  ┌ CUSTOMER ──────────────┐  ┌ CART ──────────────────┐    │
│  │ Karim Uddin            │  │ Chinigura rice 5kg     │    │
│  │ 01711-223344           │  │   2 × 620.00 = 1240.00 │    │
│  │ Owes      ৳ 4,200.00   │  │ Soyabin oil 1L         │    │
│  │ Limit    ৳ 10,000.00   │  │   1 × 185.00 =  185.00 │    │
│  │ Available ৳ 5,800.00   │  │                        │    │
│  │ Oldest due 34 days     │  │ [↑↓ select · +/− qty]  │    │
│  └────────────────────────┘  └────────────────────────┘    │
│  Usual: [Rice 5kg] [Oil 1L] [Atta 2kg] [Sugar 1kg]         │
│  Scan or type name / SKU / barcode ──────────────  1425.00 │
└────────────────────────────────────────────────────────────┘
  F2 Pay  F3 Qty  F4 Discount  F5 New item  F6 Customer  F7 Hold
```

- Touch targets sized for a counter, not a desk. Assume a shared screen and a fast hand.
- Numbers get `font-variant-numeric: tabular-nums` everywhere they stack.
- **State is never colour alone** — `pos.css` already gets this right for the online/offline badge
  ("the text itself is the signal; colour only reinforces it"). Hold that standard for the new
  credit and stock states.
- A **visible function-key bar** along the bottom. The plan's answer to discoverability was "print
  it on a card and tape it to the counter"; a row of labels costs nothing and trains a new cashier
  without the card.
- The "usual items" strip from F2's `usual_items` — tap to add. This is the "as per their need"
  half of the priority.

**Self-test** DOM assertions from Phase F must all still pass unchanged. If a layout change breaks
one, the layout is wrong, not the test.
**Manual** A cashier who has not seen the till before completes a cash sale without being told how.
**Commit** `counter: pos: counter-scale layout with customer panel and key bar`

---

### U2 — Bengali and currency

**Goal** The till reads correctly for the people using it.
**Files** `assets/pos.css`, `assets/pos.js`, `languages/counter-bn_BD.po`

**Direction** `counter-bn_BD.po` already exists and is substantial. Every new string added in
Phase F needs to reach it. Set a font stack that renders Bengali correctly at counter distance, and
format money as `৳ 1,425.00` consistently — the Reports screen's raw 4-decimal output is the
counter-example to avoid.
**Commit** `counter: pos: bengali strings and currency formatting`

---

### U3 — Group the admin sidebar

**Goal** Seventeen screens stop being one flat list.
**Files** `includes/Admin/Menu.php`

**Direction** Confirmed by count: one `add_menu_page` plus sixteen `add_submenu_page` calls, in
registration order, with daily-use tools interleaved with rare compliance exports.

Five groups: **Operations** (Counter, Adjust Stock, Fulfilment) · **Purchasing** (once it exists) ·
**People** (Attendance, Leave, Payroll) · **Money** (Receivables, Payment Accounts, Expenses & P&L,
VAT Exports) · **Admin** (Health, Performance, Offline Failures, Oversell Log, Label Designer).

Screens self-register on the `cntr_admin_menu` action — that hook is deliberate and must survive.
Add ordering without requiring each screen to know about the others.
**Self-test** `test_admin_menu()` — 3 checks: every registered screen still appears; each has a
group; no screen is orphaned by the reorganisation.
**Commit** `counter: admin: group the sidebar into five sections`

---

### U4 — The shared entity picker

**Goal** One name-search component, reused everywhere an ID is currently typed.
**Files** `includes/Admin/` (new shared component), `Screens/Adjust.php`, `Screens/Reports.php`

**Direction** Build this **before** the missing admin screens, not after. Every screen in U5 needs
to resolve a product, supplier or employee by name; building the picker first means those screens
consume it instead of shipping raw ID fields and being retrofitted.

Then retrofit the two worst offenders: Adjust Stock (bare numeric Product ID and Variation ID) and
Reports (bare numeric Location id).
**Commit** `counter: admin: shared entity picker, retrofitted into adjust and reports`

---

### U5 — The missing screens

**Goal** The back office is operable by clicking.
**Direction** Nine areas have no screen at all. This is **new scope beyond
`COUNTERIMPLEMENTATION.md`** and should be planned as its own phase, not squeezed onto this one.
Order, by how badly the shop needs each:

1. Locations & registers — the precondition for everything else.
2. Employees — no cashier can be onboarded without editing the database today.
3. Suppliers, purchase orders & receiving — the largest piece; the backend is built and tested.
4. Transfers, batches & stocktake.
5. Settings — a form over existing `Settings::get()/set()` keys. Ranks higher than its daily-use
   frequency suggests, because `pos.weight_barcode_prefix`, `pos.discount_ceiling_pct` and
   `cash.rounding_step` govern till behaviour, and after Phase F they are finally live.

**Do not start U5 inside this plan.** Finish F0–F9 and U1–U4, then scope U5 properly.

---

## 4. The manual pass — cannot be skipped, cannot be automated

`COUNTERIMPLEMENTATION.md` P1.13 already specified this list and it was never run. That is how
v0.1.8 shipped. Run it on the shop's own hardware after Phase F.

**Scanner**
- [ ] Scan twenty random items off the shelf. Every one resolves to the right product, price, unit.
- [ ] Scan into a focused text field — the barcode does **not** land in it.
- [ ] Type a SKU by hand at human speed — it is **not** treated as a scan.
- [ ] A weight-embedded barcode parses to the right SKU and price.

**Offline**
- [ ] Unplug the network. Search still works. A sale completes and queues.
- [ ] Reconnect. The queued sale drains and the receipt number is unchanged.

**Keyboard** — every key in the map, in order, on the real machine.

**Paper and drawer**
- [ ] Receipt prints with **no dialog** (`--kiosk-printing`), correct ৳, correct Bengali, correct width.
- [ ] Drawer opens on a cash sale and **not** on a credit sale.
- [ ] `F10` prints the slip and kicks the drawer.
- [ ] A reprint is identical to the first.

**The standing customer — the acceptance test for this whole plan**
- [ ] Attach a real regular by phone. The right person, the right balance.
- [ ] A household number shows a picker, not a guess.
- [ ] Bill them: part cash, part bKash, remainder on account.
- [ ] The receipt shows the new balance.
- [ ] Receivables shows the debit; aging moves.
- [ ] Take a part payment. The balance drops correctly.
- [ ] An over-limit credit sale is refused with a message a cashier can act on.

---

## 5. Scope

**F0–F9 are not new scope.** They finish P1.13 and P1.14 as those tasks were written — the keyboard
map, split tenders, the quick-add form, the Health listing — plus one new endpoint (F2), which
exists only so the till can display a balance it currently cannot see.

**U1–U4 are a reasonable finishing pass** on work that already exists.

**U5 is genuinely new scope** beyond `COUNTERIMPLEMENTATION.md`.

Nothing here asks for a backend rewrite. The PHP is why this list is short: the ledger discipline,
the credit limits, the tender validation and the FIFO costing are all done and tested. What is
missing is the surface a person puts their hands on.
