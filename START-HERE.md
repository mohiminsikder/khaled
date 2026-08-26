# Start here

You are continuing work on **Counter**, a WordPress point-of-sale plugin for a Bangladeshi shop.
Nobody is watching this run. Do not stop to ask questions.

## What to read, in this order

1. **`docs/COUNTERV2.md`** — the plan. Work its tasks in order. §0.2 is the unattended protocol and
   overrides any instinct to pause; §1 holds five decisions that are **already made** — apply the
   defaults, do not seek confirmation.
2. **`docs/TILLBUGS.md`** — three verified defects with root cause and fixes. A1–A3 in the plan.
3. **`docs/DEPLOY.md`** — how to reach the live server. Key-based; never prompts.

Reference only, when a task points you there: `COUNTERIMPLEMENTATION.md` (architecture, invariants,
schema — still authoritative), `COUNTERFRONTEND.md`, `COUNTERGOLIVE.md`.

## What is here

```
counter/        the plugin source at v0.1.20 — this is what you edit
tests/          DOM harness that drives the real pos.js in headless Chromium
scripts/        deploy.sh — pull, push, selftest, status
docs/           the plan and its companions
```

`docs/BLOCKED.md`, `docs/MANUAL-QUEUE.md` and `docs/decisions.md` exist and are where you write.

## Where are you? Work this out first

This plan is larger than one session. **Before starting anything, find out what has already been
done** — do not assume you are the first run:

```bash
git log --oneline -25          # completed tasks; commit subjects name them
cat docs/BLOCKED.md            # tasks that failed and why
cat docs/decisions.md          # assumptions an earlier run already made
cat docs/MANUAL-QUEUE.md       # what is waiting on a human
```

Commit subjects follow the plan's own **Commit** lines, so the last one tells you where the previous
run stopped. **Start at the first task in `docs/COUNTERV2.md` that has no matching commit**, skipping
anything listed in `BLOCKED.md` unless the blocker is now resolvable.

If `git log` shows only the import commit and the docs, you are the first run — start at A0.

## Begin with

**Task A0** in `docs/COUNTERV2.md` — add `wp counter selftest`. Everything after it depends on being
able to run the suite from a terminal; today it is reachable only from a browser.

## The loop, per task

```
edit counter/ → node tests/pos-dom.mjs → commit → push
              → scripts/deploy.sh push → scripts/deploy.sh selftest
```

A non-zero `selftest` means roll back with the command `push` printed, log it in `docs/BLOCKED.md`,
and move to the next task. Do not halt the run.

## Rules that matter most

- **Never stop to ask.** Unspecified? Choose the smallest, most reversible option, record it under
  *Assumptions* in `docs/decisions.md`, continue.
- **Never weaken, skip or delete a failing test.** A failing check means the code is wrong until
  proven otherwise.
- **Never leave the tree broken.** A task is done when its tests pass and it is committed and pushed.
- **A blocked task is reverted and reported, not left half-applied.**
- **Manual checks never block.** Append to `docs/MANUAL-QUEUE.md` and carry on — they need a person
  at real hardware.
- **Bump `CNTR_VERSION`** in `counter/counter.php` on every rebuild. Roles are only written on
  activation and version bump, so a capability change without a bump silently does nothing.

## Two traps this project has already fallen into twice

**The suite tests files, not the screen.** v0.1.8 shipped five empty handlers past 445 green checks;
v0.1.20 shipped a blocking search bug past 465. Both because the PHP tests reach code through
`window.CNTR._pos`, an export hatch — the tests call the functions, the screen never does. Every
task that touches the till adds assertions to `tests/pos-dom.mjs`, **through rendered markup only**.
An assertion that reaches into `window.CNTR._pos` recreates the exact blind spot.

**Green is not done.** The manual queue is real work, not paperwork. Say plainly in your run summary
which manual items are outstanding; never call a phase accepted on automated checks alone.

## When you finish, or run out of tasks

Write a summary: tasks completed, tasks blocked and why, assumptions made, manual items queued, and
the current state of the suite as a labelled pass/fail list — **never a total count**
(`HANDOVER.md` explains why: guards legitimately skip checks on a live install).
