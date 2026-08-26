# Decisions

Two kinds of entry. Both are append-only — never rewrite history here.

## Decisions taken by the shop

One line each: what was decided, by whom, when, and why.

_(none recorded yet — the D1–D5 defaults below were applied unattended and have not been confirmed
by the shop)_

## Assumptions made while running unattended

Written by the agent when it hits something `COUNTERV2.md` does not specify. Each entry: date, task
id, what was assumed, and how to reverse it.

### 2026-08-26 · COUNTERV2 §1 defaults applied

| | Default applied | How to reverse |
|---|---|---|
| **D1** price tier | Till price-group picker **and** `price_group_id` on the customer, auto-selected on attach | Ignore the column; the picker still works standalone |
| **D2** capabilities | `cntr_credit_sale` → Cashier and Supervisor · `cntr_refund` → Supervisor only | One line each in `Capabilities.php` + version bump |
| **D3** barcode | Real barcode meta field populated in `Catalog::reindex()`, falling back to SKU when empty | Fallback means SKU-as-barcode shops are unaffected either way |
| **D4** loyalty | Not built, and no reward-point row drawn. Deferred to E1 | B4's footer leaves room for the row |
| **D5** back office | Stays in wp-admin; Phase C builds density, not a second shell | — |

These are defaults, not verdicts. Any of them can be overturned; each was chosen so that reversing
it is contained.
