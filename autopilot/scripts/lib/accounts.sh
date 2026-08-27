#!/usr/bin/env bash
# accounts.sh — rotate between Claude accounts without a terminal prompt.
#
# CLAUDE_CONFIG_DIR relocates settings, CREDENTIALS, and session history. One
# directory per account means switching accounts is an environment variable
# rather than an interactive /login — which is the whole point, because there is
# nobody at the keyboard to run /login at 3am.
#
# One-time, per extra account:
#   CLAUDE_CONFIG_DIR=~/.claude-acct2 claude      # /login, then /exit
#
# Then list them in .autopilot/accounts.env:
#   AP_PROFILES="default ~/.claude-acct2 ~/.claude-acct3"
#
# Keep hooks and permissions in the PROJECT .claude/settings.json, not in
# ~/.claude/settings.json — user settings live inside CLAUDE_CONFIG_DIR, so a
# rotation would otherwise silently change your allow list too.

# shellcheck disable=SC1091
[[ -f "$AP/accounts.env" ]] && source "$AP/accounts.env"

: "${AP_PROFILES:=default}"
: "${AP_MAX_WAIT_HOURS:=14}"

acct_list() { printf '%s\n' $AP_PROFILES; }

acct_current() { cat "$AP/.acct" 2>/dev/null || acct_list | head -1; }

acct_set() { printf '%s' "$1" > "$AP/.acct"; }

# Export the environment for a profile. "default" means the normal ~/.claude,
# so you never have to duplicate your main account into a second directory.
acct_env() {
  local p="${1:-$(acct_current)}"
  if [[ "$p" == "default" ]]; then
    unset CLAUDE_CONFIG_DIR
  else
    export CLAUDE_CONFIG_DIR="${p/#\~/$HOME}"
  fi
}

# Next profile in rotation order; empty when the list is exhausted.
acct_next() {
  local cur="${1:-$(acct_current)}" seen=0 p
  while read -r p; do
    [[ $seen -eq 1 ]] && { printf '%s' "$p"; return 0; }
    [[ "$p" == "$cur" ]] && seen=1
  done < <(acct_list)
  return 1
}

# A three-token prompt. Cheap enough to use as a liveness check; a phase retry
# against a still-dry account is not.
acct_probe() {
  local p="$1" out
  ( acct_env "$p"
    out=$(claude -p --max-turns 1 --output-format text "ok" 2>&1)
    [[ "$out" == *"limit"* || "$out" == *"429"* || "$out" == *"Credit balance"* ]] && exit 1
    exit 0 )
}

# Reset time, if the quota marker captured one. Prints epoch seconds.
quota_reset_epoch() {
  local raw when
  raw=$(grep -oE 'resets [^·|]+' "$AP/QUOTA" 2>/dev/null | head -1 | sed 's/^resets //' | xargs)
  [[ -n "$raw" ]] || return 1
  when=$(date -d "$raw" +%s 2>/dev/null) || return 1
  # A bare clock time already past today means tomorrow.
  [[ "$when" -lt "$(date +%s)" ]] && when=$(date -d "$raw tomorrow" +%s 2>/dev/null) || true
  printf '%s' "$when"
}

# Called at a phase boundary when .autopilot/QUOTA exists.
# Returns 0 to retry the phase, 1 to halt the run.
acct_handle_quota() {
  local reason cur nxt reset now wait_s
  reason=$(head -c 200 "$AP/QUOTA" 2>/dev/null)
  cur=$(acct_current)
  log "quota stop on profile '$cur': $reason"

  if nxt=$(acct_next "$cur"); then
    acct_set "$nxt"
    journal "quota — '$cur' dry $(date '+%H:%M'), rotated to '$nxt'"
    notify "Autopilot: '$cur' out of quota, switched to '$nxt' — run continues"
    rm -f "$AP/QUOTA"
    return 0
  fi

  # Every subscription profile is dry. Metered billing has no reset to wait for,
  # so an API key is what finishes the run at 4am.
  if [[ -n "${AP_API_FALLBACK_KEY:-}" && ! -f "$AP/.api-fallback-used" ]]; then
    touch "$AP/.api-fallback-used"
    export ANTHROPIC_API_KEY="$AP_API_FALLBACK_KEY"
    unset CLAUDE_CONFIG_DIR
    acct_set "api-fallback"
    notify "Autopilot: all profiles dry, falling back to API key (cap \$${AP_API_FALLBACK_BUDGET_USD:-5})"
    rm -f "$AP/QUOTA"
    return 0
  fi

  # Sleep to the reset. A sleeping shell bills nothing — this is the piece
  # Claude Code cannot do for us, because the built-in wait is not offered
  # in -p runs.
  now=$(date +%s)
  if reset=$(quota_reset_epoch); then
    wait_s=$(( reset - now + 60 ))
    [[ $wait_s -lt 60 ]] && wait_s=60
    if [[ $wait_s -gt $(( AP_MAX_WAIT_HOURS * 3600 )) ]]; then
      blocker "HALT: all profiles exhausted, earliest reset $(date -d "@$reset" '+%F %H:%M') is beyond AP_MAX_WAIT_HOURS=$AP_MAX_WAIT_HOURS"
      return 1
    fi
    notify "Autopilot: all profiles dry. Sleeping until $(date -d "@$reset" '+%H:%M'), then continuing. Costs nothing while it waits."
    log "sleeping ${wait_s}s until quota reset"
    sleep "$wait_s"
  else
    # No parseable reset time: wait, then probe. Never retry the phase to find out.
    notify "Autopilot: all profiles dry, no reset time given. Probing every 20 min."
    local tries=0
    while (( tries < 36 )); do
      sleep 1200
      acct_set "$(acct_list | head -1)"
      if acct_probe "$(acct_current)"; then
        log "probe succeeded, quota is back"
        break
      fi
      tries=$(( tries + 1 ))
    done
    (( tries >= 36 )) && { blocker "HALT: quota never returned after 12h of probing"; return 1; }
  fi

  acct_set "$(acct_list | head -1)"
  rm -f "$AP/QUOTA"
  return 0
}
