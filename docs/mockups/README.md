# Four-panel mockups

Target-state designs for the panel split, in Counter's own visual language (till palette from
`assets/pos.css`; back office in WordPress admin styling because that is where it lives).

**These are mockups, not running code.** Static HTML, no JS, no data. The screenshots in
`docs/TILLBUGS.md` are the opposite — those were the real `pos.js` booted in Chromium.

| File | Panel | State today |
|---|---|---|
| `1-cashier.html` | Cashier / billing | Mostly built. Adds product grid, shift bar, cart-level discount. |
| `2-cfd.html` | Customer-facing display | **Nothing exists.** Zero code in v0.1.20. |
| `3-admin.html` | Admin / back office | Partly built. Adds top sellers, peak hours, permissions UI. |
| `4-inventory.html` | Inventory / warehouse | Backend built and tested, **no screens at all**. |

Render: `node shots.mjs` with `playwright-core`, Chromium at `/opt/pw-browsers/`.

## Honesty notes on what is drawn

- The **QR block on the CFD is decorative**. A real bKash/Nagad merchant QR needs a merchant
  account and each provider's API — the panel is UI over an integration that does not exist.
- **Loyalty points on the CFD** depend on a points feature Counter does not have. Customer
  identity and the ledger it would build on do exist.
- **"Generate PO from low stock"** on the dashboard is new wiring, but `Reports::reorder_list()`
  and `Purchasing\Orders` are both already built and tested.
- The **permissions matrix** is a screen over `Capabilities.php`, which is code-only today. It
  would fix the two wrong default grants in `docs/COUNTERGOLIVE.md` §U0.2 by making them visible.
- **Cart-level discount** is drawn in the cashier totals. Only line discount (F4) exists today.
