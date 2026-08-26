# COUNTER — Go-live gap (U0)

**Against:** v0.1.20
**Status:** the till is built and works. Nothing tells anyone it exists.
**Size:** roughly one sitting. This is not a phase.

---

## 0. What this is

Phase F and U1–U4 landed and landed well — verified by reading v0.1.20, not by trusting the
report. `pos.js` went 43 KB → 117 KB, `pos.css` 4.6 KB → 13.8 KB, every stubbed handler is now a
real capability-gated modal, `searchByText()` is wired into the UI at line 776, and there are 11
`.focus()` calls where there was one.

The plugin is not missing a shop-floor environment. **It is missing the door to the one it has,
and one capability grant that makes the headline feature invisible to the person who needs it.**

Four tasks. None of them are large.

---

## U0.1 — The till has no link anywhere

**Severity** Blocking. This is the whole "I see no frontend to run on a shop."

`/pos/` exists, the rewrite is registered and flushed on activation
(`Boot::on_activate()` calls `Terminal::add_rewrite()` then `flush_rewrite_rules()` — a bug already
found and fixed in P7.1). The page works.

But **there is not one link to it in the entire plugin.** Verified: every `/pos/` reference in
`includes/` is a code comment. Not in the Counter menu, not on the Dashboard, not in the admin bar.
To open the till you must already know to type `https://<site>/pos/`.

`templates/pos.php` says a real till is *"a dedicated machine bookmarked to its own
`/pos/?register=<id>`"* — that design is sound. But nothing in the product ever tells you the URL,
so the first bookmark cannot be made without reading source.

**Build**

- **Admin bar link**, visible on every screen to anyone with `cntr_use_pos` or
  `cntr_terminal_access`: "Open Till" → `home_url('/pos/')`, `target="_blank"`.
- **A prominent button on the Counter dashboard** (`Screens/Dashboard.php`), above the sales
  panels. This is the first screen an owner lands on.
- **A "Registers" panel on the dashboard** listing each active register with its own bookmarkable
  URL — `/pos/?register=1` and its prefix — with a copy control. This is what makes the
  dedicated-machine design actually usable, and it needs no Registers CRUD screen to exist.

**Self-test** `test_pos_entry_points()` — 3 checks: the dashboard HTML contains a link to
`home_url('/pos/')`; the admin bar node is registered for a user with `cntr_use_pos`; it is absent
for a user with neither till capability.
**Commit** `counter: admin: surface the till with admin-bar and dashboard entry points`

---

## U0.2 — The cashier cannot make a credit sale

**Severity** Blocking, and it defeats the stated first priority.

The whole point of Phase F was billing a standing customer on account. Trace the chain in v0.1.20:

1. `Rest\Customer::profile()` line 154 — `'can_credit' => current_user_can('cntr_credit_sale') && $customer_id > 0`.
2. `pos.js` line 1640 — the credit tender row renders only when `can_credit === true`.
3. `Capabilities::cashier_caps()` — `cntr_use_pos`, `cntr_open_shift`, `cntr_close_shift`,
   `cntr_hold_sale`, `cntr_refund`. **No `cntr_credit_sale`.**
4. `Capabilities::supervisor_caps()` — cashier caps plus discount, override, void, no-sale, adjust,
   transfer, close-any-shift. **Still no `cntr_credit_sale`.**

`cntr_credit_sale` appears in exactly one place: the master `all_caps()` list, which reaches
administrator and Counter Manager.

**So a Counter Cashier standing at the till sees no credit row at all.** Neither does a Counter
Supervisor. The feature is invisible to everyone except an administrator and a manager.

`pos.js` line 1638 even carries the comment *"cntr_credit_sale is a capability of the CASHIER"* —
the code's own assumption is contradicted by the role definition. Every self-test passed because
the suite runs as administrator.

**Build**

- Decide who may sell on credit — cashier, or supervisor only. **This is the owner's call, not a
  code decision:** letting every cashier extend credit is a real commercial risk, and letting none
  of them do it makes the feature useless. Ask, then grant `cntr_credit_sale` to that role in
  `Capabilities.php` and record the answer in `docs/decisions.md`.
- Roles are only written on activation and version bump (`grant_all()`), so ship it with a
  `CNTR_VERSION` bump or the existing install never picks it up. This is the kind of change that
  silently does nothing on the live site otherwise.

**Self-test** Extend `test_caps()` — 2 checks: the role chosen holds `cntr_credit_sale`; a plain
Counter Cashier's `can_credit` matches the decision rather than being false by accident.
**Manual** Log in as a real cashier — not an administrator — and confirm the credit row appears.
**Commit** `counter: caps: grant credit-sale to the till role that sells on account`

---

## U0.3 — No setup documentation ships

**Severity** High.

`COUNTERIMPLEMENTATION.md` referenced `docs/install-guide.md` (the `--kiosk-printing` machine
setup, without which every sale opens a print dialog and a six-second sale becomes twenty) and
`docs/counter-card.md` (the keyboard card to tape to the counter). **Neither exists.** There is no
`docs/` directory in the plugin at all — only `HANDOVER.md` and `readme.txt`.

`readme.txt` still says `Stable tag: 0.1.0` with a changelog that stops at the foundation commit.

**Build** — `docs/install-guide.md` covering:

- Activate → what gets seeded automatically (below), and how to open the till.
- **Chrome `--kiosk-printing` shortcut** and setting the register's printer as the Windows default.
  P1.14 is explicit that this is a machine-setup step and not code, and it is the difference
  between a usable till and an unusable one.
- The printer driver's "open cash drawer before/after printing" checkbox.
- Creating a cashier: Users → Add New → role **Counter Cashier**. The roles are already registered
  (Counter Cashier / Supervisor / Stockkeeper / Manager / Terminal), so this works today — it is
  simply written down nowhere.
- Bookmarking each till to its own `/pos/?register=<id>`.

`docs/counter-card.md` — the printable key card, matching what the keys now actually do.

Refresh `readme.txt`'s stable tag and changelog.

**Commit** `counter: docs: install guide, counter card and refreshed readme`

---

## U0.4 — Nothing tells a new owner the shop is ready

**Severity** Medium. Cheap, and it removes most of the confusion this document exists to answer.

Activation already seeds more than anyone has been told. `Install::seed()` creates:

- **Main** location (`MAIN`, default, online-sellable)
- **Register 1** (`R1`, active, on Main)
- Price groups: retail (default), wholesale, online
- Counters: `catalog_rev`, `po_no`, `transfer_ref`, `stocktake_ref`
- Payment accounts: Cash Drawer, bKash, and the full method set, so no tender is refused on day one

**A fresh single-till install is genuinely ready to sell.** That is worth knowing — an earlier audit
claimed locations and registers existed only as load-test leftovers, and that was wrong.

**Build** A first-run panel on the Counter dashboard, dismissible, showing real state rather than a
static checklist: location and register seeded ✓, payment accounts ✓, till URL (copyable), cashier
users created (count, with a link to Users → Add New), products in the catalogue (count), shift
currently open or not. Anything missing links to where it is fixed.

**Self-test** `test_first_run_panel()` — 3 checks: it reports the seeded register; it reports zero
cashiers on an install with none; it does not render once dismissed.
**Commit** `counter: admin: first-run readiness panel on the dashboard`

---

## What is still genuinely absent

Unchanged and correctly scoped out — **U5**, the nine missing back-office screens: locations and
registers CRUD, employees, suppliers, purchase orders and receiving, transfers, batches,
stocktakes, settings.

Consequence, stated plainly: **a single-till shop can run on v0.1.20 once U0.1–U0.4 are done.** A
second till, a second branch, or onboarding staff through Counter's own UI still needs U5. Adding a
register today means a direct database insert.

Scope U5 separately. Do not fold it into U0.

---

## Order

1. **U0.2** — one line plus a version bump, and it unblocks the priority feature. Ask the owner
   who may sell on credit first.
2. **U0.1** — the door.
3. **U0.4** — the readiness panel.
4. **U0.3** — the guide, written once the above are true so it documents reality.
