#!/usr/bin/env bash
# accounts-add.sh — add a second (or third) Claude account for auto-switching.
#
#   ./scripts/accounts-add.sh acct2
#   ./scripts/accounts-add.sh --list
#   ./scripts/accounts-add.sh --check
#
# One folder per account. CLAUDE_CONFIG_DIR points Claude Code at a different
# home, and the login lands in that home. Switching accounts then costs an
# environment variable instead of an interactive /login — which is the point,
# because at 3am there is nobody to type one.

set -uo pipefail
AP="$(pwd)/.autopilot"
ENVF="$AP/accounts.env"
mkdir -p "$AP"
[[ -f "$ENVF" ]] || printf 'AP_PROFILES="default"\n' > "$ENVF"
# shellcheck disable=SC1090,SC1091
source "$ENVF"

profile_dir() { [[ "$1" == "default" ]] && echo "$HOME/.claude" || echo "${1/#\~/$HOME}"; }

# Send a three-word prompt through a profile and report what comes back.
check_one() {
  local p="$1" out rc
  if [[ "$p" == "default" ]]; then unset CLAUDE_CONFIG_DIR
  else local d; d=$(profile_dir "$p"); export CLAUDE_CONFIG_DIR="$d"; fi

  out=$(claude -p --max-turns 1 --output-format text "reply with the word ok" 2>&1); rc=$?
  if [[ $rc -ne 0 || "$out" == *"Please run /login"* || "$out" == *"Invalid authentication"* ]]; then
    printf '  %-24s NOT LOGGED IN — run: ./scripts/accounts-add.sh %s\n' "$p" "$p"; return 1
  elif [[ "$out" == *"limit"* || "$out" == *"Credit balance"* ]]; then
    printf '  %-24s logged in, but OUT OF QUOTA right now\n' "$p"; return 0
  else
    printf '  %-24s ready\n' "$p"; return 0
  fi
}

case "${1:-}" in
  --list)
    echo "Profiles in rotation order (first one is used first):"
    for p in $AP_PROFILES; do printf '  %-24s %s\n' "$p" "$(profile_dir "$p")"; done
    exit 0 ;;
  --check)
    echo "Checking each profile. This sends a three-word prompt to each — costs a rounding error."
    ok=0
    for p in $AP_PROFILES; do check_one "$p" && ok=$((ok+1)); done
    echo
    if [[ $ok -lt 2 ]]; then
      echo "Only $ok profile(s) usable. With one account, a usage limit at 2am stops the run"
      echo "until it resets. Add a second: ./scripts/accounts-add.sh acct2"
    else
      echo "$ok profiles usable — a usage limit will now cost you about 30 seconds, not the night."
    fi
    exit 0 ;;
  "" ) echo "usage: $0 <name>   |   $0 --list   |   $0 --check" >&2; exit 1 ;;
esac

NAME="$1"
DIR="$HOME/.claude-$NAME"

cat <<BANNER

Adding account profile: $NAME
Its own home:           $DIR

IMPORTANT: this must be a DIFFERENT Claude account — a different email with its
own subscription. Signing the same account in twice gives you no extra quota,
because the limit belongs to the account, not to the terminal window.

Claude Code is about to open. Do exactly this:
    1.  type   /login      and sign in as the OTHER account
    2.  type   /exit

BANNER
read -r -p "Press Enter to open Claude Code (Ctrl+C to cancel)... " _

mkdir -p "$DIR"
CLAUDE_CONFIG_DIR="$DIR" claude

# Add to the rotation if it is not already there.
if [[ " $AP_PROFILES " != *" ~/.claude-$NAME "* ]]; then
  NEW="$AP_PROFILES ~/.claude-$NAME"
  if grep -q '^AP_PROFILES=' "$ENVF"; then
    sed -i.bak "s|^AP_PROFILES=.*|AP_PROFILES=\"$NEW\"|" "$ENVF" && rm -f "$ENVF.bak"
  else
    printf 'AP_PROFILES="%s"\n' "$NEW" >> "$ENVF"
  fi
  echo
  echo "Added to rotation: AP_PROFILES=\"$NEW\""
fi

echo
echo "Now verifying both accounts actually answer:"
source "$ENVF"
for p in $AP_PROFILES; do check_one "$p"; done
echo
echo "If both say 'ready', you are done. The run will switch between them by itself."
