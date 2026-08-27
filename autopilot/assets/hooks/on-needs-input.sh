#!/usr/bin/env bash
# Notification(permission_prompt|idle_prompt|agent_needs_input). These are
# exactly the states where an unattended run has silently stopped making
# progress, which is worse than failing.
#
# Install to .claude/hooks/on-needs-input.sh and chmod +x.

set -uo pipefail
input=$(cat)
AP="${CLAUDE_PROJECT_DIR:-$(pwd)}/.autopilot"
mkdir -p "$AP"

msg=$(printf '%s' "$input" | jq -r '.message // "session is waiting for input"' | head -c 200)
echo "- [$(date '+%F %T')] WAITING: $msg" >> "$AP/BLOCKERS.md"
"${CLAUDE_PROJECT_DIR:-$(pwd)}/scripts/notify.sh" "AUTOPILOT WAITING: $msg" >/dev/null 2>&1 || true
exit 0
