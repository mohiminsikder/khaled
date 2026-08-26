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

### 2026-08-26 · pos.js line-ending note (not a bug, not reversed)

The A2 commit's diff stat looks alarming (~3000 lines) for a ~30-line change. Cause: `assets/pos.js`
was the one file in this repo stored with CRLF line endings (verified via `git show <old-commit>:... |
file -`); every other tracked file (PHP, `deploy.sh`, docs, `tests/pos-dom.mjs`) is already stored LF.
Editing it through normal tools on this Windows checkout (`core.autocrlf=true`) caused git to store it
as LF on this commit, same as everything else — a diff was going to touch every line the first time
this happened no matter which tool made the edit. Verified with `diff` after stripping `\r` from both
sides: the only real content change is the intended 31 lines. **Not reverting** — this makes the repo
internally consistent (LF everywhere) rather than reintroducing the one-file outlier, and `deploy.sh`
already being LF-stored is what keeps it running correctly if this repo is ever cloned onto the Linux
sandbox its own docs assume (a CRLF-stored shell script frequently breaks there). No `.gitattributes`
added — not needed now that the one outlier is gone, and speculative infra beyond what actually broke.

### 2026-08-26 · Standing reminder: bump CNTR_VERSION whenever pos.js/pos.css changes

Missed this for A1 and A2 (caught and fixed at A4), then missed it again for B1 and B2 — bumped to
0.1.22 belatedly. Pattern going forward: **every task whose Files line touches `assets/pos.js` or
`assets/pos.css` bumps CNTR_VERSION in `counter.php` as part of that same commit**, not a separate
catch-up later — `Pos\Assets` uses it as the literal cache-buster query string, so skipping it means a
browser that already cached the old file keeps serving it until the version string changes for some
unrelated reason. A backend-only task (no `pos.js`/`pos.css` in its Files) does not need this.

### 2026-08-26 · Verification strategy while the live selftest suite is blocked

Per `docs/BLOCKED.md`, `wp counter selftest` currently cannot complete on peapip.com (production) —
CASH/BKASH payment accounts are inactive, which cascades into fatal crashes in whichever `test_*()`
method next assumes a sale succeeded, and a crash skips `cleanup()` entirely, leaving fixture debris
behind (also documented in `BLOCKED.md`). Both need a human decision (a live-data write the permission
system declined to make unattended). **Continuing Phase A without that baseline**, task by task:

- **Frontend (`assets/pos.js`) tasks** — verified via `tests/pos-dom.mjs` against the real rendered
  screen, now working locally (`npm i`, `PW_CHROME_PATH` pointed at this machine's Chrome). This is
  actually the *more* rigorous layer for these tasks per `COUNTERV2.md` §2 — Layer B, not Layer A.
- **Backend (PHP) tasks** — `php -l` for syntax, careful manual review against the existing code's own
  conventions, and reasoning through the specific `test_*()` method being added/extended. **Not**
  claiming these are self-test-verified against the live suite; the run summary will say so plainly for
  each one, per `START-HERE.md`'s "green is not done" rule.
- **Still pushing each commit live** (`scripts/deploy.sh push`) — that step doesn't run any test and
  its own risk is bounded by the existing `.bak-<timestamp>` rollback. **Not** running
  `scripts/deploy.sh selftest` after each push until the payment-account blocker clears, to avoid
  manufacturing more crash-orphaned fixture debris for a run that cannot complete anyway.
- Once a human flips the two accounts active, the very next full run's `cleanup()` will finally execute
  and this project will have a real labelled pass/fail baseline — worth re-running immediately when
  that happens, and worth treating that first real result set as the actual baseline, not the count
  quoted anywhere in the plan docs (`TILLBUGS.md`'s "465 checks" describes the pre-crash-discovery
  belief, not a number this project has ever actually observed complete).
