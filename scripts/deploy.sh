#!/usr/bin/env bash
# Counter — deploy and verify against the live server.
#
# Authentication is by SSH key only. There is deliberately no password path:
# a password cannot be supplied unattended without either an interactive
# prompt or a plaintext secret on disk, and a key is both safer and simpler.
#
#   scripts/deploy.sh pull      fetch the live plugin into ./live/ for inspection
#   scripts/deploy.sh push      upload ./counter/ to the server (backs up first)
#   scripts/deploy.sh selftest  run the suite remotely, exit non-zero on failure
#   scripts/deploy.sh status    plugin version, WP version, disk
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env.deploy"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "missing $ENV_FILE — copy .env.deploy.example and fill it in" >&2
  exit 2
fi
# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

: "${CNTR_SSH_HOST:?}"; : "${CNTR_SSH_PORT:?}"; : "${CNTR_SSH_USER:?}"; : "${CNTR_WP_PATH:?}"
KEY="${CNTR_SSH_KEY/#\~/$HOME}"
if [[ ! -f "$KEY" ]]; then
  echo "no SSH key at $KEY — see docs/DEPLOY.md section 1" >&2
  exit 2
fi

REMOTE="$CNTR_SSH_USER@$CNTR_SSH_HOST"
# BatchMode: never prompt. An unattended run must fail fast, not hang on a prompt.
SSH_OPTS=(-i "$KEY" -p "$CNTR_SSH_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
RSH="ssh -i $KEY -p $CNTR_SSH_PORT -o BatchMode=yes -o StrictHostKeyChecking=accept-new"

PLUGIN="$CNTR_WP_PATH/wp-content/plugins/counter"
WP="cd '$CNTR_WP_PATH' && wp"

# rsync is assumed by the sandbox this script was written for, but is absent
# from plain Git-for-Windows Bash. Fall back to tar-over-ssh, which every SSH
# host has; same net effect (full mirror, excluding tests/.git/node_modules),
# just without rsync's incremental transfer.
HAVE_RSYNC=0
command -v rsync >/dev/null 2>&1 && HAVE_RSYNC=1

case "${1:-}" in
  pull)
    rm -rf "$ROOT/live"; mkdir -p "$ROOT/live"
    if [[ "$HAVE_RSYNC" == 1 ]]; then
      rsync -az -e "$RSH" "$REMOTE:$PLUGIN/" "$ROOT/live/"
    else
      ssh "${SSH_OPTS[@]}" "$REMOTE" "tar czf - -C '$PLUGIN' ." | tar xzf - -C "$ROOT/live"
    fi
    echo "pulled -> $ROOT/live"
    ;;

  push)
    if [[ ! -d "$ROOT/counter" ]]; then echo "no ./counter to push" >&2; exit 2; fi
    STAMP="$(date +%Y%m%d-%H%M%S)"
    # Back up before overwriting. Never skip this.
    ssh "${SSH_OPTS[@]}" "$REMOTE" "cp -a '$PLUGIN' '$PLUGIN.bak-$STAMP' 2>/dev/null || true"
    if [[ "$HAVE_RSYNC" == 1 ]]; then
      rsync -az --delete \
        --exclude 'tests/' --exclude '.git/' --exclude 'node_modules/' \
        -e "$RSH" "$ROOT/counter/" "$REMOTE:$PLUGIN/"
    else
      echo "rsync not found locally — falling back to tar over ssh" >&2
      ssh "${SSH_OPTS[@]}" "$REMOTE" "rm -rf '$PLUGIN' && mkdir -p '$PLUGIN'"
      tar czf - -C "$ROOT/counter" --exclude='tests' --exclude='.git' --exclude='node_modules' . \
        | ssh "${SSH_OPTS[@]}" "$REMOTE" "tar xzf - -C '$PLUGIN'"
    fi
    echo "pushed. rollback: mv '$PLUGIN.bak-$STAMP' '$PLUGIN'"
    ;;

  selftest)
    # Needs task A0 (wp counter selftest). Non-zero exit on any failing check.
    ssh "${SSH_OPTS[@]}" "$REMOTE" "$WP counter selftest" || { echo "SELFTEST FAILED" >&2; exit 1; }
    ;;

  status)
    ssh "${SSH_OPTS[@]}" "$REMOTE" \
      "$WP plugin get counter --field=version 2>/dev/null || echo 'counter: not installed'; $WP core version; df -h '$CNTR_WP_PATH' | tail -1"
    ;;

  *)
    sed -n '2,12p' "$0"
    exit 2
    ;;
esac
