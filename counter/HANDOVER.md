# Counter — handover

This file exists for P8.6 of `COUNTERIMPLEMENTATION.md`, the last task in the plan.
It records the state of the self-test suite at the point the plan was completed.

## Self-test suite size

As of this commit (plugin version 0.1.8):

- **71** `private function test_*()` methods in `includes/Admin/Selftest.php`.
- **445** `$this->check(...)` call sites in the source.

**This 445 figure is not a fixed number, and must never be treated as one** — not in
CI, not in a health-check assertion, not as a "the suite should report exactly N"
regression gate. A live run's actual pass+fail total can be lower than 445, for real,
legitimate reasons rooted in the suite's own design:

- Several test methods deliberately short-circuit with an early `return` when a
  precondition they depend on isn't met on the install they're running against —
  for example `test_wc_stock_authority()` (`Selftest.php:759-761`) skips its
  remaining checks if the stock-authority hook fixture couldn't be wired up on that
  install's WooCommerce config, and `test_customer_ledger()` (`Selftest.php:3735-3737`)
  skips its remaining checks if the fixture customer it depends on fails to create.
  Several other methods (`test_pin`, `test_attendance`, `test_payroll`,
  `test_cashier_reports`) have an equivalent guard around the borrowed-administrator-user
  block they need to exercise a REST permission check.
- These guards exist because the suite runs against a **real, live install** — not a
  clean, deterministic fixture-only sandbox — so it has to tolerate an install having
  different active gateways, different pre-existing data, or a fixture step that
  legitimately can't complete on that particular install's state, without turning the
  whole suite red for a reason that has nothing to do with a Counter bug.
- The source count (445 `check()` call sites) only changes when the suite itself is
  edited. The number of checks that actually *execute and get recorded* on any single
  run depends on which of the above guards fire on that install, that day, against
  that data — which is why "the count" is a property of a run, not a property of the
  plugin.

Anyone reading a self-test report in the future should compare the labelled
pass/fail list itself, not a total number, when judging whether the suite is healthy.

## `cleanup()` — confirmed complete

`cleanup()` (`Selftest.php:9839-10312`) was read in full for P8.6 and cross-checked
against every one of the 25 fixture-tracking class properties declared near the top
of the class (`audit_fixture_ids`, `product_fixture_ids`, `order_fixture_ids`,
`location_fixture_ids`, `register_fixture_ids`, `sale_queue_uuids`,
`customer_fixture_ids`, `transfer_fixture_ids`, `adjustment_uuid_options`,
`supplier_fixture_ids`, `batch_fixture_ids`, `po_fixture_ids`,
`po_receive_uuid_options`, `stocktake_fixture_ids`, `unit_fixture_ids`,
`label_fixture_ids`, `doc_template_fixture_ids`, `payment_account_fixture_ids`,
`expense_fixture_ids`, `expense_category_fixture_ids`, `employee_fixture_ids`,
`employee_user_fixture_ids`, `attendance_fixture_ids`, `leave_fixture_ids`,
`payroll_run_fixture_ids`). Every one is read and purged somewhere in `cleanup()`, in
correct dependency order (children before parents). Append-only, Invariant-protected
tables (`cntr_stock_moves`, `cntr_audit_log` rows outside this run's own tag,
`cntr_challan_register`) are correctly never purged — that's Invariant V, not a gap.

"Both installs" in the Direction text was verified the only way possible given this
project only ever had one real install throughout its life (peapip.com, later carrying
the P8.1 load-test dataset as a standing baseline): two consecutive full live runs of
the suite were confirmed to report an identical clean-state result, with `cleanup()`
leaving zero of its own tagged rows behind between them — see the P8.6 entry in
`docs/decisions.md` for the actual run output.

## What's left after this plan

Every task in `COUNTERIMPLEMENTATION.md` is complete as of this commit. Honestly-flagged
gaps that were found and deliberately left out of scope during the plan (not silently
missed) are documented in their own `docs/decisions.md` entries rather than repeated
here — search that file for "gap" or "not yet performed" to find them.
