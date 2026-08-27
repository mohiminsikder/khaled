# Counter v0.1.29 — functional audit

Independent read of the shipped code after Phases A–D, plus a live run of the DOM harness. Findings
are ranked by what breaks for a real shop, not by how interesting they are.

---

## What is genuinely healthy

Verified directly, not assumed:

| Check | Result |
|---|---|
| DOM harness against the real `pos.js` | **66/66 PASS** — cart, price groups, split tender, X-report, register close, drafts, quotations, suspend, expense, return, quick-add, search config, rebound keys |
| REST routes with a `permission_callback` | **every one** — no ungated route in `includes/Rest/` |
| SQL injection surface | **clean** — every interpolated query uses `%s`/`%d` through `prepare()`; the rest concatenate `Install::table()`, which is internal |
| Money arithmetic | **bcmath throughout** the PHP money path |
| Stubs / TODOs / empty handlers | **none** — the v0.1.8 failure mode has not recurred |
| A1 regression (`suppressNextEnter`) | **fixed and still fixed** |
| B6 invariant — drafts write no stock | **sound by construction**: `cntr-draft`/`cntr-quotation` are deliberately absent from `Channel`'s stock-applying status list, and the rollup derives from stock moves, so a draft cannot reach `sales_daily` or P&L |

**On the float in the harness output** (`roundOff=-0.2999999999999545`): not a bug. `footerTotals()`
is documented as a display-only preview; `Orders\Money::apply_rounding()` is the server authority and
`wc_format_decimal(…, 4)` normalises anything the client sends. The drift never reaches the ledger.

---

## R1 · The till cannot take a cash sale — P0, live now

`Tenders::account_for_method('cash')` resolves `CASH` **only where `status = 'active'`**. On
peapip.com both `CASH` and `BKASH` are `inactive`, so `Tenders::record()` returns a 422 and the sale
is refused. Card, Rocket and Bank work; cash and bKash — most of a grocery's takings — do not.

This predates the build. The run correctly refused to fix it: writing to a live shop's payment
configuration unattended is exactly the action that should need a person.

**Fix** — one statement, or the Accounts screen:

```sql
UPDATE wp_cntr_payment_accounts SET status='active' WHERE code IN ('CASH','BKASH');
```

Then ring a ৳10 cash sale to confirm. Worth asking whoever last touched that screen whether these
were switched off deliberately.

---

## R2 · 85 unguarded crash sites in the self-test — P0, and the root cause of "no baseline"

This is the finding the run's own reports gesture at without measuring.

`Selftest.php` has **99 test methods, 582 checks, all wired into `run()`** — and `run()` has **never
once completed**. Three separate crashes were hit this session. Each was diagnosed individually and
treated as its own incident. They are not separate incidents.

Counted mechanically: **85 call sites** in `Selftest.php` assign the result of a method that can
return `WP_Error` — `Sale::process()`, `Registers::create()`, `Locations::create()`,
`Accounts::create()`, `Employees::create()`, `Suppliers::create()` — and then use that value within
the next fourteen lines **without an `is_wp_error()` guard**.

Every one is a latent fatal. When it fires, PHP dies mid-`run()`, which means:

1. One failed check becomes **total loss of every result**, including from methods that never ran.
2. `cleanup()` at the end of `run()` never executes, so **every fixture that run created is
   orphaned** — which is where R3 comes from.

The three crashes seen (`get_total()` on `false`, `WP_Error` into `ShiftReport::build(int)`,
`WP_Error` used as array) are three of eighty-five. Patching them one at a time, as each fires, is an
unbounded loop — and that is precisely what the last session spent its time on.

**Fix** — one systematic pass, not eighty-five individual patches:

- Add a private helper, e.g. `fixture_or_skip( $result, string $label )`: if `is_wp_error( $result )`,
  record **one failed check** naming the label and the error message, and return `null`.
- Route all 85 sites through it. Where a method cannot continue without the fixture, `return` early —
  the class already does this in several places (`test_wc_stock_authority()`,
  `test_customer_ledger()`), so the pattern is established and `HANDOVER.md` already documents why a
  lower check count on a given run is legitimate.
- Then run `wp counter selftest` **once**, end to end, and record the first complete labelled
  pass/fail list this project has ever had.

Do R1 first — with CASH inactive, many of these still fail, just without killing the process.

---

## R3 · Production is full of test fixtures — P1, and a shop owner can see it

A read-only audit of peapip.com found fixture naming/meta on **163 WooCommerce products, 65
registers, 26 locations, and 33 of the site's 35 WordPress users**.

Most predates this session, but every crashed run adds more — see R2 for why.

It stopped being a hygiene problem when A5 shipped: the new Dashboard "The till" panel lists every
`active` register, which is the correct behaviour the task asked for, and today that means Register 1
followed by dozens of `Counter Load Test Register N`. **Do not fix this by filtering the panel** —
guessing "real vs fixture" from a name pattern is a fragile heuristic papering over a data problem.

**Fix** — a reviewed sweep, run by a person:

1. `SELECT` the candidates first and read them. Never delete unread.
2. Delete fixture WooCommerce products via `wp post delete --force`, not raw SQL — meta, terms and
   lookup tables need to go too.
3. Deactivate rather than delete registers and locations that any `stock_moves` row references;
   deleting them orphans ledger history, and the ledger is Invariant I.
4. Take a database backup first. Hostinger's hPanel does this in a click.

---

## R4 · Testing runs against the live shop database — P1, systemic

R2 and R3 are both downstream of this. Every self-test run creates real WooCommerce products, real
users and real registers on the shop's production database, and a crash leaves them there.

**Fix** — a staging clone, and point `.env.deploy` at it. Hostinger supports this in hPanel. Then
production runs only when a human asks for it, and R3 cannot recur.

This is the decision worth making even if nothing else on this list gets done.

---

## R5 · The DOM harness only runs from one directory — P2

`node tests/pos-dom.mjs` from the repo root fails with `ERR_FILE_NOT_FOUND`; it works only from
inside `tests/`, because the harness path is resolved against `process.cwd()`.

Minor, but it is the difference between a future session running the harness and concluding it is
broken. **Fix**: resolve the harness path from `import.meta.url` instead of `cwd`, and note the
invocation in `tests/README.md`.

---

## Order

```
R1  flip CASH/BKASH          minutes    unblocks selling AND most of R2
R2  guard the 85 call sites  one pass   first complete baseline in the project's history
R3  sweep the fixtures       reviewed   with a backup, by a person
R4  move to staging          decision   stops R3 recurring
R5  fix the harness path     minutes
```

R1 and R5 are quick. R2 is the one that changes what this project knows about itself.
