#!/usr/bin/env bash
# autopilot-run.sh — the unattended driver.
#
# One phase per `claude -p` invocation. Each starts from a clean context and
# re-reads a curated state file. That is the compaction strategy: we never
# compact, we restart small. It is also the crash strategy, the quota strategy,
# and the account-switch strategy — all three are the same property.
#
# Usage:
#   ./scripts/autopilot-run.sh "Build the X feature end to end"
#   ./scripts/autopilot-run.sh --resume     # after a crash, a reboot, anything
#
# Stop it from anywhere:  touch .autopilot/HALT

set -uo pipefail

PROJECT_DIR="$(pwd)"
AP="${PROJECT_DIR}/.autopilot"
LIB="${PROJECT_DIR}/scripts/lib"
mkdir -p "$AP" "$AP/pack"

# shellcheck source=lib/common.sh
source "$LIB/common.sh"
# shellcheck source=lib/accounts.sh
source "$LIB/accounts.sh"
# shellcheck source=lib/pack.sh
source "$LIB/pack.sh"
# shellcheck source=lib/graph.sh
source "$LIB/graph.sh"

MAX_PHASES="${AP_MAX_PHASES:-60}"
MAX_TURNS="${AP_MAX_TURNS:-25}"
MAX_BUDGET="${AP_MAX_BUDGET_USD:-}"
LANES="${AP_LANES:-3}"
REGRESSION_EVERY="${AP_REGRESSION_EVERY:-3}"
FULL_SUITE="${AP_FULL_SUITE:-}"
PUSH_BRANCH="${AP_PUSH_BRANCH:-}"
CONSEC_FAIL_LIMIT=3

command -v claude >/dev/null || die "claude CLI not found on PATH"
command -v jq     >/dev/null || die "jq not found on PATH (the hooks need it)"
git -C "$PROJECT_DIR" rev-parse --git-dir >/dev/null 2>&1 \
  || die "not a git repo — autopilot needs git for per-phase checkpoints and rollback"

# ---------- the invocation ----------------------------------------------------

# Terseness is for output, never for reasoning. This is appended, not a
# replacement, so the model keeps everything it normally knows.
read -r -d '' TERSE <<'PROMPT' || true
You are running unattended with nobody at the keyboard. Follow the autopilot skill.
Output discipline: no narration, no preamble, no summary of what you just did, no
restating a file you just wrote. Do not run git commit, do not edit PLAN.md status
lines, do not write JOURNAL.md — the driver does all of that deterministically after
you stop. Produce the code change and nothing else. If you need something you cannot
reach, say so in one line and stop; do not retry a denied call.
PROMPT

claude_flags() {
  local model="$1"
  local -a f=(
    -p
    --permission-mode dontAsk
    --output-format json
    --max-turns "$MAX_TURNS"
    --model "$model"
    --append-system-prompt "$TERSE"
  )
  # Bare-name denies drop these tool definitions out of the system prompt for
  # the whole run, not just at call time.
  flag_supported '--disallowedTools' && f+=(--disallowedTools "WebSearch,WebFetch,NotebookEdit")
  # No MCP servers means no MCP tool definitions and no mid-run reconnect
  # invalidating the cache.
  flag_supported '--strict-mcp-config' && f+=(--strict-mcp-config --mcp-config '{"mcpServers":{}}')
  flag_supported '--fallback-model'    && f+=(--fallback-model sonnet)
  [[ -n "$MAX_BUDGET" ]] && flag_supported '--max-budget-usd' && f+=(--max-budget-usd "$MAX_BUDGET")
  printf '%s\n' "${f[@]}"
}

# Run one claude invocation. Sets RC, and OUT_FILE holds the JSON result.
invoke() {
  local model="$1" prompt="$2" think="$3" out="$AP/last-result.json"
  local -a flags; mapfile -t flags < <(claude_flags "$model")
  acct_env
  if [[ -n "$think" ]]; then export MAX_THINKING_TOKENS="$think"; else unset MAX_THINKING_TOKENS; fi
  claude "${flags[@]}" "$prompt" > "$out" 2>>"$AP/driver.log"
  RC=$?
  OUT_FILE="$out"
  # Keep every result: autopilot-report.sh reads real token counts out of them.
  mkdir -p "$AP/results"
  cp "$out" "$AP/results/$(date +%s%N)-$$-$model.json" 2>/dev/null || true
  return $RC
}

run_verify() {
  local cmd="$1"
  [[ -n "$cmd" ]] || return 0
  ( cd "${2:-$PROJECT_DIR}" && eval "$cmd" ) >"$AP/verify.out" 2>&1
}

# ---------- one phase ---------------------------------------------------------

run_phase() {
  local p="$1" dir="${2:-$PROJECT_DIR}"
  local verify class model think attempt=0 rc
  [[ -n "$p" ]] || { log "run_phase called with no phase — skipping"; return 1; }

  verify=$(phase_field "$p" verify)
  class=$(phase_field "$p" class); class="${class:-logic}"

  # 1. Already done? A crash between the edit and the status flip is the common
  #    case after a power cut, and it costs zero model tokens to notice.
  if [[ -n "$verify" ]] && run_verify "$verify" "$dir"; then
    log "$p — verify already passes, no work needed"
    set_phase_status "$p" "done"
    journal "$p done (0m, skipped) — verify already green on entry"
    git -C "$dir" add -A >/dev/null 2>&1
    git -C "$dir" commit -q -m "autopilot: $p (already satisfied)" >/dev/null 2>&1 || true
    return 0
  fi

  # 2. Checkpoint. The tag is what a rollback and a crash both aim at.
  printf '%s' "$p" > "$AP/CURSOR"
  git -C "$dir" add -A >/dev/null 2>&1
  git -C "$dir" commit -q -m "autopilot: pre-$p" >/dev/null 2>&1 || true
  git -C "$dir" tag -f "autopilot/pre-$p" >/dev/null 2>&1 || true

  # 3. Pack.
  local pack; pack=$(build_pack "$p")

  while (( attempt < 2 )); do
    model=$(model_for_class "$class")
    think=$(thinking_for_class "$class")
    local started; started=$(date +%s)

    log "$p attempt $((attempt+1)) — class=$class model=$model"
    invoke "$model" "Use the autopilot skill. Execute exactly phase $p and stop.

State: .autopilot/STATE.md
Plan:  .autopilot/PLAN.md
Pack:  $pack   (read this first — it is where the code is)

Touch only the files named in the phase's files: list. When done, update
.autopilot/STATE.md and stop. Do not start another phase." "$think"
    rc=$?

    # A quota or auth stop is not a phase failure. Roll back so no half-written
    # file survives the switch, then retry the same phase on a live account.
    if [[ -f "$AP/QUOTA" ]]; then
      git -C "$dir" reset --hard "autopilot/pre-$p" >/dev/null 2>&1 || true
      acct_handle_quota || return 2
      continue
    fi

    if [[ $rc -eq 0 ]] && run_verify "$verify" "$dir"; then
      local dur=$(( ($(date +%s) - started) / 60 ))
      set_phase_status "$p" "done"
      git -C "$dir" add -A >/dev/null 2>&1
      git -C "$dir" commit -q -m "autopilot: $p — $(phase_field "$p" done_when)" >/dev/null 2>&1 || true
      journal "$p done (${dur}m, $model) — verify green"
      rm -f "$AP/CURSOR"
      return 0
    fi

    attempt=$(( attempt + 1 ))
    if (( attempt < 2 )); then
      # A failed mech phase is usually a misclassification, not a hard problem.
      # Escalating costs one cheap wasted attempt; not escalating costs quality.
      class=$(escalate_class "$class")
      log "$p verify failed, escalating to class=$class for attempt 2"
      journal "$p retry — verify failed, escalating to $(model_for_class "$class")"
    fi
  done

  # Two attempts, no green. Roll back to a clean tree and flag it.
  local rb; rb=$(phase_field "$p" rollback)
  if [[ -n "$rb" ]]; then ( cd "$dir" && eval "$rb" ) >/dev/null 2>&1 || true
  else git -C "$dir" reset --hard "autopilot/pre-$p" >/dev/null 2>&1 || true
  fi
  set_phase_status "$p" blocked
  { echo; echo "## $p failed $(date '+%F %T')"; tail -20 "$AP/verify.out" 2>/dev/null; } >> "$AP/BLOCKERS.md"
  notify "BLOCKED $p — $(phase_field "$p" done_when) — rolled back, run continues"
  journal "$p blocked — verify failed twice, rolled back"
  rm -f "$AP/CURSOR"
  return 1
}

# ---------- lanes -------------------------------------------------------------
# Worktrees, not branches in one checkout: two claude processes editing one tree
# interleave writes and produce a diff neither intended. The cost is a cold
# prompt cache per lane, which is why LANES defaults to 3 rather than 8.

run_lane() {
  local p="$1"
  local wt="$AP/wt/$p" br="autopilot-lane-$p"
  git -C "$PROJECT_DIR" worktree add -q -B "$br" "$wt" HEAD 2>>"$AP/driver.log" || return 1
  ( cd "$wt" && run_phase "$p" "$wt" )
  local rc=$?
  if [[ $rc -eq 0 ]]; then
    if ! git -C "$PROJECT_DIR" merge --no-edit -q "$br" 2>>"$AP/driver.log"; then
      git -C "$PROJECT_DIR" merge --abort >/dev/null 2>&1 || true
      set_phase_status "$p" pending
      touch "$AP/.conflicted"
      blocker "$p merged with conflict — files: lists overlap. Dropping to sequential for the rest of the run."
      rc=1
    fi
  fi
  git -C "$PROJECT_DIR" worktree remove --force "$wt" >/dev/null 2>&1 || true
  git -C "$PROJECT_DIR" branch -D "$br" >/dev/null 2>&1 || true
  return $rc
}

# Ready phases with no file overlap between them.
select_batch() {
  local -a batch=() p q ok
  for p in $(plan_phases); do
    (( ${#batch[@]} >= LANES )) && break
    phase_ready "$p" || continue
    ok=1
    for q in "${batch[@]}"; do
      phases_disjoint "$p" "$q" || { ok=0; break; }
      # files: lists are a claim. With a code graph the driver can verify the
      # claim instead of trusting it, which is what makes lanes safe on a plan
      # you have not read.
      if ! graph_lane_safe "$p" "$q" || ! graph_lane_safe "$q" "$p"; then ok=0; break; fi
    done
    (( ok )) && batch+=("$p")
  done
  # An empty array must print nothing at all: printf '%s\n' "" emits a blank
  # line, which mapfile turns into a phantom phase named "".
  (( ${#batch[@]} )) && printf '%s\n' "${batch[@]}"
  return 0
}

# ---------- preflight and plan ------------------------------------------------

if [[ "${1:-}" != "--resume" ]]; then
  GOAL="${1:?usage: autopilot-run.sh \"<goal>\"  |  --resume}"
  printf '%s\n' "$GOAL" > "$AP/GOAL.md"
  : > "$AP/driver.log"
  rm -f "$AP/HALT" "$AP/QUOTA" "$AP/CURSOR" "$AP/.api-fallback-used"
  acct_set "$(acct_list | head -1)"

  graph_refresh   # deterministic, no LLM — a stale graph is worse than no graph
  log "PREFLIGHT + PLAN"
  invoke "$(model_for_class hard)" "Use the autopilot skill. Run Phase 0 PREFLIGHT (60s cap)
and Phase 1 PLAN for the goal below, then stop without executing any phase.

Every phase needs: depends, files, class (mech|logic|hard), done_when, verify, rollback,
status: pending. files: must be exact and complete — phases with disjoint files run in
PARALLEL, so an undeclared file is a merge conflict at 3am.

GOAL:
$GOAL" ""

  [[ -f "$AP/HALT" ]] && { notify "Autopilot halted at preflight — see BLOCKERS.md"; exit 1; }
  [[ -f "$AP/PLAN.md" ]] || die "planning produced no PLAN.md"
  notify "Autopilot started: $(plan_phases | wc -l | xargs) phases planned, profiles: $AP_PROFILES"
else
  log "RESUME — reconstructing state from disk, no model call"
  # A phase in flight when the power went: run_phase's own verify-first check
  # will skip it for free if the edit landed, and roll it back if it did not.
  if [[ -s "$AP/CURSOR" ]]; then
    inflight=$(cat "$AP/CURSOR")
    log "phase $inflight was in flight at interruption"
    set_phase_status "$inflight" pending
    git -C "$PROJECT_DIR" rev-parse "autopilot/pre-$inflight" >/dev/null 2>&1 \
      && log "rollback point autopilot/pre-$inflight is intact"
  fi
  rm -f "$AP/HALT"
  left=$(grep -c '^status: pending' "$AP/PLAN.md" 2>/dev/null || true)
  notify "Autopilot resumed — ${left:-?} phases left"
fi

# ---------- main loop ---------------------------------------------------------

consec_fail=0
completed=0
for (( i=1; i<=MAX_PHASES; i++ )); do
  [[ -f "$AP/HALT" ]] && { log "HALT file present"; break; }

  mapfile -t batch < <(select_batch)
  if [[ ${#batch[@]} -eq 0 ]]; then
    if grep -q '^status: pending' "$AP/PLAN.md" 2>/dev/null; then
      log "pending phases remain but none are ready — dependencies are blocked"
      blocker "HALT: remaining phases all depend on blocked work"
    else
      log "no pending phases remain"
    fi
    break
  fi

  # One bad disjointness claim means the plan's claims are not trustworthy.
  [[ -f "$AP/.conflicted" ]] && LANES=1

  if [[ ${#batch[@]} -eq 1 || $LANES -le 1 ]]; then
    run_phase "${batch[0]}"; rc=$?
  else
    log "running ${#batch[@]} phases in parallel: ${batch[*]}"
    pids=()
    for p in "${batch[@]}"; do run_lane "$p" & pids+=($!); done
    rc=0
    for pid in "${pids[@]}"; do wait "$pid" || rc=1; done
  fi

  [[ $rc -eq 2 ]] && { log "quota handler asked to halt"; break; }
  if [[ $rc -ne 0 ]]; then
    consec_fail=$(( consec_fail + 1 ))
    (( consec_fail >= CONSEC_FAIL_LIMIT )) && {
      notify "Autopilot stopped: $CONSEC_FAIL_LIMIT consecutive failed phases"; break; }
  else
    consec_fail=0
  fi

  completed=$(( completed + ${#batch[@]} ))
  graph_refresh
  [[ -n "$PUSH_BRANCH" ]] && git push -q -u origin "HEAD:$PUSH_BRANCH" 2>>"$AP/driver.log" || true

  # Per-phase verification catches local breakage. Only a full run catches
  # phase 3 breaking phase 1 — and it is the cheapest insurance here.
  if [[ -n "$FULL_SUITE" ]] && (( completed % REGRESSION_EVERY == 0 )); then
    log "regression gate after $completed phases"
    if ! run_verify "$FULL_SUITE"; then
      blocker "REGRESSION: full suite red after $completed phases"
      tail -30 "$AP/verify.out" >> "$AP/BLOCKERS.md"
      touch "$AP/HALT"
    fi
  fi
done

# ---------- report ------------------------------------------------------------
if [[ -n "$FULL_SUITE" ]]; then
  run_verify "$FULL_SUITE" && suite="green" || suite="RED"
else
  suite="not configured"
fi
count_status() { local c; c=$(grep -c "^status: $1" "$AP/PLAN.md" 2>/dev/null || true); echo "${c:-0}"; }
d=$(count_status "done"); b=$(count_status "blocked"); n=$(count_status "pending")
msg="Autopilot finished — done:$d blocked:$b pending:$n · full suite: $suite"
log "$msg"; notify "$msg"
"${PROJECT_DIR}/scripts/autopilot-report.sh" 2>/dev/null || true
[[ -s "$AP/BLOCKERS.md" ]] && { echo; echo "=== BLOCKERS ==="; tail -40 "$AP/BLOCKERS.md"; }
exit 0
