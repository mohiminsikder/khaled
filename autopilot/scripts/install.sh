#!/usr/bin/env bash
# install.sh — wire a project up for autopilot. Run from the project root.
#
#   ~/.claude/skills/autopilot/scripts/install.sh
#   ~/.claude/skills/autopilot/scripts/install.sh --systemd

set -euo pipefail
SKILL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT_DIR="$(pwd)"

git rev-parse --git-dir >/dev/null 2>&1 || {
  echo "Not a git repo. Autopilot needs git for per-phase checkpoints and rollback." >&2
  exit 1; }
command -v jq >/dev/null || { echo "jq is required (the hooks parse stdin with it)." >&2; exit 1; }

mkdir -p .claude/hooks scripts .autopilot
cp "$SKILL_DIR"/assets/hooks/*.sh .claude/hooks/
cp "$SKILL_DIR"/scripts/notify.sh "$SKILL_DIR"/scripts/preflight.sh \
   "$SKILL_DIR"/scripts/autopilot-run.sh "$SKILL_DIR"/scripts/autopilot-report.sh \
   "$SKILL_DIR"/scripts/accounts-add.sh "$SKILL_DIR"/scripts/selftest.sh scripts/
mkdir -p scripts/lib && cp "$SKILL_DIR"/scripts/lib/*.sh scripts/lib/
chmod +x .claude/hooks/*.sh scripts/*.sh

# Merge settings rather than clobbering an existing file.
SNIP="$SKILL_DIR/assets/settings.snippet.json"
if [[ -f .claude/settings.json ]]; then
  jq -s '.[0] * .[1] | del(._comment)' .claude/settings.json "$SNIP" > .claude/settings.json.new
  mv .claude/settings.json .claude/settings.json.bak
  mv .claude/settings.json.new .claude/settings.json
  echo "merged into .claude/settings.json (previous saved as .bak)"
  echo "  -> check the merged permissions.allow list; jq merge replaces arrays wholesale"
else
  jq 'del(._comment)' "$SNIP" > .claude/settings.json
  echo "wrote .claude/settings.json"
fi

[[ -f .autopilot/notify.env ]] || {
  printf 'NTFY_TOPIC=autopilot-%s\n' "$(head -c 12 /dev/urandom | base64 | tr -dc 'a-z0-9')" \
    > .autopilot/notify.env
  echo "wrote .autopilot/notify.env — subscribe to that topic in the ntfy app"; }

[[ -f .autopilot/accounts.env ]] || cat > .autopilot/accounts.env <<'ACC'
# Rotation order, cheapest first. "default" is your normal ~/.claude.
# Seed each extra profile once, interactively:
#   CLAUDE_CONFIG_DIR=~/.claude-acct2 claude    # /login, then /exit
AP_PROFILES="default"

# Optional last resort. Metered billing has no reset to wait for, so this is
# what finishes a run at 4am when every subscription is dry. Leave empty to skip.
AP_API_FALLBACK_KEY=""
AP_API_FALLBACK_BUDGET_USD="5"

# Refuse to sleep longer than this for a reset (a weekly limit can be days out).
AP_MAX_WAIT_HOURS="14"
ACC
echo "wrote .autopilot/accounts.env — add a second profile before your first overnight run"

grep -qx '.autopilot/' .gitignore 2>/dev/null || {
  { echo '.autopilot/'; echo '!.autopilot/PLAN.md'; } >> .gitignore
  echo "updated .gitignore"; }

if [[ "${1:-}" == "--systemd" ]]; then
  mkdir -p ~/.config/systemd/user
  sed "s|__PROJECT__|$PROJECT_DIR|g" "$SKILL_DIR/assets/autopilot.service" \
    > ~/.config/systemd/user/autopilot.service
  systemctl --user daemon-reload
  echo "installed ~/.config/systemd/user/autopilot.service"
  echo "  systemctl --user enable --now autopilot.service"
  echo "  sudo loginctl enable-linger $USER"
fi

if command -v graphify >/dev/null 2>&1; then
  if [[ -f graphify-out/graph.json ]]; then
    echo "graphify: code graph found — lane safety will be verified, not assumed"
  else
    echo "graphify: installed but no graph yet. Build it (free, local, seconds):"
    echo "  graphify update . --no-cluster"
  fi
  if grep -rqs 'hook-guard.*--strict' .claude/settings.json 2>/dev/null; then
    echo "  WARNING: graphify strict mode is on. Every autopilot phase is a new session,"
    echo "  so 'once per session' becomes once per PHASE — a wasted turn every time."
    echo "  Reinstall without --strict, or set GRAPHIFY_HOOK_STRICT=0."
  fi
fi

echo
echo "Two things before you trust it overnight:"
echo "  ./scripts/notify.sh 'test'          # confirm the phone alert arrives"
echo "  ./scripts/accounts-add.sh acct2     # add a second account so a 2am limit costs 30s"
