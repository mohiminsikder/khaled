#!/usr/bin/env bash
# PreCompact. Under this protocol compaction should never happen — phases are
# sized to fit one clean context and autoCompactEnabled is false. If it fires,
# the phase was too big, which is worth knowing in the morning.
#
# It does not block compaction. Blocking would strand the session with a full
# context and no way forward, which is worse than a lossy summary.
#
# Install to .claude/hooks/pre-compact.sh and chmod +x.

set -uo pipefail
input=$(cat)
AP="${CLAUDE_PROJECT_DIR:-$(pwd)}/.autopilot"
mkdir -p "$AP"

trigger=$(printf '%s' "$input" | jq -r '.trigger // "auto"')
echo "- [$(date '+%F %T')] COMPACT($trigger) fired — a phase was oversized, split it" >> "$AP/JOURNAL.md"
"${CLAUDE_PROJECT_DIR:-$(pwd)}/scripts/notify.sh" "Autopilot: compaction fired — a phase is too big" >/dev/null 2>&1 || true

jq -nc '{
  hookSpecificOutput: {
    hookEventName: "PreCompact",
    additionalContext: "Compaction is about to run. Ensure .autopilot/STATE.md holds the goal, active constraints, decisions with their reasons, and the current phase. Anything not written there is about to be lost."
  }
}'
