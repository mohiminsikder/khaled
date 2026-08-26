# Manual queue

Checks that need a person at real hardware. Appended by the agent as it passes each task's
**Manual** line; never a reason to stop a run.

**A phase is not accepted until its manual items are done.** Automated checks passing is not the
same thing, and the run summary must say which of these are still outstanding.

Format: `- [ ] <task id> — <what to do> — <what proves it>`

## Outstanding

- [ ] A3 — Scan twenty items off the shelf — each resolves at the right price via its real barcode
      (`_cntr_barcode` meta), not just by SKU coincidence. Note: no product on peapip.com has this
      meta field populated yet (D3(b) only wired the read side; populating real barcodes is a
      shop-data/import step, out of A3's scope) — this check is only meaningful once at least one
      product has a real barcode set.
- [ ] A4 — Log in as a real cashier (not an administrator) and confirm the credit-sale row appears at
      the tender screen, and that Refund is no longer offered to them. Verified programmatically live
      (`docs/decisions.md`/commit ac410ca — cashier has cntr_credit_sale=true, cntr_refund=false) but
      never seen through an actual cashier's own screen.

## Standing checks — every phase

- [ ] Scan twenty items off the shelf; each resolves at the right price and unit
- [ ] Scan into a focused text field; the barcode does **not** land in it
- [ ] Type a SKU by hand at human speed; it is **not** treated as a scan
- [ ] Unplug the network: search works, a sale completes and queues; reconnect and it drains with the
      receipt number unchanged
- [ ] Every key in the map, in order, on the real machine
- [ ] Receipt prints with **no dialog**, correct ৳, correct Bengali, correct width
- [ ] Drawer opens on a cash sale and **not** on a credit sale
- [ ] Bill a real standing customer: part cash, part bKash, remainder on account — then check the
      receipt balance, the Receivables debit, and aging
- [ ] Open a shift with a float, sell through, run the X-report, count the drawer, close, and confirm
      the variance matches a hand count
