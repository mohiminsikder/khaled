# Resilience — quota, accounts, and power loss

Two things end unattended runs: the account runs out of quota, and the machine loses
power. Both are survivable at near-zero cost, but only if the run was built for it.
This is how.

## Why an account switch used to cost so much

The expensive part is not the switch. It is what the switch does to the prompt cache.

Claude Code re-sends your entire conversation on every request and relies on prefix
caching to bill the unchanged part at roughly a tenth of the input rate. Three
documented facts about that cache decide everything here:

1. **Caches are isolated between organizations.** A different account is a different
   cache. The first request after a switch matches nothing.
2. **The one-hour TTL is a subscription-within-plan-usage benefit.** The moment you go
   over your limit and start drawing on usage credits, the main conversation drops to a
   five-minute TTL. You can hold the hour with `promptCacheTtl`.
3. **A handover ends the built-in wait.** Switching accounts with `/login`, resuming
   another session, or teleporting the conversation all end the automatic
   "continue when the limit resets" wait, because the conversation changed hands.

So the classic failure is: you work a long session, hit the limit, `/login` to the other
account, and the very next turn reprocesses six hours of conversation as *uncached*
input against a fresh org. That single turn is the huge chunk you noticed. It scales
with how long the session has been open, which is why it feels worse the later it
happens.

**The fix is structural, not a setting.** Under this protocol there is no long
conversation to reprocess. Each phase is its own `-p` invocation carrying a curated
`STATE.md` and a context pack — a few thousand tokens. Switching accounts between
phases re-warms a cache that small. The switch stops being an event you can feel.

The settings below are the second-order savings on top of that.

## Account profiles — switching with no terminal, no `/login`

`CLAUDE_CONFIG_DIR` relocates everything Claude Code keeps in `~/.claude`: settings,
**credentials**, session history, plugins. One directory per account is one account per
directory, and the driver picks between them with an environment variable.

### One-time setup, per account

The helper does all of this and verifies the result:

```bash
./scripts/accounts-add.sh acct2     # opens Claude Code, you type /login then /exit
./scripts/accounts-add.sh --check   # confirms both accounts actually answer
```

**The second account must be a genuinely different Claude account** — a different
email with its own subscription. Signing the same account in twice buys nothing,
because the usage limit belongs to the account, not to the terminal window. This is
the one setup mistake that looks like it worked and then does not help at 2am.

By hand, if you prefer:

```bash
# Account 2. Do this once, interactively — it is the only interactive step.
CLAUDE_CONFIG_DIR=~/.claude-acct2 claude
#   /login  -> sign in as the second account
#   /exit

# Account 3, if you have one
CLAUDE_CONFIG_DIR=~/.claude-acct3 claude   # /login, /exit
```

Then declare them, cheapest-first, in `.autopilot/accounts.env`:

```bash
# Rotation order. The driver walks this list top to bottom.
AP_PROFILES="default ~/.claude-acct2 ~/.claude-acct3"

# Optional last-resort profile. An API key has no reset window to wait for —
# it just bills — so it is the thing that finishes the run at 4am when every
# subscription is dry. Leave it unset if you do not want that.
AP_API_FALLBACK_KEY=""
AP_API_FALLBACK_BUDGET_USD="5"
```

`default` means the normal `~/.claude`. The literal string is special-cased, so you do
not have to duplicate your main account into a second directory.

### Why the hooks still work after a switch

`CLAUDE_CONFIG_DIR` moves *user*-level settings. Put every autopilot hook and permission
rule in the **project** file, `.claude/settings.json` — the installer does — and they
apply identically no matter which profile is active. A rotation changes the credential
and nothing else. If you put your allow list in `~/.claude/settings.json` instead, the
second profile will start denying commands the first one allowed, which looks exactly
like a mysterious 3am stall.

## What happens when quota runs out

### Detection

Claude Code emits a `StopFailure` hook event whose matcher names the cause. The
installed hook matches `rate_limit`, `authentication_failed`, `billing_error`, and
`oauth_org_not_allowed`, and writes `.autopilot/QUOTA` with the reason and any reset
time it can see.

This matters more than it looks. The alternative is grepping stdout for
`You've hit your session limit`, and those strings change between releases — a driver
that greps them fails silently and invisibly one upgrade later. The hook is a contract.

### Response, in order

The driver reacts **only at a phase boundary**. A phase interrupted mid-edit is rolled
back to its `pre-P<n>` tag first, so a rotation never leaves a half-written file.

1. **Rotate.** Move to the next profile in `AP_PROFILES` and re-run the same phase. Cost
   is one cold cache on a small context — a few thousand tokens. Typically ~30 seconds
   of wall clock, and no human involved.
2. **Wait, if every profile is dry.** Parse the reset time from the marker, sleep until
   then, plus a 60-second margin. **A sleeping shell bills nothing.** This is the piece
   Claude Code cannot do for you here: the built-in wait is explicitly not offered in
   `-p` runs or background sessions, which is exactly what a driver is.
3. **Probe, if no reset time could be parsed.** Sleep 20 minutes, then send a
   three-token prompt to test whether quota is back. A probe costs a rounding error; a
   phase retry against a still-dry account costs a phase. Never retry the phase to find
   out.
4. **Fall back to the API key**, if one is configured, with `--max-budget-usd` set from
   `AP_API_FALLBACK_BUDGET_USD`. Metered billing has no reset to wait for.
5. **Halt** if the earliest reset is further out than `AP_MAX_WAIT_HOURS` (default 14).
   A weekly limit can reset days away; sleeping through that is not a strategy.

Every one of these notifies. You wake up knowing which account is dry and when it
returns, not to a log ending mid-sentence.

### Settings that reduce how often you get here

In the project `.claude/settings.json` — the installer writes these:

```jsonc
{
  "promptCacheTtl": "1h",           // hold the hour even on usage credits (v2.1.242+)
  "subagentPromptCacheTtl": "1h",   // subagents default to 5m even on a subscription
  "autoCompactEnabled": false,      // compaction is a full-context request; never pay it
  "crossSessionInbound": "hold",    // an inbound message wakes a turn that re-sends everything
  "spinnerTipsEnabled": false,
  "env": {
    "CLAUDE_CODE_GOAL_CHECKIN_MINUTES": "0",      // idle check-ins each cost a full-context turn
    "CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC": "1",
    "DISABLE_TELEMETRY": "1",
    "BASH_MAX_OUTPUT_LENGTH": "8000"
  }
}
```

`promptCacheTtl` is the highest-value line here for your specific problem. Without it,
the moment you cross into usage credits your cache lifetime silently drops to five
minutes, and every gap between phases longer than that becomes a full re-read.

If you also work in *interactive* sessions, leave `autoContinueAtUsageLimit` on (its
default). It does nothing for `-p` runs, but it is what makes your own terminal sessions
resume themselves after a reset instead of waiting for you to notice.

## What happens when the power goes out

The design rule: **the driver's state machine is on disk, so recovery needs no model
call at all.** Restarting costs zero tokens. Not "few" — zero. Nothing is sent to a
model until real work resumes.

### What is written, and when

| Written | When | Recovers |
|---|---|---|
| `git tag autopilot/pre-P<n>` | before the phase runs | the exact tree to roll back to |
| `.autopilot/CURSOR` | before the phase runs | which phase was in flight |
| phase commit + `status: done` | after verify passes | that the phase is finished |
| `STATE.md` | end of every phase | what the next phase needs to know |

### The restart path

`./scripts/autopilot-run.sh --resume` does this, in shell, with no API call:

1. Read `CURSOR`. If empty, pick up at the first pending phase.
2. If a phase was in flight, **run its `verify` first**. If it passes, the edit landed
   before the power cut and only the bookkeeping was lost: mark it done, commit, move
   on. Zero model tokens for a phase that was 95% finished.
3. If verify fails, `git reset --hard autopilot/pre-P<n>` and redo that one phase. The
   loss is bounded at one phase, whatever the run's length.
4. Continue the loop normally.

This is why phases are sized small. Phase size is your blast radius for every kind of
interruption at once.

### Coming back with nothing typed

Install the user unit so a reboot resumes the run by itself:

```bash
./scripts/install.sh --systemd
systemctl --user enable --now autopilot.service
sudo loginctl enable-linger "$USER"   # so it starts before you log in
```

`Restart=always` with a 60-second delay also covers the case where the driver itself
dies — an OOM kill, a crashed `claude` process. On a machine without systemd, the
installer offers a `@reboot` crontab line instead.

Two things worth doing before the first overnight run on an unreliable supply:

- **Push after every phase.** Set `AP_PUSH_BRANCH=autopilot/$(date +%F)`. Commits on a
  disk that does not spin up again are not checkpoints. This costs nothing and is the
  difference between losing a night and losing nothing.
- **Run under `tmux`, not a bare shell**, if you might close the terminal. The systemd
  unit makes this moot, but the habit is cheap.

## The interaction worth knowing about

Parallel lanes use git worktrees, and **each worktree is its own working directory, so
it builds its own cache prefix**. Running four lanes means four cold caches instead of
one warm one. On a small per-phase context that is a good trade — you buy most of a 4x
wall-clock speedup for a modest token premium — but it is a real cost, not a free lunch,
and it is why `AP_LANES` defaults to 3 rather than 8.

If a run is token-constrained rather than time-constrained, set `AP_LANES=1`. The
sequential path in a single directory is the cheapest configuration available, because
consecutive phases in the same directory can hit each other's cached prefix whenever the
git status snapshot has not moved.
