#!/usr/bin/env bash
set -euo pipefail

if [[ -d /awg ]]; then
  chmod -R g+rwX /awg 2>/dev/null || true
fi

if [[ -d /host-awg-gui ]]; then
  mkdir -p \
    /host-awg-gui/acme/account \
    /host-awg-gui/acme/pending \
    /host-awg-gui/acme/challenge \
    /host-awg-gui/certs/panel \
    /host-awg-gui/certs/live/panel
  chmod -R a+rwX /host-awg-gui/acme /host-awg-gui/certs 2>/dev/null || true
  for f in Caddyfile webhook.conf update.state update.log; do
    if [[ -e "/host-awg-gui/${f}" ]]; then
      chmod a+rw "/host-awg-gui/${f}" 2>/dev/null || true
    fi
  done
fi

if [[ -n "${DB_HOST:-}" ]]; then
  echo "[app] Waiting for database ${DB_HOST}:${DB_PORT:-3306}..."
  for i in $(seq 1 60); do
    if awgctl ping >/dev/null 2>&1; then
      break
    fi
    sleep 2
  done
fi

awg-migrate-locked || echo "[app] migrate failed (continuing boot)" >&2
awgctl bootstrap || echo "[app] bootstrap failed (continuing boot)" >&2

exec "$@"
