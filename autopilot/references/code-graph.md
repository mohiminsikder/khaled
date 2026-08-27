# Code graph (optional)

Autopilot can use [graphify](https://github.com/Graphify-Labs/graphify), which parses a
repository into a queryable graph of symbols and their relationships. It is **optional**.
Without it every function degrades to a no-op and the run behaves exactly as before —
never make an unattended run depend on a tool that might not be installed.

## Whether it will save you tokens: yes, but not where you would guess

Measured on an 18,000-line Python tree:

| Answering "what is in `pascal.py`" | chars into context |
|---|---|
| model reads the file | ~28,800 |
| autopilot's grep pack | ~1,200 |
| `graphify query` | ~3,100 |

For *what is in a file I already named*, grep wins. The graph is bigger and slower there,
and swapping the pack for graph queries would make this protocol worse.

The saving is on the question grep cannot answer cheaply: **what else depends on this?**
Today the model discovers that by searching — several billed turns of grep, read, grep
again. `graphify affected src/db.py` answers it from a pre-built index in milliseconds,
with no model call. That is the whole value, and it is why the integration adds a *blast
radius* section rather than replacing the pack.

Building the code half of the graph is deterministic tree-sitter parsing: **no LLM calls,
no network, nothing leaves the machine.** It took 2.2 seconds on that same 18,000-line
tree. That is cheap enough for the driver to refresh it after every batch of phases,
which matters because a stale graph is worse than no graph.

## What it actually changes in a run

**1. Blast radius in every context pack.** The phase gets told which files depend on the
ones it is about to change, with an instruction not to edit them and to stop if the change
forces one of them to move. It converts a silent breakage into a flagged plan error.

**2. Real parallel-lane safety — the big one.** Lanes are chosen by comparing each phase's
`files:` list. That list is a *claim the plan makes*. With a graph the driver **checks the
claim**:

```
P1 edits src/db.py
P2 edits src/api.py      -> the plan says these are independent
graph: src/api.py imports src/db.py
       -> not independent. P1 and P2 are serialised; P3 still runs alongside P1.
```

That case is in the self-test, and without the graph those two phases would have run
concurrently and produced a broken merge at 3am. This turns "parallelism is only as safe
as the plan's `files:` lists" from a caveat you have to trust into something verified.

## Install

```bash
uv tool install graphifyy
cd /path/to/project
graphify install --project        # registers its own assistant-side hooks
graphify update . --no-cluster    # build the code graph: free, local, seconds
```

The driver picks it up automatically from then on. Turn it off at any time with
`AP_USE_GRAPH=0`.

| Variable | Default | Meaning |
|---|---|---|
| `AP_USE_GRAPH` | `1` | `0` disables the integration entirely |
| `AP_GRAPH_DEPTH` | `2` | How far to trace dependents. `1` is stricter and faster |
| `AP_GRAPH_BUDGET` | `800` | Token cap on any graph query |

## Do not use `--strict` with autopilot

graphify offers `graphify install --project --strict`, which **blocks the first raw source
read of a session** and redirects it to the graph. It is a good idea in an interactive
session, where it fires once and teaches you a habit.

It is the wrong setting here. Under this protocol **every phase is a new session**, so
"once per session" becomes *once per phase* — a denied read and a wasted turn on every
single phase of the run. It cannot strand the agent (the next read is allowed), so it is a
cost rather than a hang, but it is a cost you pay dozens of times a night for nothing.

Use the default soft-nudge install. `GRAPHIFY_HOOK_STRICT=0` forces it off if you enabled
strict for your own interactive work.

## Hook interaction

Both tools register `PreToolUse` hooks and both fire. They do not fight:

- autopilot's `guard-output.sh` matches `Bash` and returns `updatedInput` — it rewrites an
  unbounded command into a bounded one.
- graphify's `hook-guard` matches `Bash|Grep` and `Read|Glob` and returns
  `additionalContext` — it nudges toward querying the graph.

Different fields, so both apply. The one combination that misbehaves is strict mode, which
returns `permissionDecision: deny` — see above.

## What this does not fix

- **Non-code assets.** The free, deterministic pass covers code. Docs, PDFs and images need
  a semantic pass that *does* spend model calls. Autopilot only ever runs
  `graphify update --no-cluster`, the code-only path, so the graph never quietly costs you
  tokens mid-run. Run the semantic pass yourself, deliberately, if you want it.
- **A stale graph.** It is refreshed after each batch, but a phase that adds a brand-new
  file works from a graph that predates it. The pack's blast-radius section is an aid, not
  a guarantee.
- **Languages tree-sitter does not parse well.** Coverage is broad but uneven. If
  `graphify affected` returns nothing for files you know have dependents, the lane check
  silently falls back to trusting the plan — set `AP_LANES=1` for that repo.
