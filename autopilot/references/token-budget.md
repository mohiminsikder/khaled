# Token budget

## Where the tokens actually go

Claude Code re-sends the full conversation on every request. Prefix caching bills the
unchanged part at roughly a tenth of the input rate, so in a long run cost is governed
almost entirely by two things: **how big the context is** and **how often the prefix
stops matching**. The model's own reasoning is usually the smallest slice, and the one
that should never be cut.

Ranked by what they cost in a typical overnight run:

- **Unbounded command output** — `npm test` with no pattern, a full stack trace, `cat`
  on a 2,000-line file. It enters context once and is then re-sent, cached, on every
  subsequent turn of that phase. Largest avoidable cost by a wide margin.
- **Long phases** — a 40-turn phase re-sends its whole history 40 times. Four 10-turn
  phases doing the same work cost dramatically less.
- **Cache invalidation** — switching model or effort mid-session, an MCP server
  connecting or disconnecting, compaction, upgrading Claude Code, or simply a gap longer
  than the TTL. Each one reprocesses everything as uncached input.
- **Re-reading** — the same file read in phases 3, 5 and 7 because nothing recorded what
  it said.
- **Deterministic work done by the model** — commits, status flips, journal lines. Every
  one is a billed turn for something `sed` does for free.
- **Narration** — explaining what is about to happen, then what just happened.

## The levers, and what each is worth

Enforced by the harness — the model cannot forget these:

| Lever | Mechanism | Rough share of a run's tokens |
|---|---|---|
| Bounded command output | `PreToolUse` hook rewrites the command before it runs | 15–25% |
| Deterministic steps in shell | driver does checkpoint/verify/commit/journal | 15–20% |
| Context packs | driver pre-greps instead of the model searching | 10–15% |
| Dropped tool definitions | bare-name deny removes them from the system prompt | 3–6% |
| No compaction, ever | `autoCompactEnabled: false`, phases sized to fit | 5–10% |
| No idle turns | goal check-ins off, `crossSessionInbound: hold` | 0–10% |
| Cache TTL held at 1h | `promptCacheTtl` survives the drop to usage credits | 5–15% |
| Thinking budget by class | `MAX_THINKING_TOKENS` on `mech` phases only | 3–8% |

These overlap — you cannot add the column up and believe it. On the runs this was built
for, the combination lands somewhere between half and two thirds off a naive long
session. **Measure your own** with `scripts/autopilot-report.sh`, which reads the actual
token counts out of the driver's JSON results. A number you measured beats a number
someone quoted at you.

## Per-phase budget

| Phase type | Target turns | Warning sign |
|---|---|---|
| Preflight | 3–5 | probing services deeply instead of shallowly |
| Plan | 5–10 | reading source to plan — plan from structure, refine per phase |
| `mech` edit + verify | 4–8 | reading files the phase does not name |
| `logic` edit + verify | 6–12 | still exploring after turn 8 |
| Refactor + verify | 12–20 | still exploring after turn 8 |
| Debug a failing verify | 10–20 | attempt 3 with no new hypothesis |

Over budget: finish the current edit, write `STATE.md`, end the turn. The driver picks it
up with a clean context. This is strictly cheaper than pushing through.

## Cache mechanics worth knowing

The prefix is matched exactly, from the start of the request. Claude Code orders it so
the stable parts come first — system prompt and tool definitions, then project context
(CLAUDE.md), then the conversation. A change high up invalidates everything below it.

Consequences that shape this protocol:

- **The system prompt embeds the working directory and the git status snapshot.** Two
  sessions in different directories never share a cache — including two worktrees of the
  same repo. Consecutive sessions in one directory share a prefix only while the branch
  and recent commits still match, which per-phase commits will eventually move. Small
  per-phase contexts are what make that affordable.
- **Each model has its own cache.** Routing a phase to Haiku does not warm Sonnet's
  cache. That is fine here — each phase is its own invocation — but it is why you should
  not switch models *inside* a session.
- **`/compact` costs the most exactly when you most want it**, because it sends the
  conversation it is summarizing. `/clear` costs nothing. Never compact; clear and
  re-ground from `STATE.md`.
- **Denying a tool by bare name** (`WebFetch`, not `Bash(rm *)`) removes its definition
  from the system prompt entirely. Scoped rules like `Bash(npm test:*)` are checked at
  call time and leave the prefix alone, so a long allow list is not itself a cost.
- **Subagents get a five-minute TTL by default**, even on a subscription, and never read
  the parent's cache. Set `subagentPromptCacheTtl` if you delegate heavily.

Check the real numbers rather than assuming: `cache_read_input_tokens` should dominate
`cache_creation_input_tokens`. If creation stays high phase after phase, something in
the prefix is moving.

## Concrete rules

```bash
# no                          # yes
cat src/services/payment.ts   grep -n "processRefund" src/services/payment.ts
                              sed -n '120,180p' src/services/payment.ts
npm test                      npm test -- payment 2>&1 | tail -30
git log                       git log -n 5 --oneline
grep -r "handler" .           grep -rn "handler" src/ --include='*.ts' | head -20
```

The output-clamp hook applies most of the right column automatically, but write them
anyway — the hook is a floor, not a substitute for aiming.

- Read with `offset`/`limit` once you know the line range. Whole-file reads are for files
  under ~100 lines.
- Never re-read a file already read this phase.
- Batch independent tool calls into one turn.
- Patch; do not rewrite unless the edit touches most of the file.
- Anything discovered the expensive way goes in `STATE.md` gotchas, so it is never
  discovered expensively again.

## The line not to cross

Terseness is for reading and output. It is not for reasoning.

Cutting thinking on a hard debug looks like a saving on the turn it happens and is not:
the wrong fix produces a failed verify, a rollback, and a blocked phase, which costs
several times what the thinking would have. The whole point of externalising state is to
buy room to think — spend it.

The more irreversible the step, the more thinking it deserves. A migration, a schema
change, or a public API signature is worth ten turns of care. Renaming a local variable
is not.
