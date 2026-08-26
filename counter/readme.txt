=== Counter ===
Contributors:
Tags: point of sale, woocommerce, stock, retail
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 0.1.21
License: GPLv2 or later

Point of sale, stockroom and back office for a WooCommerce shop that sells both
online and across a counter. WooCommerce remains the system of record for products,
orders, customers, tax and refunds; Counter adds a stock ledger, a till, purchasing,
documents and people.

== Description ==

One site, one WooCommerce, one database. See COUNTERIMPLEMENTATION.md for the full
build plan, invariants and self-test contract this plugin is built against, and
docs/COUNTERV2.md for the phased plan from here to v1.0.0.

New to Counter? Start with docs/install-guide.md — printer, scanner and drawer setup
is machine configuration, not code, and is the difference between a six-second sale
and a twenty-second one. docs/counter-card.md is a one-page keyboard reference to
print and tape to the counter.

== Changelog ==

= 0.1.21 =
* CLI: `wp counter selftest` runs the self-test suite from a terminal, with
  `--format=json`/`--filter=<substring>` and a real non-zero exit code on failure.
* Fix: the till silently dropped every second item added by name search.
* Fix: Enter now submits every till modal (customer lookup, quantity, discount,
  price override, no-sale, quick-add), not only the customer lookup's Find button.
* Fix: the barcode field is populated from a real per-product source (falling back
  to SKU), instead of always indexing empty — scanning now actually uses it.
* Caps: cashiers can now sell on account (cntr_credit_sale); refund moves to
  Supervisor only.
* Admin: the till has a link in the admin bar and on the Dashboard (one row per
  active register, each with its own bookmarkable URL), and a first-run readiness
  panel reporting whether the shop is actually ready to sell.

= 0.1.0 =
* Foundation: plugin skeleton, HPOS compatibility declaration, autoloader.
