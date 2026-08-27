#!/usr/bin/env bash
# graph.sh — optional code-graph integration (graphify).
#
# Everything here is best-effort. If graphify is not installed, or no graph has
# been built, every function no-ops and the run behaves exactly as it did
# before. Never make an unattended run depend on an optional tool.
#
# What the graph buys, specifically:
#
#   1. Blast radius. `graphify affected <file>` answers "what else breaks if I
#      change this" from a pre-built index, in milliseconds, with no model call.
#      Grep cannot answer that cheaply — the model would otherwise discover it
#      by searching, several billed turns at a time.
#
#   2. Real lane safety. Parallel lanes currently trust the plan's files: list.
#      With a graph the driver can CHECK it: if phase A's blast radius touches a
#      file phase B declares, they are not actually independent, whatever the
#      plan claims.
#
# Building the code graph is deterministic tree-sitter parsing — no LLM calls,
# nothing leaves the machine, and it takes a couple of seconds on a mid-size
# repo. That is what makes it worth running on a schedule rather than once.
#
#   Install:  uv tool install graphifyy && graphify install --project
#   See:      references/code-graph.md

GRAPH_JSON="${GRAPH_JSON:-graphify-out/graph.json}"

graph_available() {
  [[ "${AP_USE_GRAPH:-1}" == "1" ]] || return 1
  command -v graphify >/dev/null 2>&1 || return 1
  [[ -f "$PROJECT_DIR/$GRAPH_JSON" ]] || return 1
}

# Rebuild the code half of the graph. Deterministic, no LLM, no network.
graph_refresh() {
  command -v graphify >/dev/null 2>&1 || return 0
  [[ "${AP_USE_GRAPH:-1}" == "1" ]] || return 0
  ( cd "$PROJECT_DIR" && timeout 300 graphify update . --no-cluster ) \
    >>"$AP/driver.log" 2>&1 || log "graph refresh failed (continuing without it)"
  rm -f "$AP"/pack/.impact-* 2>/dev/null || true
}

# Files whose behaviour depends on the given files. One line per path.
# Cached per phase: select_batch compares every pair, so without a cache this
# would shell out O(n^2) times per iteration for an answer that cannot change
# until the next graph refresh.
graph_impact_cached() {
  local p="$1" cf="$AP/pack/.impact-$1"
  [[ -f "$cf" && "$cf" -nt "$PROJECT_DIR/$GRAPH_JSON" ]] || {
    mkdir -p "$AP/pack"
    # shellcheck disable=SC2046  # deliberate split: phase_files is a file list
    graph_impact $(phase_files "$p") > "$cf" 2>/dev/null || : > "$cf"
  }
  cat "$cf"
}

graph_impact() {
  graph_available || return 0
  local f
  for f in "$@"; do
    ( cd "$PROJECT_DIR" && timeout 60 graphify affected "$f" \
        --depth "${AP_GRAPH_DEPTH:-2}" --graph "$GRAPH_JSON" 2>/dev/null ) \
      | sed -n 's/^- .*\] \([^:]*\):L[0-9]*$/\1/p'
  done | sort -u
}

# An orientation section for the phase's pack: what this change can reach.
# Kept small on purpose — this is a warning label, not a second codebase.
graph_pack_section() {
  local p="$1" impact
  graph_available || return 0
  impact=$(graph_impact_cached "$p" | grep -v '^$' | head -25)
  [[ -n "$impact" ]] || return 0
  echo "## Blast radius (from the code graph — these depend on the files you are changing)"
  printf '%s\n' "$impact"
  echo
  echo "Do NOT edit these; they are listed so you do not break them silently."
  echo "If the change forces one of them to change too, stop and say so — the plan is wrong."
  echo
}

# Ask the graph a scoped question. --budget caps what comes back, which is the
# whole reason this is safe to call in a token-constrained run.
graph_query() {
  graph_available || return 0
  ( cd "$PROJECT_DIR" && timeout 60 graphify query "$1" \
      --budget "${AP_GRAPH_BUDGET:-800}" --graph "$GRAPH_JSON" 2>/dev/null )
}

# Would running these two phases concurrently actually be safe?
# The plan's files: lists claim independence; this checks it. Returns 0 if safe.
graph_lane_safe() {
  local a="$1" b="$2" impact_a bf
  graph_available || return 0          # no graph: fall back to the plan's claim
  impact_a=$(graph_impact_cached "$a")
  [[ -n "$impact_a" ]] || return 0
  for bf in $(phase_files "$b"); do
    if printf '%s\n' "$impact_a" | grep -qxF "$bf"; then
      log "lane check: $a's blast radius reaches $bf, which $b edits — not running them together"
      return 1
    fi
  done
  return 0
}
