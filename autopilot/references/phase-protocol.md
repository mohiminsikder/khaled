# Phase protocol — decision reference

Read this when a phase does not go cleanly. `SKILL.md` covers the happy path.

## Decision table

| Situation | Do |
|---|---|
| `verify` passes | Mark done, commit, next phase |
| `verify` passed *before* the phase ran | The work already landed (crash before the status flip). Mark done. Zero model tokens |
| `verify` fails, cause identified | Fix, re-run verify (max 2 attempts, escalate model on attempt 2) |
| `verify` fails, no specific hypothesis | Stop. Rollback, mark blocked, notify, next phase |
| `verify` fails on a `mech` phase | Re-run once on sonnet before blocking — it may just be misclassified |
| `verify` command itself is broken | Fix the verify command as its own micro-phase, then retry the phase once |
| Phase needs a file outside `files:` | Update `PLAN.md`, note it in the journal, continue. If lanes are running, this is urgent — the disjointness claim is now wrong |
| Phase needs a credential you do not have | Blocker. Log, notify, mark blocked, next phase |
| Permission denied by policy | Blocker. Do not retry — the hook already notified. Next phase |
| Usage limit / auth failure | Not a blocker. Roll back to the phase tag, rotate profile or sleep to reset, retry the same phase |
| Two plausible designs, both reversible | Take the simpler one, record the choice *and the discarded option* in `STATE.md`, continue |
| Two plausible designs, one irreversible | Blocker. Log both with their implications, next phase |
| Full suite red after a rollback | HALT. The tree is in a state the protocol cannot recover |
| Every remaining phase blocked | HALT |
| Rate limit / network error | Not a blocker. The driver backs off and retries |
| Over the turn budget | Finish the current edit, write `STATE.md`, end the turn. The driver resumes it with a clean context |
| Two lanes conflict on merge | Both roll back. Re-run sequentially. Fix the `files:` lists in `PLAN.md` first |

## Why "no hypothesis, no third attempt"

The failure mode that ruins unattended runs is not stopping too early — it is a model
attempting the same fix with cosmetic variations for twenty turns. It burns the budget,
leaves the diff worse than when it started, and hands you a mess that takes longer to
review than the original task would have taken to do.

Two attempts with distinct, articulable hypotheses is generous. If you cannot say *why*
this attempt differs from the last, the honest move is to roll back and flag it. A
blocked phase with a clean tree and a clear note is a good outcome at 3am. A half-fixed
phase is not.

## Writing STATE.md

Target 200 lines.

```markdown
# Goal
<one paragraph, unchanged all run>

# Constraints
- must not break the existing coupon logic
- Node 18, no new runtime deps
- deploy target has no Redis

# Decisions
- P2: advisory locks, not Redis — Redis is not in the deploy target
- P4: kept the legacy /v1 route as a shim — external clients still call it
- P5: rejected batching the webhook writes — ordering matters for refunds

# Current
P6 in progress. P3 blocked (missing STRIPE_SECRET_KEY). P7/P8 running in lanes.

# Gotchas
- test suite needs `npm run db:seed` first or 4 tests fail spuriously
- src/legacy/ is generated — edit tools/gen.ts instead
```

**Decisions** is the section that earns its keep. Each line stops a future phase from
re-litigating something already settled — and, more importantly, from *reversing* it,
which is the specific way long unattended runs produce incoherent output. A decision
without its reason will be undone by a later phase with a good local argument for
undoing it.

**Gotchas** is second. Anything that cost turns to discover goes here so it costs zero
turns next time.

## Journal entries

Three lines maximum. This is a record for a human skimming at 8am, not a transcript.

```
P4 done (4m, haiku) — coupon guard in checkout.ts, verify green
P5 blocked — STRIPE_SECRET_KEY unset, rolled back, notified 02:14
P6 done (11m, sonnet) — webhook handler refactor, full suite green
P7 quota — acct1 dry 02:40, rotated to acct2, resumed 02:41
```
