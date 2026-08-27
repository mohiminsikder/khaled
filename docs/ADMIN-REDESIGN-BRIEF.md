# Counter — back-office redesign brief for Claude Design

*Paste everything below the line into Claude Design (the `design` skill) as a single
prompt. It is written to be self-contained: Claude Design starts cold with no access
to the plugin source, so every fact it needs is carried in the prompt itself.*

---

# Design the back office for Counter — a WooCommerce point-of-sale plugin for a
# Bangladeshi retail shop

## 1. Who I am designing for — read this first, it governs every other decision

One person: the shop owner. He is in his fifties, runs a retail shop in Dhaka, and
he is **not comfortable reading**. Not in English, and not fluent at reading long
Bengali text either. He reads numbers well — he has counted cash his whole life —
but a screen of English words in a grey table is a wall he will not climb.

He is also, and this is the important part, **not incurious**. He wants to know
everything: what sold, who owes him money, which staff member was late, whether the
drawer balanced, what the profit was this month, which product is about to run out.
His current back office contains all of that data and he cannot get to any of it.
Today he phones his manager to ask questions the screen is already answering.

So the design goal is not "simplify by hiding." **Nothing may be removed.** The goal
is to make every number reachable by someone who navigates by shape, colour,
position and size rather than by reading. If a screen forces him to read a sentence
to find out whether something is good or bad, that screen has failed.

Design rules that follow directly from this, and that I want you to hold to on every
artboard:

1. **A number is more readable than a word.** Lead with the figure, at a size he can
   read at arm's length on a phone. The label goes under it, small.
2. **Every screen answers one plain question in its own headline** — "How much money
   came in today?" — in Bengali first, English underneath, both real, neither a
   placeholder.
3. **Colour always carries meaning, and never carries it alone.** Green/amber/red
   means good / watch this / act now, consistently, everywhere. But every coloured
   thing also carries an icon *and* a word. He may be colour-blind; assume he is.
4. **Magnitude must be visible without reading digits.** Any column of money or
   quantity also gets a proportional bar behind or beside it, so "who owes the most"
   is answered by eye, not by comparing seven-digit strings.
5. **No jargon, no acronym, no machine value ever reaches the screen.** Not
   "Receivables aging 31–60", not `sale.void`, not `2026-08-25 14:03:22`, not
   `1250.0000`. Instead: "owes ৳১,২৫০ — ৪০ দিন ধরে", "সেলস বাতিল করা হয়েছে",
   "গতকাল বিকেল ৪টা".
6. **Time is words, not timestamps.** "আজ", "গতকাল", "৩ দিন আগে", "গত মাসে".
7. **Money is always formatted, always the same way, everywhere**: ৳ symbol, thousands
   separators, no decimal places unless the paisa is genuinely non-zero. Bengali
   numerals where the interface is Bengali.
8. **Every card ends with what to do about it.** A low-stock card ends in a button
   that starts a purchase order. An overdue-customer card ends in a button that calls
   him. Data without a next action is a dead end.
9. **Touch first.** He uses a phone and a cheap Android tablet as often as the shop
   desktop. Nothing interactive smaller than 56×56px, generous spacing, no hover-only
   affordance anywhere.

## 2. What the product is

Counter is a WordPress/WooCommerce plugin: a point-of-sale till plus a full back
office for a shop that sells both across a counter and online. WooCommerce stays the
system of record for products, orders, customers and tax; Counter adds a stock
ledger, a till, purchasing, documents and people/payroll.

The back office you are designing lives **inside WordPress admin** — the black
WordPress sidebar and admin bar are present on every screen, and the plugin's screens
render into the content area to the right of them. Show that context on your
artboards. If your design needs the WordPress chrome suppressed or restyled to work,
say so explicitly and show what replaces it — that is a legitimate design decision,
but it must be a deliberate one, not an accident of the mockup.

## 3. What exists today, and why it fails him

There are **31 back-office screens** in one flat WordPress sidebar list, loosely
grouped into five sections (Operations, Purchasing, People, Money, Admin) by a
1-pixel divider rule. All of them are stock WordPress admin: grey `.widefat` tables,
`postbox` panels, 13px grey type, and about fifty scattered inline styles. **The
plugin ships no back-office stylesheet at all** — not one line of admin CSS is
enqueued. There are no charts, no graphs, no visual encoding of any kind on any of
the 31 screens.

Specific failures worth designing against, all of them real:

- The **Dashboard** already asks the right three questions — "What did we make
  yesterday?", "What is running out?", "Did the drawer balance last night?" — and
  then answers them as bullet lists of plain text.
- **Receivables** prints raw four-decimal numbers with no currency symbol
  (`1250.0000`), and the ledger's machine type slug, in an aging table whose column
  headings are "Current / 1–30 / 31–60 / 61–90 / 90+".
- The **Activity Log** shows a raw action slug, an object type with a database id,
  and a truncated JSON blob in monospace, per row.
- **Reports** offers twelve report types in a dropdown; each renders as an unstyled
  table of numbers, no visual, no comparison, no total worth reading.
- Filters are hidden inside a collapsed accordion, so a screen showing the wrong data
  looks the same as one showing the right data.
- The interface is largely English. The Bengali translation catalogue covers the till
  well but is stale for the back office — "Payment status", "Balance owed", "Sales
  summary", "Filters" are all untranslated today. **Design everything Bengali-first;
  I will fix the catalogue to match your design.**

## 4. The design system you must inherit — do not invent a new one

The till (the cashier-facing terminal) was redesigned recently and shipped. It is
light, colourful, high-contrast, and the shop already knows it. **The back office
must look like the same product.** Use these exact tokens. They are the real ones
from the shipped stylesheet.

```
/* surfaces */
--bg:      #eef1f7   /* the app field */
--panel:   #ffffff   /* a raised card */
--sunken:  #f7f9fd   /* inset field behind inputs */
--border:  #d7dfec
--line:    #e8edf6

/* text */
--text:    #152033
--muted:   #5f6f88

/* meaning — every one of these also prints a word and an icon, never colour alone */
--accent:      #1f5fd6   --accent-soft: #e8f0ff   --accent-ink: #20458c  /* neutral system blue */
--success:     #0b8a4b   --success-soft:#e3f6ec   --success-ink:#0a6b3c  /* money in, balanced, healthy */
--danger:      #d02a2a   --danger-soft: #fdeaea   --danger-ink: #8f1f1f  /* refund, void, overdue, short */
--warn:        #b3740a   --warn-soft:   #fdf1dd   --warn-ink:   #7a4f06  /* held, low stock, watch this */

--focus:     0 0 0 4px rgba(31, 95, 214, .18)
--radius:    12px
--radius-lg: 14px

/* type — nothing interactive below 16px; he reads this from ~60cm */
--fs-meta:  14px
--fs-body:  16px
--fs-line:  19px
--fs-total: 56px

--tap: 56px   /* minimum touch target */
```

Typography: **`Noto Sans Bengali` first**, then a Latin system stack. The font is
self-hosted and already shipped with the plugin — Bengali and Latin render in the
same family, at the same weight, on the same baseline. Weights 400–700 only.

Numerals in every table and every money column use `font-variant-numeric:
tabular-nums`, so columns of money line up on their digits.

Icons: **inline SVG only, stroked with `currentColor`, 24px, stroke-width 1.9, round
caps and joins.** Never an icon font. **Never emoji** — they render differently on
every device and disappear at counter distance. An icon never appears without its
word beside it.

## 5. The information architecture I want you to design toward

Thirty-one sidebar items is the single biggest barrier. Reorganise them into **six
rooms plus a home**, each room named by what the owner would call it, Bengali first.
Every one of the 31 existing screens must land somewhere — nothing orphaned, nothing
dropped. Where a room has more screens than fit comfortably, design the overflow
("সব দেখুন" / "See everything") rather than hiding items.

- **আজ — Today** (home). What happened today and what needs me right now.
- **টাকা — Money.** All sales, who owes me, what I owe, expenses, profit, cash flow,
  the drawer/Z reports, VAT exports.
- **মাল — Stock.** What's on hand, what's running out, what's expiring, transfers
  between locations, stock counts, adjustments, product list.
- **কেনা — Buying.** Suppliers, purchase orders, receiving goods.
- **লোক — People.** Staff, attendance, leave, payroll.
- **দোকান — Shop setup.** Locations and registers, payment accounts, roles, labels,
  settings, activity log, health, performance, offline failures.

Design the sidebar itself as an artboard. It is currently 13px grey English text on
black; it should become the most legible thing on the screen — big Bengali labels,
one icon per room, a live badge where a room needs attention (three overdue
customers, two products out of stock).

## 6. Artboards to produce

Lay these out on one canvas. Fill every one with **realistic Bangladeshi shop data** —
Bengali customer and staff names, ৳ amounts in the thousands to low lakhs, real
product names for a general retail shop, dates around today. Never `Lorem ipsum`,
never "Product A", never `123.45`. The mockup has to be persuasive to a man who knows
what his own shop's numbers look like.

1. **Design system sheet** — colours with their meaning-words, type scale in both
   scripts, the icon set, buttons in every state, the status pill, the number-with-bar
   row, the answer card, form fields, the table row.
2. **Sidebar and page shell** — the six rooms, inside real WordPress admin chrome.
3. **আজ / Today — desktop.** The home screen. Money in today (huge), against
   yesterday and against last week's same day. Cash expected in each drawer. What is
   running out. Who owes me and is overdue. What needs a decision today. Top sellers
   and busiest hours as small charts, not lists.
4. **আজ / Today — phone.** The same screen at 390px. This is the one he opens most.
5. **Money — All sales list.** Every sale, till and online, with the filters *visible
   and legible*, a totals row that reads as a conclusion rather than a footer, and a
   paid/partial/unpaid state that is obvious at a glance from across the room.
6. **Money — Who owes me.** The aging table reimagined: one row per customer, with
   how much and how long overdue as bars, sorted by pain. Plus the drill-in: a single
   customer's statement, printable, with a running balance he can follow down the page.
7. **Money — Profit and expenses.** Money in, money out, what's left, for a period he
   picks. This is the screen he will care about most after Today.
8. **Money — The drawer.** Register/shift close-out: expected cash, counted cash,
   short or over, per register, per cashier. Balanced must look and feel different
   from short in under a second.
9. **Stock — What's on hand.** Product list with quantity, and a low-stock lane that
   is impossible to miss. Include the batch/expiry case.
10. **Stock — One product.** Everything known about one product: on hand per location,
    movement history, cost and margin, what it sold last month, when it was last
    bought and from whom.
11. **Buying — A purchase order, end to end.** Draft → sent → partially received →
    received, as a state the owner can read off the page. Plus the receiving screen.
12. **People — Attendance and payroll.** Who was in, who was late, who is on leave,
    what each is owed this month.
13. **Reports — one report, done properly.** Take "Sales by product" and show what a
    report should be: a chart that answers the question, the table underneath for the
    detail, a comparison against the previous period, and one plain-language sentence
    at the top saying what the numbers mean.
14. **The activity log, humanised.** Every row a sentence a person can read: who,
    what, when, and what changed — with the machine detail available on demand, not
    by default.
15. **Empty, loading, and error states** for a list screen, a report and the home
    screen. "No sales recorded yesterday" needs to look deliberate, not broken.
16. **Permission states.** Some screens and buttons are unavailable to some staff. The
    house rule is **dim, never hide** — a cashier should see that a control exists and
    that it is not theirs. Show that treatment.

## 7. Hard constraints — a design that breaks any of these cannot ship

- **No build step, no framework, no npm.** Plain CSS and plain HTML, hand-written.
  Design accordingly: no utility-class-framework thinking, no component library that
  implies React.
- **No external hosts.** No Google Fonts link, no CDN, no remote image, no analytics.
  The shop's internet drops regularly and the back office must not degrade when it
  does. Everything is self-hosted or inline.
- **No emoji anywhere.** Inline SVG only.
- **It must print.** He prints customer statements, purchase orders and the day's
  figures on a cheap inkjet. Any screen carrying a document must have a print layout
  that works in black and white on A4 — which means state must never be conveyed by
  background colour alone.
- **It must survive being wrong.** Long Bengali product names, a customer with no
  name, a negative balance, a thirty-row table and a one-row table, a number in the
  lakhs. Show at least a few of these cases rather than only the tidy ones.
- **Contrast:** 4.5:1 minimum for text, 3:1 for the boundary of any interactive
  control, on the light background. Check it, don't estimate it.

## 8. What to deliver

For each artboard: the screen itself, plus a short note naming which of the 31
existing screens it replaces or absorbs, and calling out any place where you have
proposed data or a control that does not exist in the current back office (so I can
tell design intent apart from what I can build immediately).

Then, separately, a short written summary of:

- the component inventory — every reusable piece your artboards use, named;
- the rules a developer must follow to extend the design to the screens you did not
  draw, since 31 screens will not all be mocked and the rest must still come out
  looking like these;
- anything in section 3 you think I have diagnosed wrongly.

## 9. What not to do

- Do not simplify by removing data. He wants all of it. Reorganise, summarise,
  visualise, layer — but every figure in the list above must still be reachable.
- Do not design a dark theme. The till is light; the shop is bright; this matches.
- Do not use a dashboard-template look — no gradient hero cards, no glassmorphism, no
  decorative charts that carry no information. Every pixel of ink should be a number,
  a label, a control, or a boundary between them.
- Do not translate Bengali by transliterating English words where a real Bengali word
  exists. If you are unsure of a term, mark it and I will supply the shop's own word
  for it.
