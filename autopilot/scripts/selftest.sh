#!/usr/bin/env bash
# selftest.sh — prove the driver still works after you change it.
#
# Runs the whole protocol against a throwaway repo and a stub `claude`, so it
# costs nothing and needs no account. Run it after editing anything in scripts/.
#
#   ./scripts/selftest.sh
#
# It checks the things that are expensive to get wrong at 3am: phase ordering,
# model routing, failure isolation, crash recovery costing zero model calls, and
# account rotation on a usage limit.

set -uo pipefail
SK="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Works from two layouts: the skill directory (assets/hooks, scripts/) and an
# installed project (.claude/hooks, scripts/). Resolve both rather than assuming.
if   [[ -d "$SK/assets/hooks" ]]; then HOOKS="$SK/assets/hooks"
elif [[ -d "$SK/.claude/hooks" ]]; then HOOKS="$SK/.claude/hooks"
else echo "cannot find the hooks directory from $SK" >&2; exit 1; fi
[[ -d "$SK/scripts/lib" ]] || { echo "cannot find scripts/lib from $SK" >&2; exit 1; }
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
PASS=0; FAIL=0

ck() { if [[ "$2" == "$3" ]]; then printf '  PASS  %s\n' "$1"; PASS=$((PASS+1))
       else printf '  FAIL  %s (got "%s", want "%s")\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi; }

setup() {
  R="$TMP/$1"; mkdir -p "$R/bin"; cd "$R" || exit 1
  git init -q .; git config user.email t@t; git config user.name t
  echo x > README.md; git add -A; git commit -qm init
  mkdir -p .claude/hooks scripts/lib .autopilot
  cp "$HOOKS"/*.sh .claude/hooks/
  cp "$SK"/scripts/*.sh scripts/; cp "$SK"/scripts/lib/*.sh scripts/lib/
  chmod +x .claude/hooks/*.sh scripts/*.sh
  echo 'AP_PROFILES="default"' > .autopilot/accounts.env
  export PATH="$R/bin:/usr/bin:/bin"
}
phase() { printf '## %s — t\ndepends: %s\nfiles: %s\nclass: %s\ndone_when: x\nverify: %s\nrollback: true\nstatus: pending\n\n' "$1" "$2" "$3" "$4" "$5"; }

echo "== 1. phases, ordering, routing, failure isolation =="
setup t1
cat > bin/claude <<'ST'
#!/usr/bin/env bash
[[ "$1" == "--help" ]] && { echo "--disallowedTools --strict-mcp-config --mcp-config --fallback-model --max-budget-usd"; exit 0; }
p="${*: -1}"; for n in 1 2 3; do [[ "$p" == *"phase P$n"* ]] && echo v > "f$n.txt"; done
echo '{"usage":{"input_tokens":100,"output_tokens":50,"cache_read_input_tokens":900,"cache_creation_input_tokens":100},"total_cost_usd":0.01,"num_turns":3}'
ST
chmod +x bin/claude
{ phase P1 none f1.txt mech "test -f f1.txt"; phase P2 none f2.txt logic "test -f f2.txt"
  phase P3 P1 f3.txt logic "test -f f3.txt"; phase P4 none f9.txt mech "test -f never.txt"; } > .autopilot/PLAN.md
AP_LANES=2 AP_FULL_SUITE="test -f f1.txt" ./scripts/autopilot-run.sh --resume >/dev/null 2>&1
ck "3 good phases done"        "$(grep -c '^status: done' .autopilot/PLAN.md)" "3"
ck "unverifiable phase blocked" "$(grep -c '^status: blocked' .autopilot/PLAN.md)" "1"
ck "mech phase used haiku"      "$(grep -c haiku .autopilot/JOURNAL.md)" "1"
ck "failed phase escalated"     "$(grep -c escalating .autopilot/JOURNAL.md)" "1"
ck "no phantom empty phase"     "$(grep -c '^ done' .autopilot/JOURNAL.md)" "0"
ck "worktrees cleaned up"       "$(git worktree list | wc -l)" "1"

echo "== 2. power loss: work landed, bookkeeping lost =="
setup t2
cat > bin/claude <<'ST'
#!/usr/bin/env bash
[[ "$1" == "--help" ]] && { echo "--fallback-model"; exit 0; }
echo CALL >> .autopilot/calls.log; echo '{"usage":{},"total_cost_usd":0}'
ST
chmod +x bin/claude
phase P1 none f1.txt logic "test -f f1.txt" > .autopilot/PLAN.md
echo survived > f1.txt; printf 'P1' > .autopilot/CURSOR; git tag -f autopilot/pre-P1 >/dev/null 2>&1
./scripts/autopilot-run.sh --resume >/dev/null 2>&1
ck "phase recovered"            "$(grep -c '^status: done' .autopilot/PLAN.md)" "1"
ck "recovery cost ZERO calls"   "$( [[ -f .autopilot/calls.log ]] && wc -l < .autopilot/calls.log || echo 0 )" "0"
ck "work preserved"             "$(cat f1.txt)" "survived"

echo "== 3. usage limit: rotate to the second account =="
setup t3
mkdir -p "$TMP/acct2"
cat > bin/claude <<'ST'
#!/usr/bin/env bash
[[ "$1" == "--help" ]] && { echo "--fallback-model"; exit 0; }
if [[ -z "${CLAUDE_CONFIG_DIR:-}" ]]; then
  printf 'rate_limit :: resets %s\n' "$(date -d '+2 hours' '+%H:%M')" > .autopilot/QUOTA; echo '{}'; exit 0; fi
echo ok > f1.txt; echo '{"usage":{},"total_cost_usd":0.01,"num_turns":1}'
ST
chmod +x bin/claude
phase P1 none f1.txt logic "test -f f1.txt" > .autopilot/PLAN.md
printf 'AP_PROFILES="default %s"\n' "$TMP/acct2" > .autopilot/accounts.env
./scripts/autopilot-run.sh --resume >/dev/null 2>&1
ck "rotated and finished"       "$(grep -c '^status: done' .autopilot/PLAN.md)" "1"
ck "rotation was journaled"     "$(grep -c 'rotated to' .autopilot/JOURNAL.md)" "1"

echo "== 4. output clamp =="
clamp() { printf '%s' "$1" | jq -Rc '{tool_input:{command:.}}' | bash "$HOOKS/guard-output.sh" \
          | jq -r '.hookSpecificOutput.updatedInput.command // "UNCHANGED"'; }
ck "npm test is bounded"        "$(clamp 'npm test')" "npm test 2>&1 | tail -80"
ck "already-bounded left alone" "$(clamp 'npm test 2>&1 | tail -5')" "UNCHANGED"
ck "git log is bounded"         "$(clamp 'git log')" "git log -n 20"
ck "harmless command untouched" "$(clamp 'echo hi')" "UNCHANGED"

echo "== 5. code graph is optional =="
setup t5
cat > bin/claude <<'ST'
#!/usr/bin/env bash
[[ "$1" == "--help" ]] && { echo "--fallback-model"; exit 0; }
echo v > f1.txt; echo '{"usage":{},"total_cost_usd":0.01,"num_turns":1}'
ST
chmod +x bin/claude
phase P1 none f1.txt logic "test -f f1.txt" > .autopilot/PLAN.md
./scripts/autopilot-run.sh --resume >/dev/null 2>&1   # graphify absent from PATH
ck "runs fine with no graph"    "$(grep -c '^status: done' .autopilot/PLAN.md)" "1"

cd /; echo
if [[ $FAIL -eq 0 ]]; then echo "ALL $PASS CHECKS PASSED"; exit 0
else echo "$FAIL FAILED, $PASS passed"; exit 1; fi
