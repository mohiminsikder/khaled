#!/usr/bin/env bash
# notify.sh — push a one-line message to the user's phone.
#
# This is the only channel that works while nobody is at the keyboard, so it must
# never be the thing that fails silently. Every failure falls through to the log,
# and a failed notification never breaks the run.
#
# Pick one and set it in .autopilot/notify.env (gitignored):
#
#   NTFY_TOPIC=some-long-random-string-you-choose
#     -> install the ntfy app, subscribe to that topic. No account needed.
#        Anyone who guesses the topic can read it, so use a random string and
#        never put a secret value in a notification body.
#
#   TELEGRAM_BOT_TOKEN=... and TELEGRAM_CHAT_ID=...   (from @BotFather; private)
#   SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
#
# Usage: ./scripts/notify.sh "BLOCKED: STRIPE_SECRET_KEY not set"

set -uo pipefail
MSG="${*:-autopilot notification}"
AP="$(pwd)/.autopilot"
mkdir -p "$AP"
# shellcheck disable=SC1091
[[ -f "$AP/notify.env" ]] && source "$AP/notify.env"

echo "[$(date '+%F %T')] NOTIFY: $MSG" >> "$AP/notifications.log"
sent=0

if [[ -n "${NTFY_TOPIC:-}" ]]; then
  curl -fsS --max-time 10 -H "Title: Claude Autopilot" -H "Priority: high" \
    -d "$MSG" "https://ntfy.sh/${NTFY_TOPIC}" >/dev/null 2>&1 && sent=1
fi

if [[ -n "${TELEGRAM_BOT_TOKEN:-}" && -n "${TELEGRAM_CHAT_ID:-}" ]]; then
  curl -fsS --max-time 10 -d "chat_id=${TELEGRAM_CHAT_ID}" \
    --data-urlencode "text=${MSG}" \
    "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" >/dev/null 2>&1 && sent=1
fi

if [[ -n "${SLACK_WEBHOOK_URL:-}" ]]; then
  payload=$(jq -nc --arg t "$MSG" '{text:$t}' 2>/dev/null) || payload="{\"text\":\"autopilot\"}"
  curl -fsS --max-time 10 -H 'Content-Type: application/json' \
    -d "$payload" "$SLACK_WEBHOOK_URL" >/dev/null 2>&1 && sent=1
fi

[[ $sent -eq 0 ]] && echo "[$(date '+%F %T')] NOTIFY FAILED (no channel configured): $MSG" >> "$AP/notifications.log"
exit 0
