#!/usr/bin/env bash
# SessionStart(startup|resume|compact). Re-grounds every session in the curated
# state file instead of whatever survived a summary.
#
# This is the cheap half of the token strategy: ~200 lines of deliberate state
# beats several thousand tokens of transcript, and it is more accurate, because
# it was written on purpose rather than salvaged under pressure.
#
# Install to .claude/hooks/inject-state.sh and chmod +x.

set -uo pipefail
AP="${CLAUDE_PROJECT_DIR:-$(pwd)}/.autopilot"
[[ -f "$AP/STATE.md" ]] || exit 0

ctx=$(head -c 8000 "$AP/STATE.md")   # additionalContext is capped at 10,000

if [[ -s "$AP/BLOCKERS.md" ]]; then
  ctx="${ctx}

Open blockers — do not retry these, route around them:
$(tail -15 "$AP/BLOCKERS.md")"
fi

jq -nc --arg c "$ctx" '{
  hookSpecificOutput: {
    hookEventName: "SessionStart",
    additionalContext: ("Autopilot run in progress. Current state:\n\n" + $c)
  }
}'
