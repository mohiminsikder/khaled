#!/usr/bin/env bash
# common.sh — shared helpers. Sourced by the driver; not executable on its own.

: "${PROJECT_DIR:=$(pwd)}"
: "${AP:=${PROJECT_DIR}/.autopilot}"

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*" | tee -a "$AP/driver.log" >&2; }

notify() { "${PROJECT_DIR}/scripts/notify.sh" "$*" >/dev/null 2>&1 || true; }

die() { log "FATAL: $*"; notify "Autopilot fatal: $*"; exit 1; }

# Probe once whether this claude build supports a flag, so the driver degrades
# instead of failing on an older release.
_flags_cache=""
flag_supported() {
  [[ -n "$_flags_cache" ]] || _flags_cache=$(claude --help 2>&1 || true)
  [[ "$_flags_cache" == *"$1"* ]]
}

# ---------- PLAN.md accessors -------------------------------------------------
# PLAN.md is the source of truth. Deliberately line-oriented so shell can own it
# and no model turn is ever spent on bookkeeping.

plan_phases() { grep -oE '^## (P[0-9]+)' "$AP/PLAN.md" 2>/dev/null | awk '{print $2}'; }

# phase_field <P3> <verify>  -> value, or empty
phase_field() {
  awk -v p="## $1 " -v f="$2:" '
    index($0, p) == 1 { inp = 1; next }
    inp && /^## / { exit }
    inp && index($0, f) == 1 { sub("^" f "[ \t]*", ""); print; exit }
  ' "$AP/PLAN.md"
}

phase_status() { phase_field "$1" status; }

# Parallel lanes mutate one PLAN.md, so every write takes a lock. Without it the
# read-modify-write below loses an update whenever two lanes finish together.
with_lock() {
  local lock="$AP/.plan.lock"
  if command -v flock >/dev/null 2>&1; then
    ( flock -w 30 9 || true; "$@" ) 9>"$lock"
  else
    local n=0
    until mkdir "$lock.d" 2>/dev/null; do sleep 0.2; n=$((n+1)); (( n > 150 )) && break; done
    "$@"; rmdir "$lock.d" 2>/dev/null || true
  fi
}

_set_phase_status() {
  local p="$1" s="$2" tmp
  tmp=$(mktemp)
  awk -v p="## $p " -v s="status: $s" '
    index($0, p) == 1 { inp = 1; print; next }
    inp && /^## / { inp = 0 }
    inp && /^status:/ { print s; next }
    { print }
  ' "$AP/PLAN.md" > "$tmp" && mv "$tmp" "$AP/PLAN.md"
}

set_phase_status() { with_lock _set_phase_status "$@"; }

# Dependencies all done?
phase_ready() {
  local p="$1" d
  [[ "$(phase_status "$p")" == "pending" ]] || return 1
  for d in $(phase_field "$p" depends | tr ',' ' '); do
    [[ -z "$d" || "$d" == "none" ]] && continue
    [[ "$(phase_status "$d")" == "done" ]] || return 1
  done
  return 0
}

phase_files() { phase_field "$1" files | tr ',' ' ' | xargs 2>/dev/null || true; }

# Do two phases claim any file in common? Parallel safety depends on this.
phases_disjoint() {
  local a b
  for a in $(phase_files "$1"); do
    for b in $(phase_files "$2"); do
      [[ "$a" == "$b" ]] && return 1
    done
  done
  return 0
}

# ---------- model routing -----------------------------------------------------
# Sonnet is the default workhorse. Routing is about the tails.

model_for_class() {
  case "${1:-logic}" in
    mech) echo "${AP_MODEL_MECH:-haiku}" ;;
    hard) echo "${AP_MODEL_HARD:-opus}" ;;
    *)    echo "${AP_MODEL_LOGIC:-sonnet}" ;;
  esac
}

thinking_for_class() {
  # Empty means "leave the default alone" — never trim thinking on real logic.
  case "${1:-logic}" in
    mech) echo "${AP_THINK_MECH:-2000}" ;;
    *)    echo "" ;;
  esac
}

# One tier up, for a phase that has already failed once.
escalate_class() {
  case "${1:-logic}" in
    mech) echo "logic" ;;
    *)    echo "hard" ;;
  esac
}

# >> on a short line is atomic enough for lanes; the lock keeps ordering sane.
journal() { with_lock bash -c 'printf "%s\n" "$1" >> "$2"' _ "$*" "$AP/JOURNAL.md"; }

blocker() {
  { echo "- [$(date '+%F %T')] $*"; } >> "$AP/BLOCKERS.md"
  notify "$*"
}
