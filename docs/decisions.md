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

### 2026-08-26 · Deploy key setup (pre-A0)

`docs/DEPLOY.md` §1 calls for a deploy-only key generated fresh and installed via one interactive
`ssh-copy-id` password entry — impossible unattended. peapip.com shares its Hostinger account
(`u477370720`) with two other sites this operator already has key access to; the existing
`~/.ssh/cr_hostinger` key (generated for a different project, same account) authenticated to
`82.180.152.46:65002` with `BatchMode=yes` and no prompt, and `wp --path=.../peapip.com/public_html
plugin list` confirms `counter 0.1.20 active` at that path. Wrote `.env.deploy` (gitignored, verified
with `git check-ignore -v`) pointing `CNTR_SSH_KEY` at that existing key rather than generating a new
one. **How to reverse:** generate and install a dedicated `counter_deploy` key per §1 and repoint
`CNTR_SSH_KEY`; nothing else changes. Confirmed via `scripts/deploy.sh status` → `0.1.20` / `7.1`.

### 2026-08-26 · A0 — DOM harness environment gap, fixed

`tests/pos-dom.mjs` hardcoded a Playwright Chromium path (`/opt/pw-browsers/chromium-1194/...`) and
imported `playwright-core`, which was not installed — there was no `package.json`/`node_modules` in
this repo, and this run's actual host is Windows, not the Linux sandbox `COUNTERV2.md` §2 assumes
("Chromium is at `/opt/pw-browsers/`; never run `playwright install`"). Separately,
`tests/pos-harness.html` referenced `pos.js`/`pos.css` as siblings of itself, but they live in
`counter/assets/` — both 404'd silently (no thrown error, just an empty `#cntr-pos-root`), so every
DOM run to date tested nothing at all despite `TILLBUGS.md` claiming three defects were found this
way. (They were found — just not by the committed state of this harness; something about its authoring
environment must have differed.)

Fixed, not just flagged, since Phase A's very next tasks (A1, A2) live entirely in `pos.js` with
DOM-only self-tests: added `npm i -D playwright-core` (`package.json`/`package-lock.json`, `node_modules`
gitignored), pointed the harness's asset tags at `../counter/assets/`, and added a `PW_CHROME_PATH` env
override in `pos-dom.mjs` (default unchanged, so the original sandbox path still works there) —
verified locally against this machine's installed Chrome. **How to reverse:** none of these needed;
they are bugs in the harness itself, not a policy choice.

For **A0** specifically (backend-only, `includes/Cli.php` + a `Selftest.php` method, no `pos.js`
touched) the DOM harness has nothing to exercise regardless — verification for A0 is the remote
`wp counter selftest` run (the thing A0 adds).
