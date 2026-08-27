#!/usr/bin/env bash
# preflight.sh — 60-second access audit, driven by .autopilot/ACCESS.yml
#
# Exits 0 if everything the run needs is reachable, 1 if anything is missing.
# Missing items go to BLOCKERS.md and are pushed as a notification, so a run
# that would be blocked at 3am says so at 11pm.
#
# ACCESS.yml format (flat and deliberately dumb — no yaml parser needed):
#
#   env: STRIPE_SECRET_KEY DATABASE_URL SMTP_PASSWORD
#   cmd: node npm psql docker
#   http: https://api.stripe.com/healthcheck https://myapp.test/health
#   tcp: localhost:5432 localhost:6379
#   file: .env wp-config.php

set -uo pipefail
AP="$(pwd)/.autopilot"
ACCESS="$AP/ACCESS.yml"
mkdir -p "$AP"
[[ -f "$ACCESS" ]] || { echo "no ACCESS.yml — nothing to check"; exit 0; }

missing=()
val() { grep -E "^${1}:" "$ACCESS" | head -1 | cut -d: -f2- ; }

for v in $(val env);  do [[ -n "${!v:-}" ]] || missing+=("env var not set: $v"); done
for c in $(val cmd);  do command -v "$c" >/dev/null 2>&1 || missing+=("command not on PATH: $c"); done
for f in $(val file); do [[ -e "$f" ]] || missing+=("file missing: $f"); done

for u in $(grep -E '^http:' "$ACCESS" | head -1 | sed 's/^http://'); do
  curl -fsS --max-time 5 -o /dev/null "$u" 2>/dev/null || missing+=("unreachable: $u")
done

for hp in $(val tcp); do
  h="${hp%%:*}"; p="${hp##*:}"
  timeout 5 bash -c ">/dev/tcp/$h/$p" 2>/dev/null || missing+=("tcp closed: $hp")
done

if [[ ${#missing[@]} -eq 0 ]]; then echo "preflight OK"; exit 0; fi

{ echo; echo "## Preflight $(date '+%F %T')"; printf -- '- %s\n' "${missing[@]}"; } >> "$AP/BLOCKERS.md"
./scripts/notify.sh "PREFLIGHT: ${#missing[@]} access gap(s) — ${missing[0]}" 2>/dev/null || true
printf -- '- %s\n' "${missing[@]}"
exit 1
