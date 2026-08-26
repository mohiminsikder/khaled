# Counter — install guide

This is machine setup, not code. None of it needs a developer, but every step here is the
difference between a six-second sale and a twenty-second one — a till that pops a print dialog
and a "which printer?" prompt on every sale will not survive a busy morning.

## 1. Find the till

Log in to wp-admin as an administrator. The till has two entry points:

- The **admin bar** — a "Till" link appears for anyone who can use the till (a cashier or the
  terminal account), not just admins.
- The **Dashboard** (`Counter → Dashboard`, the first screen you land on) — a "The till" panel
  lists every active register with its own link and a **Copy link** button.

A shop with more than one physical till should bookmark **each till's own URL**
(`/pos/?register=<id>`) on that specific machine — not the plain `/pos/` link, which falls back to
whichever register happens to be first. The Dashboard panel's Copy link button gives you the exact
URL for a given register; paste it into that machine's browser bookmarks.

The Dashboard also shows a **"Is the shop ready to sell?"** checklist the first time you visit —
a location and register, an active payment account, a cashier account, a published product, and an
open shift. Each miss links straight to where it's fixed. It stays until you dismiss it or every
check passes.

## 2. Create a cashier account

Counter's roles are registered the moment the plugin activates, but nothing in wp-admin's normal
flow tells you that. To create one:

1. **Users → Add New**.
2. Fill in the usual fields (name, email, a password).
3. Set **Role** to **Counter Cashier** (or **Counter Supervisor**, if this person should also
   handle discounts, void lines, refunds, and stock adjustments — see the role list below).
4. Log this cashier in on the till machine itself, not the administrator account — an admin
   account can do everything, which is exactly what you don't want logged in at a till all day.

| Role | Can |
|---|---|
| **Counter Cashier** | Use the till, open/close their own shift, hold a sale, sell on account (credit) |
| **Counter Supervisor** | Everything a Cashier can, plus discounts, price overrides, voids, no-sale, refunds, stock adjustments, transfers, closing any register's shift |
| **Counter Stockkeeper** | Stock, purchasing, labels, cost visibility, online-order fulfilment — no till access at all |
| **Counter Manager** | Everything operational, every report — not settings or user management |

## 3. Set up the printer

The receipt prints through a hidden frame calling the browser's own print function — there is
**no dialog and no printer picker** once this is configured, but the browser has to be told which
printer to use and how, once, per machine.

**Windows default printer.** Set the receipt printer as the machine's default printer
(Windows Settings → Bluetooth & devices → Printers & scanners → select it → **Set as default**).
The till does not pick a printer; whatever the OS default is, is what prints.

**The drawer.** If the cash drawer is triggered by the printer (the common setup for a
receipt-printer-plus-drawer combo), there is a checkbox in the printer's own driver settings —
usually named something like "Open cash drawer" or "Kick drawer after print," under the printer's
Properties/Preferences in Windows. Turn it on. Counter's own logic decides *when* to print (a cash
sale, not a credit sale, per §6's manual pass); the driver decides what printing *does* to the
drawer.

**Chrome's `--kiosk-printing` flag.** By default Chrome still shows a print preview dialog even
though Counter calls print() automatically — this flag skips it and prints straight to the default
printer. Make (or edit) the shortcut Chrome opens the till with, and add the flag to its target:

```
"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk-printing "https://your-shop.example/pos/?register=1"
```

Use this shortcut (not a normal browser bookmark) to open the till on each machine. Without it,
`F10` (no-sale) and every completed sale will stop at a print dialog a busy till cannot afford.

## 4. Confirm it

Before calling a till ready:

- [ ] The shortcut above opens the till straight to its own register.
- [ ] A test sale prints with no dialog, at the right paper width.
- [ ] The drawer kicks on a cash sale.
- [ ] The drawer does **not** kick on a credit sale.
- [ ] A cashier (not an admin) can log in and see the credit-sale row at the tender screen.
- [ ] The Dashboard's readiness panel shows every check passing, or you know what's left and why.

`docs/counter-card.md` is the one-page keyboard reference — print it and tape it to the counter
once the till itself is working.
