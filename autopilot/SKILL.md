---
name: autopilot
description: Run a long, unattended Claude Code session that keeps working with nobody at the keyboard — plans the work into verifiable phases, self-tests every phase, checkpoints to git, survives usage-limit exhaustion and power loss at near-zero token cost, rotates between Claude accounts without a terminal prompt, runs independent phases in parallel, and notifies within 60 seconds if it needs an access it does not have. Use this skill whenever the user wants work to continue overnight, while they sleep, hands-free, unattended, "without me typing anything", or asks for a self-verifying multi-phase build. Also use it when the user complains about burning tokens on long sessions, about sessions dying at compaction, about having to switch Claude accounts mid-run when quota is exhausted, or about losing a run to a power cut.
---

# Autopilot

An unattended-run protocol. The goal is a session that finishes real work correctly
while nobody is watching, fails loudly and early instead of silently and late, and
loses nothing when the account runs out of quota or the power goes out.

## The one idea that makes this work

**Context is disposable. Files are memory. The driver is the state machine.**

Every property below falls out of that single choice:

| Because state lives in files, not context… | …you get |
|---|---|
| a phase starts from a small clean context | compaction never triggers, so it is never lossy |
| an interrupted run re-reads files, not a transcript | a power cut costs one phase, not the run |
| an account switch carries no history forward | the cold-cache re-read is a few thousand tokens, not a few hundred thousand |
| the driver knows the phase graph | independent phases run in parallel |
| verify is a command, not a judgement | quality is checked by machine on every phase |

Quality is lost by *forgetting the plan*, not by using fewer tokens. That is why
"fewest tokens" and "no drop in quality" are not a tradeoff here. Rewriting a state
file is cheap; recovering from a bad compaction is not.

**Never compact.** `/compact` sends the whole conversation to summarize it, so it costs
the most exactly when context is largest, and it silently drops the constraint from
phase 2 that phase 7 needs. `/clear` costs nothing. This protocol clears and re-grounds
from `STATE.md` instead of compacting, always.

## Phase 0 — PREFLIGHT (hard cap: 60 seconds)

A run that will be blocked at 3am should say so at 11pm.

1. Read `.autopilot/ACCESS.yml`. If absent, create it by scanning `.env.example`,
   `docker-compose.yml`, CI configs, `package.json` scripts, and `process.env.X` /
   `getenv('X')` references. List every credential, service, database, and endpoint
   the task plausibly needs.
2. Probe each cheaply and in parallel: env var set? host reachable? command on PATH?
   token still valid? Shallow checks only — 60 seconds is the budget.
3. Write every failure to `.autopilot/BLOCKERS.md` and fire `scripts/notify.sh`.
4. Decide, and record it:
   - Blocker affects **all** phases → write `.autopilot/HALT`, notify, stop.
   - Blocker affects **some** phases → mark those `status: blocked` in `PLAN.md` and
     continue with the rest. Do not stall the whole night on one missing key.

Also confirm at least one account profile has quota (`scripts/lib/accounts.sh probe`).
Starting a 6-hour run on a profile with 20 minutes left is a preventable failure.

Never wait on an interactive prompt. There is nobody there. If a step cannot proceed
without a human, it is a blocker — record it, notify, route around it.

## Phase 1 — PLAN

Write `.autopilot/PLAN.md` once. Every phase needs a `verify` command, because a phase
with no machine-checkable success condition cannot be self-tested and will be marked
done on vibes.

```markdown
## P3 — Add rate limiting to the API
depends: P1
files: src/middleware/rate-limit.ts, src/server.ts
class: logic
done_when: 11th request inside 60s returns 429
verify: npm test -- rate-limit
rollback: git checkout -- src/middleware/rate-limit.ts src/server.ts
status: pending
```

`class:` drives model routing and thinking budget — it is what buys the speed:

| class | Model | Thinking | Use for |
|---|---|---|---|
| `mech` | haiku | low | boilerplate, renames, config, scaffolds, docs, codegen |
| `logic` | sonnet | full | real implementation, anything with branching behaviour |
| `hard` | opus | full | schema/API design, concurrency, security-sensitive work |

A phase may only be `class: mech` if its `verify` is a real test, typecheck, or lint
that would *catch a mistake*. Routing must never be able to lower quality silently —
and it cannot, because a failed `mech` verify is automatically re-run on sonnet before
the phase is allowed to block. The worst case is one wasted cheap attempt.

**Sizing rule:** a phase should fit comfortably in one clean context — one focused
change plus its test. If it would need compaction to finish, it is two phases.

**Disjointness rule:** phases that touch no common file and share no dependency edge
will be run *in parallel*, so `files:` must be accurate. Overlapping `files:` between
concurrent phases is the one plan error that produces a merge conflict at 3am.

If no meaningful `verify` exists for a phase, write one first as its own phase. An
unverifiable phase in an unattended run is just an unreviewed diff.

## Phase 2..N — the loop

The driver, not the model, runs steps 1, 3, 5, 6 and 7. That is deliberate: they are
deterministic, and every one moved out of the model is turns not spent and tokens not
billed. The model is asked for one thing only — the diff.

1. **Skip if already done.** The driver runs `verify` *before* executing. If it passes,
   the work already exists (a crash after the edit but before the status flip), so the
   phase is marked done for zero model tokens. This is what makes a power cut cheap.
2. **Checkpoint.** `git tag autopilot/pre-P<n>` plus a commit. Unattended runs need a
   floor to fall back to.
3. **Pack.** The driver greps the phase's `files:` and writes the relevant spans to
   `.autopilot/pack/P<n>.md`. The model starts already knowing where the code is
   instead of spending four turns finding out.
4. **Execute.** Only the files named in the phase. Touching files outside `files:`
   means the plan was wrong — update `PLAN.md` and say so in the journal rather than
   silently widening the blast radius.
5. **Verify.** The driver runs the phase's `verify`. Exit 0 or it did not pass. This is
   not satisfied by reading the code and concluding it looks right.
6. **On failure:** retry at most twice, and only with a *named, different* cause. A
   third attempt with no new hypothesis is thrashing — it burns budget and usually
   makes the diff worse. Attempt 2 escalates the model one tier. After two failures:
   run `rollback`, set `status: blocked`, append 20 lines of failing output to
   `BLOCKERS.md`, notify, move to the next independent phase.
7. **Regression gate.** Every 3rd phase and before the final one, run the full suite.
   Per-phase verification catches local breakage; only a full run catches phase 3
   breaking phase 1.
8. **Record and reset.** ≤3 lines to `JOURNAL.md`, flip `status:`, commit, end the turn.
   The next phase starts from a clean context.

## Staying alive: quota, accounts, and power

This is the part that separates a run that survives the night from one that dies at 2am.
Full mechanics in `references/resilience.md`; the shape of it:

- **Usage limit hit.** Claude Code's built-in "wait for the limit to reset" is *not
  offered in `-p` runs*, so the driver implements it. On exhaustion it rotates to the
  next account profile at a **phase boundary** — never mid-phase — via
  `CLAUDE_CONFIG_DIR`, which needs no `/login` and no terminal. If no profile has quota,
  it sleeps until the reset time. A sleeping process bills nothing.
- **Why switching accounts used to cost so much.** A handover drops the prompt cache TTL
  from an hour to five minutes and starts a cold cache in the new organization, so the
  next turn reprocesses the entire conversation as uncached input. Under this protocol
  there *is* no long conversation to reprocess — each phase carries a few thousand
  tokens of curated state. The switch stops being an event.
- **Power loss.** Every phase boundary is a git tag, a `CURSOR` file, and a status flip.
  Restart re-reads them with shell only — **zero model tokens** — and step 1 above skips
  any phase whose work already landed. Install the systemd unit and the run resumes on
  boot with nothing typed.
- **Detection is deterministic.** A `StopFailure` hook matching `rate_limit`,
  `authentication_failed`, and `billing_error` writes a marker file. The driver reads
  the marker rather than grepping stdout for message strings that change between
  releases.

## Token discipline

Terseness applies to *reading and output*, never to reasoning. Skimping on thinking
during a tricky debug is the one economy that costs more, because a wrong fix means
another phase. Spend on judgement; save everywhere else.

The structural savings are enforced by the harness, not by the model remembering:

- A `PreToolUse` hook rewrites unbounded commands before they run — `npm test` gains a
  `| tail -80`, `cat` becomes a ranged `sed -n`, `git log` gains `-n`. Unbounded output
  is the single largest avoidable cost in long runs, and this removes it by construction.
- Unused tool definitions are denied by bare name, which drops them out of the system
  prompt on *every* request for the rest of the run.
- The driver does the deterministic work, so those turns are never billed.

What the model still owes:

- Grep/Glob to locate, then read with `offset`/`limit`. Never read a whole file to find
  one function.
- Never re-read a file already read this phase.
- Batch independent tool calls into one turn.
- No narration. No "Now I will…", no summary of what just happened, no restating the
  file you just wrote. The journal is the record; nobody reads the transcript.
- Patch; rewrite a whole file only when the edit touches most of it.

`references/token-budget.md` has the per-phase budget, the measured value of each lever,
and how to check your real cache hit rate rather than assuming one.

## State files — write these, trust these

Everything lives in `.autopilot/` (gitignored except `PLAN.md`):

| File | Holds | Rewritten |
|---|---|---|
| `PLAN.md` | phases, verify commands, class, status | on status change |
| `STATE.md` | ≤200 lines: goal, constraints, decisions + *why*, current phase, gotchas | end of every phase |
| `JOURNAL.md` | append-only, ≤3 lines per phase | every phase |
| `BLOCKERS.md` | access gaps and failed phases | on blocker |
| `ACCESS.yml` | what the run needs to reach | preflight |
| `CURSOR` | phase in flight — the crash-recovery anchor | before every phase |
| `QUOTA` | quota/auth stop marker written by the hook | on limit |
| `HALT` | presence stops the driver | on fatal |

`STATE.md` is the one that matters — it is what the next phase wakes up knowing. Record
decisions *with their reason*: "chose Postgres advisory locks over Redis because Redis
is not in the deploy target" survives a context reset; "chose Postgres" does not, and a
later phase will helpfully re-introduce Redis.

## Blockers and notification

Anything needing a human — a missing credential, a permission denial, an ambiguous
product decision that would change the plan's shape — goes to `BLOCKERS.md` **and**
fires `scripts/notify.sh` immediately. Not at the end of the run. The user is asleep and
the notification is the only channel; one that arrives at 3am beats a run that stalled
at 3am and said so at 8am.

Then keep going on whatever is still unblocked.

## Halt conditions

Write `HALT` and notify only for these:

- Every remaining phase is blocked.
- The full suite was green at session start and is red after a rollback — the tree is in
  a state the protocol cannot recover.
- A phase needs a destructive or irreversible action outside the allow rules:
  force-push, production deploy, `DROP`/`TRUNCATE`, secret rotation, real email or
  payments. These need a human even where the permission system would allow them.
- Budget, phase, or turn cap reached.
- Every account profile is exhausted *and* the earliest reset is more than
  `AP_MAX_WAIT_HOURS` away.

An ambiguous product decision is a *blocker*, not a halt. Record the options and what
each implies, take the reversible one if there is one, flag it, and move on — the user
can redirect in the morning. `references/phase-protocol.md` has the full decision table.

## Setup

`scripts/autopilot-run.sh` is the driver. `scripts/install.sh` wires a project up in one
command. `assets/settings.snippet.json` holds the permission rules, hooks, and
cost-control settings that keep a run non-interactive without handing over blanket
approval.

Read `references/setup.md` before the first run — it covers the permission model and the
tradeoff you are making by running unattended at all. Read `references/resilience.md`
before the first *overnight* run, and set up at least two account profiles; that is what
turns a 2am quota wall from the end of the run into a 30-second pause.
