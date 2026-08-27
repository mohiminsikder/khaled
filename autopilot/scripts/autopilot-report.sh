#!/usr/bin/env bash
# autopilot-report.sh — the morning report, and the honest answer to
# "did this actually save me tokens?"
#
# Reads the JSON results the driver kept and reports real numbers. A figure you
# measured on your own repo beats one quoted at you in a README.

set -uo pipefail
AP="$(pwd)/.autopilot"
[[ -d "$AP/results" ]] || { echo "no results yet"; exit 0; }

echo "=== Autopilot run report ==="
echo

# grep -c prints "0" and exits 1 on no match, so `|| echo 0` would double it.
count_status() { local c; c=$(grep -c "^status: $1" "$AP/PLAN.md" 2>/dev/null || true); echo "${c:-0}"; }
printf 'Phases   done:%s  blocked:%s  pending:%s\n' \
  "$(count_status done)" "$(count_status blocked)" "$(count_status pending)"
echo

jq -s '
  def n(f): map(f // 0) | add;
  {
    invocations:  length,
    input:        n(.usage.input_tokens),
    output:       n(.usage.output_tokens),
    cache_read:   n(.usage.cache_read_input_tokens),
    cache_write:  n(.usage.cache_creation_input_tokens),
    cost_usd:     (n(.total_cost_usd) * 100 | round / 100),
    turns:        n(.num_turns)
  }
  | . + { cache_hit_pct: (if (.cache_read + .cache_write) > 0
            then (.cache_read * 100 / (.cache_read + .cache_write) | round) else 0 end) }
' "$AP"/results/*.json 2>/dev/null || echo "(could not parse results)"

echo
echo "cache_hit_pct should be high and stay high. If cache_write stays large phase"
echo "after phase, something in the prefix is moving — see references/token-budget.md."
echo
echo "=== Journal ==="
tail -40 "$AP/JOURNAL.md" 2>/dev/null || echo "(empty)"
if [[ -s "$AP/BLOCKERS.md" ]]; then
  echo; echo "=== Blockers (read these first) ==="
  tail -40 "$AP/BLOCKERS.md"
fi
