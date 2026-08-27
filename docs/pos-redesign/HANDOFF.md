# POS redesign — cold-start handoff

Read this first if you are picking this work up in a **new session or a different
Claude Code account**. Everything needed is in this repository; nothing depends on
the account that started the work.

## Why this file exists

The original session held three things that do **not** survive an account switch:

| Thing | Survives? | Why |
|---|---|---|
| The `counter` plugin source | **Only if committed here** | It arrived as an uploaded zip in an ephemeral container. |
| The Claude Design canvas | **No** | Artifacts and design projects are owned by the account that made them. |
| Session conversation context | **No** | New session starts cold. |

So the rule is: **if it is not committed to this repo, it does not exist.**

## What is in the repo

- `docs/pos-redesign-prompt.md` — the full redesign brief. The block between the
  `BEGIN PROMPT` / `END PROMPT` markers is what you paste into a fresh session.
  It already contains the codebase constraints, the element inventory to preserve,
  and the traps that break the plugin.
- `docs/pos-redesign/design/` — the exported Claude Design output (see below).
- `counter/` — the plugin source, if it has been committed.

## Getting the design out of Claude Design

Do this **while still signed in to the account that generated it.** Once you switch,
you lose read access to the artifact and the design project.

1. **If it is a design canvas Artifact** (a `claude.ai/code/artifact/...` link):
   open it and use the canvas **Export** control. Take **PNG per artboard** — and the
   `.dc.html` source too if the export offers it.
2. **If it is a claude.ai/design project**: use **Download** / **Export** to get the
   project files. Do not rely on "Send to Claude Code Web" for a cross-account
   handoff — it seeds a workspace tied to the current account.
3. **In both cases, also screenshot every artboard.** Images are the one format that
   is always readable, by any account, in any session, with no auth.

Then commit whatever you exported into `docs/pos-redesign/design/`, plus a short
`design/NOTES.md` recording:

- which screen each file shows (till idle, cart with items, tender, return, X-report…),
- any colour/spacing values the design states explicitly,
- anything the design shows that you know the plugin cannot currently do.

## Starting the new session

In the new account, with this repo attached:

1. Point it at `docs/pos-redesign-prompt.md` and `docs/pos-redesign/design/`.
2. Say: *"Redesign the Counter POS terminal following `docs/pos-redesign-prompt.md`.
   The approved visual direction is in `docs/pos-redesign/design/`. Start with the
   element inventory and layout plan in §1 and §7 — stop and show me that before
   writing code."*
3. Confirm the new account has write access to this repository, and keep working on
   the `claude/pos-terminal-redesign-qersut` branch.

That is the whole handoff. No prior conversation is needed.

## The four things that break the plugin

Repeated here so they survive even if the prompt file is skipped:

1. **`STRINGS` is declared twice** — ~200 keys in `templates/pos.php` and mirrored by
   hand in `assets/pos.js`. Add a label to one only and Bengali cashiers see `undefined`.
2. **`render()` rebuilds via `innerHTML`, then re-binds listeners by element id.**
   Renaming a class is free; renaming an id silently kills a button.
3. **No external hosts.** The till must sell offline — `sw.js` precaches the shell.
   A CDN font or icon library turns the terminal into a blank screen when the shop's
   internet drops.
4. **Bump both cache-busters** — `CNTR_VERSION` in `counter/counter.php` (the `Version:`
   header *and* the `define()`; `test_version()` asserts they match) and `CACHE_NAME`
   in `assets/sw.js`. Otherwise tills run new PHP against cached old JavaScript.
