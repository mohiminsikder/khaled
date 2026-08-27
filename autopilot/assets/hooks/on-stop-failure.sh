#!/usr/bin/env bash
# StopFailure(rate_limit|authentication_failed|billing_error|oauth_org_not_allowed).
#
# This is the quota tripwire. The driver reads the marker file this writes and
# decides whether to rotate accounts or sleep to the reset.
#
# Why a hook and not a grep of stdout: the user-facing strings ("You've hit your
# session limit · resets 3:45pm") change between releases, and a driver that
# greps them fails silently one upgrade later. The hook matcher is a contract.
#
# Install to .claude/hooks/on-stop-failure.sh and chmod +x.

set -uo pipefail
input=$(cat)
AP="${CLAUDE_PROJECT_DIR:-$(pwd)}/.autopilot"
mkdir -p "$AP"

reason=$(printf '%s' "$input" | jq -r '.reason // .matcher // "unknown"')
msg=$(printf '%s' "$input" | jq -r '.message // .error // ""' | head -c 300)

# The driver parses "resets <time>" out of this file, when there is one.
printf '%s :: %s\n' "$reason" "$msg" > "$AP/QUOTA"
echo "- [$(date '+%F %T')] STOP($reason) $msg" >> "$AP/JOURNAL.md"
exit 0
