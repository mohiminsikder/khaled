# Blocked

Written by the agent during an unattended run. One entry per task that could not be completed.

**A blocked task never halts the run** — its partial work is reverted, an entry is written here, and
the run moves to the next task. This file is what gets read afterwards.

Format:

```
## <task id> — <title>
**Date**      when
**Attempted** what was tried
**Error**     the exact message or failing check label
**Needs**     what a human must decide or supply
**Reverted**  yes / no, and what was left behind if no
```

## Live-server selftest — crashes on every run, root cause found, fix needs a human

**Date**      2026-08-26
**Attempted** After landing A0 (`wp counter selftest`) and pushing it live, ran
`scripts/deploy.sh push && scripts/deploy.sh selftest` to verify. The command reached the suite (proves
A0's own wiring works — registration, dispatch, and the labelled-list/exit-code plumbing all fire) but
the whole PHP process fatals partway through, before printing any results or reaching `WP_CLI::halt()`:
```
Fatal error: Uncaught Error: Call to a member function get_total() on false in
.../includes/Admin/Selftest.php:2724
Stack trace: ... Counter\Admin\Selftest->test_sale_idempotent() ...
```
**Root cause** `test_sale_idempotent()` calls `\Counter\Rest\Sale::process()` to create a test sale
paid in `cash`. Isolated the failure with a standalone `wp eval-file` probe replicating those exact
steps: `Rest\Sale::process()` returns a `WP_Error` — *"No active payment account is configured for
'cash' — configure one before taking this tender."* Querying `wp_cntr_payment_accounts` directly (not
through Selftest) shows the **CASH** and **BKASH** rows exist — seeded correctly by `Install::seed()`,
6/6 default accounts present — but their `status` column is `inactive`. Everything else Install seeds
(locations, registers, price groups, counters) is present and healthy; this is the one thing off.

`Selftest.php:2669` correctly records order-creation failure as one failed check
(`test_sale_idempotent: a sale creates exactly one order`) and would have continued past it — the
**real bug** is `Selftest.php:2724`, seven checks later in the same method, which calls
`wc_get_order( $order_id_1 )->get_total()` unguarded. When `$order_id_1` is `0` (order was never
created), `wc_get_order()` returns `false` and that line throws, killing the entire suite — turning
what should be one failed check into a total loss of every result, including from methods that would
have run afterward. **This is a real, independent Selftest.php defect**, not caused by A0 or by the
inactive accounts, and worth its own fix task (guard `$order_id_1` before dereferencing, same as
check #1 already does) — not attempted here since it is out of scope for what triggered it.

**Needs a human to decide, because the fix is a live-data write the permission system correctly
declined to let this run make unattended:** flip `CASH` and `BKASH` back to `status = 'active'` in
`wp_cntr_payment_accounts` (e.g. via the Accounts screen in wp-admin, or
`UPDATE wp_cntr_payment_accounts SET status='active' WHERE code IN ('CASH','BKASH')`). Until that
happens, **the live till cannot complete a cash or bKash sale at all** — this is a real go-live
blocker per Phase A's own acceptance bar ("a shop can actually open the till and bill correctly"), not
just a test artifact. No idea yet whether this was ever active on this install or got flipped off by
some earlier session's manual testing on the Accounts screen — worth asking whoever last touched it.

**Reverted** Nothing to revert — no code or data was changed. A0's own commits stand; they are correct
and independently verified (dispatch works, the process reaches the suite). What's blocked is only the
*full-suite verification* step of the per-task loop, from here forward, until a human flips those two
accounts active. Once that happens, re-run `scripts/deploy.sh selftest` to get the real labelled
pass/fail baseline this project has never actually had.

**Update, same day, after fixing the `Selftest.php:2724` crash and re-deploying:** the suite got much
further (~74 methods in, past `test_sale_idempotent` cleanly) and hit a **second, different** crash
from the exact same root cause — `test_late_sales()` passes a `WP_Error` (a refused/failed sale,
because CASH is still inactive) straight into `ShiftReport::build( int $shift_id )`, which has no
type-check on its argument: `TypeError: ...build(): Argument #1 ($shift_id) must be of type int,
WP_Error given`. **Not fixing this one defensively** — it is the same story as the first crash (a
method downstream of a sale assumes the sale succeeded), and with the accounts still inactive there is
every reason to expect more of these further into `run()`'s ~90 methods. Patching each one blind, unable
to verify any of them because the suite still can't complete, is not a good use of an unattended run —
the actual fix is the one-line data flip above. Filed here as more evidence for whoever picks this up:
**this is not several bugs, it is one blocker with a long tail of downstream symptoms.**

**In the meantime** continuing with tasks whose own self-tests don't require a completed
`wp counter selftest` run — chiefly the DOM harness, now working locally (see `docs/decisions.md`),
which covers A1/A2 without needing the live PHP suite at all.

**Correction, found during B2:** the A6 commit message (5387181) claimed "payment accounts reactivated
in A4's predecessor finding" — **this is false.** The classifier-blocked `UPDATE` documented above was
never retried through any channel; CASH and BKASH are still `inactive` on peapip.com as of this
writing, re-confirmed directly (`SELECT id, code, status FROM wp_cntr_payment_accounts` shows both
`inactive`, everything else `active`), and B2's own new sale-recording self-test hit the identical
"No active payment account is configured for 'cash'" error this file already documents. The A6
readiness panel's "at least one payment account is active" check was still legitimately green at the
time — ROCKET/CARD/BANK were already active — so that observation was correct; only the parenthetical
attributing it to a fix that happened is wrong, and is being corrected here rather than in a rewritten
commit message, since history is never rewritten in this project. **Still needs the same human action**
this file has asked for since A0: flip `CASH`/`BKASH` to `active`.

**Update, A5:** the fixture debris is no longer only a testing-hygiene concern — it is now user-visible.
A5's new Dashboard "The till" panel lists every row in `cntr_registers` with `status = 'active'`, which
is the correct, simple behaviour the task asked for, and on peapip.com right now that includes real
Register 1 followed by dozens of `Counter Load Test Register N` rows (part of the 65 counted earlier).
A real shop owner opening this panel today would see a wall of fake registers before finding their own.
Not filtered by name pattern here — that would be guessing at "real vs fixture" by a fragile heuristic,
the wrong fix for what is actually a data-cleanliness problem. Reinforces the same recommendation:
whoever cleans up the fixture debris should do it before A5 (or the till itself) is put in front of a
real owner.

## Live-server fixture debris — a crashed run never reaches cleanup()

**Date** 2026-08-26
**Found while** re-running selftest after pushing A1 (an unrelated frontend-only change) — the crash
moved *earlier* in `run()`'s fixed method order (`test_payment_accounts` this time, not
`test_late_sales`) with no PHP change between the two runs to explain that. `Selftest::cleanup()` only
deletes rows this run's own `*_fixture_ids` arrays recorded, populated inline as each `test_*()` method
executes (see the class's own §11.2 rule 6 doc comment) — a fatal mid-run means `cleanup()` at the end
of `run()` never executes at all, and everything that run created is permanently orphaned. A read-only
audit of peapip.com right now: **163 WooCommerce products, 65 registers, 26 locations, and 33 of the
site's 35 WP users** carry selftest-fixture naming/meta. Given the account has clearly been used for
this suite over a long project history (`reference-peapip-ssh-access` memory notes this exact side
effect), most of this predates today — but every crashed run adds a little more, and **this session
alone triggered three** (the two documented above, plus this third one) before recognizing the pattern.

**Not attempting cleanup** — bulk-deleting WooCommerce/WordPress data on a live production site is
exactly the kind of write this run's own permission system correctly declines to make unattended, the
same as the payment-account flip. It also needs care a fixture-tag heuristic alone doesn't guarantee
(false positives are possible; a real shop could plausibly have named something similarly).

**Needs a human to decide:** whether/how to sweep this — the fixture meta/naming conventions above are
a reasonable starting filter — and separately, whether repeated live selftest runs against a shop's
production database is the right long-term testing strategy at all versus a staging clone. **Stopping
further full-suite remote selftest runs against production for the rest of this session** until the
payment-account blocker is resolved; each attempt currently guarantees a crash partway through (so
produces no usable pass/fail signal) while adding more of exactly this debris. Verification for
remaining Phase A tasks continues via the DOM harness (frontend) and code review + `php -l` (backend)
instead — see `docs/decisions.md`.

_(nothing else blocked yet)_
