#!/usr/bin/env bash
# Serialize artisan migrate (entrypoint + bundle-install must not run in parallel).
set -euo pipefail

cd /var/www/html
mkdir -p storage/framework

exec flock -w "${AWG_GUI_MIGRATE_LOCK_TIMEOUT:-300}" storage/framework/migrate.lock \
  php artisan migrate --force --no-interaction "$@"
