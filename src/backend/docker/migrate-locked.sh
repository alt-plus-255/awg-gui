#!/usr/bin/env bash
# Serialize migrate (entrypoint + bundle-install must not run in parallel).
set -euo pipefail

mkdir -p /var/lock

exec flock -w "${AWG_GUI_MIGRATE_LOCK_TIMEOUT:-300}" /var/lock/awg-migrate.lock \
  awgctl migrate "$@"
