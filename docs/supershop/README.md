# Counter with the SuperShop structure — 11 screens

Target-state mockups applying the `SuperShopTeardown` structure to Counter. **Static HTML, no JS,
no data.** Regenerate with `python3 gen1.py … gen6.py`, render with `node shoot.mjs`.

| # | Screen | Counter today |
|---|---|---|
| 01 | POS terminal | Four zones exist in spirit; **no product grid, no price-group picker, no per-line unit dropdown, no editable subtotal, no order-level discount, no draft/quotation** |
| 02 | Payment modal | Split tender + credit **exists and works** (v0.1.20). Adds accounts with live balances, sell/staff notes |
| 03 | Register X-report | **Nothing.** Till can open a shift; cannot close or reconcile. Z-report is wp-admin only |
| 04 | Dashboard | Partly. **No trending products, no peak hours** (`cntr_sales_daily` is daily grain) |
| 05 | Products | **WooCommerce only** — no Counter product screen |
| 06 | Add purchase | Backend built and tested; **no screen**. The margin linkage is new |
| 07 | All sales | **No screen** |
| 08 | Profit / Loss | Report logic exists; screen is raw |
| 09 | Register report | **No screen** |
| 10 | Roles & permissions | **Code-only.** This screen is why the two wrong grants went unnoticed |
| 11 | Print labels | LabelDesigner exists — 14 mm fields, **no preview** |

## Deliberate departures from the teardown

Adopted: the four-zone till, five ways to conclude a sale, the X-report that prints its own
arithmetic, cost→margin→selling-price in one purchase row, the filter accordion, per-role POS
lockouts, configurable search fields.

**Not** adopted, and why:

- **Configuration by subtraction.** SuperShop ships eighteen "Disable…" toggles: everything on,
  switch off what a shop shouldn't have. Counter gates on capabilities the roles already carry.
  One mechanism, not two.
- **Its defects.** The teardown lists fifteen. Fixed by construction here: exports enabled by
  role (#14); no broken product images (#1, #2); quick-add requires a selling price rather than
  inheriting a 20% default margin (#13); P&L guards against negative COGS instead of printing it
  (#9); date filters default to a range that includes the data (#7).
- **Reward points.** Drawn on the till totals row because the teardown has it, but Counter has no
  loyalty feature. Either build it or drop the row — do not ship the label over nothing.
- **A separate app shell.** These mockups draw Counter's own sidebar. Counter is a WordPress
  plugin and its back office lives in wp-admin today; adopting a dedicated shell is a real
  architectural decision, not a styling one. The alternative is the same density inside wp-admin.

## Payment boundary, unchanged

The 13 payment methods here are still **records**, not integrations. Counter does not drive a card
terminal and has no QR generation. Same boundary as `docs/mockups/README.md`.
