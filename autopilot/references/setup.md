# Setup

## Install

```bash
# 1. The skill, user-level so every project has it
mkdir -p ~/.claude/skills
cp -r autopilot ~/.claude/skills/

# 2. Per project you want to automate
cd /path/to/project
~/.claude/skills/autopilot/scripts/install.sh          # add --systemd to survive reboots

# 3. Verify the notification channel BEFORE you trust it overnight
./scripts/notify.sh "test — you should see this on your phone"
```

Requires `jq` and `git`. The installer merges into an existing `.claude/settings.json`
rather than clobbering it, but a `jq` merge replaces arrays wholesale — check the
resulting `permissions.allow` before the first run.

## The permission decision, honestly

This is the only irreversible choice here, so it is worth slowing down on.

`--dangerously-skip-permissions` makes every unattended run work first try. It also
means that at 3am, with nobody watching, a confused model can run any command the process
has rights to. The damage is rarely dramatic — it is a `git checkout` over uncommitted
work, an `rm` in the wrong directory, a migration against the wrong `DATABASE_URL`
because the env came from your shell.

So the driver uses `--permission-mode dontAsk`: anything not in `permissions.allow` is
denied, and the denial fires a hook that logs it and notifies you within seconds. The
cost is real — your first two or three runs will hit denials for legitimate commands and
you will widen the list. That is the tuning cost, and it is worth paying once.

Widen it by adding the *specific command* that was denied, not the tool: `Bash(npm run
migrate:*)`, never `Bash`. The allow list is the actual safety boundary of the whole
system; everything else here is convention the model can drift from.

Two more layers before the first overnight run:

- **A branch.** `git checkout -b autopilot/$(date +%F)`. The per-phase commits give you a
  clean history to review and `git reset --hard` to abandon.
- **A separate database.** If any phase touches a DB, point `DATABASE_URL` at a scratch
  copy. Unattended plus production is the combination that produces the bad stories.

### Why the deny list also has bare tool names

`WebSearch`, `WebFetch`, and `NotebookEdit` are denied by bare name, which does something
scoped rules do not: it removes the tool definition from the system prompt entirely, on
every request, for the whole run. Scoped rules like `Bash(rm *)` are checked when a call
is attempted and leave the prompt alone — so a long allow list costs nothing, while a
short bare-name deny list saves on every single request.

If your work genuinely needs to fetch documentation, drop `WebFetch` from the deny list
and accept the tokens. Do not leave it denied and then wonder why a phase is blocked.

## Two accounts, before the first overnight run

The single highest-value setup step. A run that hits a usage wall at 2am with one
account configured stops until the reset; with two, it pauses for about thirty seconds.

```bash
./scripts/accounts-add.sh acct2      # opens Claude Code; type /login, then /exit
./scripts/accounts-add.sh --check    # proves both accounts answer before you rely on them
```

It must be a **different Claude account** — a different email with its own
subscription. The same account signed in twice shares one usage limit, so it buys
nothing. That is the one setup mistake that looks fine until 2am.

The helper just edits `.autopilot/accounts.env`, which you can also write yourself:

```bash
AP_PROFILES="default ~/.claude-acct2"
```

`references/resilience.md` covers the rotation logic, the zero-cost wait, and why an
account switch used to cost so much.

## Running it

```bash
# overnight
nohup ./scripts/autopilot-run.sh "Migrate the checkout flow to the new payment API,
with tests, without breaking the existing coupon logic" > .autopilot/run.log 2>&1 &

# or under systemd, so a reboot resumes it by itself
systemctl --user start autopilot

# or in tmux so you can attach and watch
tmux new -s autopilot './scripts/autopilot-run.sh "..."'

# stop it from anywhere
touch .autopilot/HALT
```

### Knobs

| Variable | Default | What it does |
|---|---|---|
| `AP_LANES` | `3` | Parallel worktree lanes. `1` = sequential and cheapest per token |
| `AP_MAX_TURNS` | `25` | Per phase. A phase needing more is too big |
| `AP_MAX_PHASES` | `60` | Loop cap |
| `AP_MAX_BUDGET_USD` | unset | Per-invocation spend cap. Set it on the first few runs |
| `AP_FULL_SUITE` | unset | Regression command, e.g. `npm test`. **Set this** — it is the quality gate |
| `AP_REGRESSION_EVERY` | `3` | Phases between full-suite runs |
| `AP_PUSH_BRANCH` | unset | Push after every phase. Set it if the power is unreliable |
| `AP_MODEL_LOGIC` | `sonnet` | The workhorse |
| `AP_MODEL_MECH` | `haiku` | Mechanical phases |
| `AP_MODEL_HARD` | `opus` | Design and escalations |

`AP_FULL_SUITE` is the one people skip and should not. Per-phase verification catches
local breakage; only a full run catches phase 3 breaking phase 1, and that is the
failure that turns an overnight run into a morning of archaeology.

## In the morning

```bash
./scripts/autopilot-report.sh    # phases, real token counts, cache hit rate, journal
git log --oneline autopilot/…    # the actual diffs, phase by phase
```

Read `BLOCKERS.md` first. A run that completed every phase but hit four denials tells you
more about what to fix than the diffs do.

The report prints your measured cache hit rate. If `cache_write` stays high phase after
phase, something in your prefix is moving and the token savings are not landing —
`references/token-budget.md` lists the usual causes.

## What this does not solve

- **Bad plans.** If Phase 1 produces a wrong decomposition, every phase after it
  faithfully executes the wrong thing. Read `PLAN.md` before the first overnight run of
  any large task. Two minutes there is the highest-leverage review in the whole system.
- **Inaccurate `files:` lists.** Parallelism is only as safe as the plan's disjointness
  claim. The driver aborts a conflicting merge and re-runs sequentially, so it degrades
  rather than corrupts — but it costs the time parallelism saved. Set `AP_LANES=1` on a
  plan you do not trust.
- **Genuinely ambiguous product decisions.** The protocol routes around them and flags
  them, which is right, but it means the morning review is not optional.
- **A weekly limit with one account.** Sleeping until a reset days away is not a
  strategy. That is what the second profile and the optional API-key fallback are for.
