# Speed — where the wall clock actually goes

A sequential unattended run is slow for three reasons, in this order:

1. **Phases run one at a time** even when they are independent.
2. **Every phase rediscovers the codebase** — four or five turns of grep and read before
   the first edit.
3. **Every phase runs on the same model**, so a config file rename costs the same
   latency as a concurrency fix.

Each has a fix. Together they are worth roughly half the wall clock on a plan with a
normal amount of independence in it; on a strictly linear plan, parallelism contributes
nothing and the other two still do. Measure your own with `scripts/autopilot-report.sh`
rather than trusting the number.

## 1. Parallel lanes

Phases whose `depends:` are satisfied and whose `files:` sets are disjoint have no
reason to wait for each other. The driver runs up to `AP_LANES` of them concurrently,
each in its own git worktree.

```
             P1 ──┬── P2 ──┐
                  ├── P3 ──┼── P6
                  └── P4 ──┘
                      P5 ─────
```

`P2 P3 P4` run together; `P5` joins them if it shares no files. `P6` waits.

**Worktrees, not branches in one directory.** Two `claude` processes editing one
checkout will interleave writes and produce a diff neither of them intended. A worktree
per lane gives each one a private tree, and lanes merge back with a normal merge commit
that git will flag if the plan's disjointness claim was wrong.

**The honest cost.** The prompt cache prefix embeds the working directory, so each
worktree starts cold and warms its own cache. Four lanes means four cache builds instead
of one. On the small per-phase contexts this protocol produces that is a good trade, but
it is not free — which is why the default is 3, not 8.

```bash
AP_LANES=3   # default. 1 = strictly sequential and cheapest per token.
```

Set `AP_LANES=1` when a run is token-constrained rather than time-constrained. Set it to
1 also for any plan where you are unsure the `files:` lists are accurate — the recovery
cost of a bad parallel merge at 3am exceeds what parallelism saved you.

**The plan is the contract — unless you can check it.** Parallelism is only as safe as
`files:`. A phase that touches a file it did not declare is the one plan error this
design cannot absorb, which is why the protocol asks the model to stop and update
`PLAN.md` rather than reach outside the list.

With an optional code graph installed, the driver stops trusting that contract and
verifies it: if phase A's blast radius reaches a file phase B declares, they are
serialised however independent the plan claims they are. See `references/code-graph.md`.

## 2. Context packs — stop paying for rediscovery

Before invoking the model, the driver greps the phase's declared files for its declared
symbols and writes the surrounding spans to `.autopilot/pack/P<n>.md`:

```markdown
# Pack for P3 — src/middleware/rate-limit.ts, src/server.ts
## src/server.ts (218 lines) — imports and mount points
  12: import { rateLimit } from './middleware/rate-limit'
  47: app.use(cors())
  48: app.use(express.json())
## src/middleware/rate-limit.ts — does not exist yet
## Related tests
  test/rate-limit.test.ts (41 lines)
```

Two turns of shell replace four or five turns of model-driven search. It is faster
because grep is faster than inference, and cheaper because those turns are never billed.
The model still reads what it needs — the pack is a map, not a substitute for reading —
but it starts from the right page.

The same principle drives everything else the driver does itself: the checkpoint, the
verify run, the status flip, the journal line, the commit. All deterministic, all shell,
none of them a model turn.

## 3. Model routing

`class:` in each phase selects the model and the thinking budget:

| class | Model | `MAX_THINKING_TOKENS` | Fits |
|---|---|---|---|
| `mech` | haiku | 2000 | renames, config, scaffolds, codegen, docs, mechanical refactors |
| `logic` | sonnet | unset (full) | implementation with real branching behaviour |
| `hard` | opus | unset (full) | schema and API design, concurrency, security-sensitive work |

Sonnet is the default and the workhorse — routing is about the tails, not the middle.
Moving genuinely mechanical phases to Haiku is where the latency goes; nothing else in
this document changes per-phase speed as much.

**The guardrail that makes this safe.** A `mech` phase whose `verify` fails is
automatically re-run once on sonnet before it is allowed to block. Routing therefore
cannot lower the quality of what lands — it can only cost one cheap wasted attempt when
a phase was misclassified. A phase that fails twice escalates a tier regardless of its
class, so a hard problem finds the right model on its own.

**Where not to economise.** Thinking budget is never trimmed on a `logic` or `hard`
phase, and never on a debug retry of any phase. A wrong fix costs a rollback, a blocked
phase, and a morning of review — several times what the thinking would have cost. The
signal is irreversibility: a migration, a schema change, or a public API signature
deserves ten turns of care; renaming a local does not.

## 4. Smaller latency wins

- **`--fallback-model`.** An overloaded primary otherwise costs a full retry cycle.
- **Background regression.** The full suite runs in the background while the next phase
  starts. Its result is picked up at the next boundary rather than blocking one.
- **Denied tool definitions.** Denying `WebSearch`, `WebFetch`, and `NotebookEdit` by
  bare name removes them from the system prompt, so every request is slightly smaller
  and every prefill slightly faster, for the whole run.
- **Tight `--max-turns`.** A phase that exceeds its budget should end and let the driver
  restart it clean. Pushing through a 30-turn phase is slower *and* more expensive than
  two 15-turn phases doing the same work, because every turn re-sends everything before it.

## What does not speed things up

- **Agent teams.** Roughly seven times the tokens for coordination this protocol already
  does in shell, for free, with a git tag as the synchronisation primitive.
- **More lanes than the plan has independence.** `AP_LANES=8` on a plan with two
  independent branches buys two lanes of speed and eight cold caches.
- **Skipping the regression gate.** It is the cheapest insurance in the protocol.
  Discovering at phase 12 that phase 3 broke something is the single most expensive
  outcome available to an unattended run.
