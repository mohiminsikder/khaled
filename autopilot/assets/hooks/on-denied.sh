#!/usr/bin/env bash
# PermissionDenied. Fires the moment the run is refused something it wanted.
# This is what makes the 60-second access promise real: it is event-driven, not
# something the model has to remember to report.
#
# Install to .claude/hooks/on-denied.sh and chmod +x.

set -uo pipefail
input=$(cat)
AP="${CLAUDE_PROJECT_DIR:-$(pwd)}/.autopilot"
mkdir -p "$AP"

tool=$(printf '%s' "$input" | jq -r '.tool_name // "unknown"')
detail=$(printf '%s' "$input" | jq -r '.tool_input.command // .tool_input.file_path // ""' | head -c 200)

echo "- [$(date '+%F %T')] DENIED $tool :: $detail" >> "$AP/BLOCKERS.md"
"${CLAUDE_PROJECT_DIR:-$(pwd)}/scripts/notify.sh" "ACCESS NEEDED: $tool — $detail" >/dev/null 2>&1 || true

# Tell the model this is a wall, not a hiccup, so it routes around instead of
# burning turns retrying the same denied call.
jq -nc '{
  hookSpecificOutput: { hookEventName: "PermissionDenied", retry: false },
  systemMessage: "Denied by policy. Logged and the user has been notified. Stop this phase and say so in one line — do not retry."
}'
