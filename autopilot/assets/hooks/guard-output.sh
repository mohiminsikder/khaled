#!/usr/bin/env bash
# PreToolUse(Bash). Clamps unbounded commands before they run.
#
# Unbounded output is the largest avoidable token cost in a long run, because it
# does not cost once — it enters context and is then re-sent on every subsequent
# turn of the phase. Asking the model to remember `| tail -30` works most of the
# time; a hook works every time, which is what an unattended run needs.
#
# It rewrites rather than blocks: the command still does what it was asked to do.
#
# Install to .claude/hooks/guard-output.sh and chmod +x.

set -uo pipefail
input=$(cat)
cmd=$(printf '%s' "$input" | jq -r '.tool_input.command // ""')
[[ -n "$cmd" ]] || { echo '{}'; exit 0; }

# Already bounded by the caller — leave it alone.
if [[ "$cmd" =~ \|[[:space:]]*(head|tail)[[:space:]] ]] || [[ "$cmd" =~ -n[[:space:]]*[0-9] ]]; then
  echo '{}'; exit 0
fi

new="$cmd"
case "$cmd" in
  # Test runners: failures are the signal, and they are at the end.
  npm\ test*|npm\ run\ test*|yarn\ test*|pnpm\ test*|jest*|vitest*|pytest*|go\ test*|cargo\ test*|phpunit*|rspec*|mvn\ test*|gradle\ test*)
      new="$cmd 2>&1 | tail -80" ;;
  # Build and lint: same shape.
  npm\ run\ build*|tsc*|eslint*|ruff*|flake8*|mypy*|cargo\ build*|make*)
      new="$cmd 2>&1 | tail -60" ;;
  git\ log*)   new="$cmd -n 20" ;;
  git\ diff*)  new="$cmd | head -300" ;;
  git\ show*)  new="$cmd | head -200" ;;
  cat\ *)
      # A whole-file read to find one thing is the classic waste. Keep small
      # files whole; range-read large ones.
      f=$(printf '%s' "$cmd" | awk '{print $2}')
      if [[ -f "$f" && $(wc -l < "$f" 2>/dev/null || echo 0) -gt 200 ]]; then
        new="sed -n '1,200p' $f && echo '--- truncated at 200 lines; use sed -n START,ENDp for the rest ---'"
      fi ;;
  find\ *)     new="$cmd 2>/dev/null | head -100" ;;
  grep\ -r*|rg\ *) new="$cmd | head -80" ;;
  docker\ logs*|journalctl*|tail\ -f*) new="$cmd 2>&1 | tail -100" ;;
esac

if [[ "$new" == "$cmd" ]]; then echo '{}'; exit 0; fi

jq -nc --arg c "$new" '{
  hookSpecificOutput: {
    hookEventName: "PreToolUse",
    permissionDecision: "allow",
    updatedInput: { command: $c }
  }
}'
