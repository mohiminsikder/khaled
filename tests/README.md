# Counter till — DOM harness

The harness `COUNTERFRONTEND.md` task F0 called for. It was never built, which is why the
bugs in `docs/TILLBUGS.md` shipped in v0.1.20 with a green 465-check suite.

## Why it exists

`Selftest.php` verifies `pos.js` as a **file** (no external host, no `console.log`, the route
resolves). The logic tests that pass reach the code through `window.CNTR._pos`, the export hatch
at the bottom of `pos.js`. **The tests call the functions; the screen never does.** Anything
broken in the wiring between a keystroke and a function call is invisible to all 465 checks.

This harness drives the real, unmodified `pos.js` through real DOM events and asserts on rendered
markup. Nothing here may import from `window.CNTR._pos` — reaching into the export hatch
reproduces exactly the blind spot this exists to cover.

## Running it

```
npm i playwright-core
node tests/pos-dom.mjs
```

Chromium is pre-installed at `/opt/pw-browsers/`. Do **not** run `playwright install`.

Note: `sleep` is blocked in some sandboxes — use `page.waitForTimeout()`, never a shell sleep.

## How it works

`pos-harness.html` sets `window.CNTR` (the bootstrap `templates/pos.php` prints), stubs `fetch`
for every route the terminal calls at boot, stubs `window.print`, and then loads the real
`pos.js`. `CFG` is read at IIFE entry, so the bootstrap must be set in a script tag **before**
the `pos.js` tag or the harness tests nothing.

Fixture catalogue rows mirror `Stock\Catalog::reindex()`'s real payload shape — including
`barcode: ''`, which is what the live indexer actually writes today. See `docs/TILLBUGS.md` #3.

## Not shipped

`tests/` is excluded from the release zip. The plugin a shop installs never contains it.
