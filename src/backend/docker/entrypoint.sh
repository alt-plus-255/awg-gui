#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# PHP 8.5 + older react/* (ws stack) emit noisy deprecations that slow boot.
mkdir -p /tmp/php-extra
printf 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED\n' >/tmp/php-extra/zz-nodeprec.ini
export PHP_INI_SCAN_DIR="/usr/local/etc/php/conf.d:/tmp/php-extra"

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

if [[ -d /awg ]]; then
  chown -R www-data:www-data /awg 2>/dev/null || chmod -R g+rwX /awg 2>/dev/null || true
fi

<<<<<<< HEAD
=======
# Host bind-mount (/etc/awg-gui → /host-awg-gui) is created as root by the installer.
# App writes ACME keys, certs, Caddyfile, webhook.conf and update state as www-data.
if [[ -d /host-awg-gui ]]; then
  mkdir -p \
    /host-awg-gui/acme/account \
    /host-awg-gui/acme/pending \
    /host-awg-gui/acme/challenge \
    /host-awg-gui/certs/panel \
    /host-awg-gui/certs/live/panel
  if ! chown -R www-data:www-data /host-awg-gui/acme /host-awg-gui/certs 2>/dev/null; then
    chmod -R a+rwX /host-awg-gui/acme /host-awg-gui/certs 2>/dev/null || true
  fi
  for f in Caddyfile webhook.conf update.state update.log; do
    if [[ -e "/host-awg-gui/${f}" ]]; then
      chown www-data:www-data "/host-awg-gui/${f}" 2>/dev/null || chmod a+rw "/host-awg-gui/${f}" 2>/dev/null || true
    fi
  done
fi

>>>>>>> a34ec4d81547d4963b761827020a578f3957b1c6
run_www_data() {
  su -s /bin/bash www-data -c "$*"
}

# Wait for DB
if [[ -n "${DB_HOST:-}" ]]; then
  echo "[app] Waiting for database ${DB_HOST}:${DB_PORT:-3306}..."
  for i in $(seq 1 60); do
    if php -r "try { new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT')?:3306), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0);} catch(Exception \$e){ exit(1);}"; then
      break
    fi
    sleep 2
  done
fi

awg-migrate-locked || echo "[app] migrate failed (continuing boot)" >&2

# Start HTTP ASAP so Caddy does not 502 while bootstrap/ws warm up.
run_www_data "php artisan serve --host=0.0.0.0 --port=8000" &
SERVE_PID=$!

run_www_data "php artisan awg:bootstrap --no-interaction" 2>/dev/null || true

# Background scheduler (resolver:refresh hourly, etc.)
<<<<<<< HEAD
run_www_data "php artisan schedule:work --verbose" &

# AWG live stats WebSocket
run_www_data "php artisan awg:ws-serve" &
=======
run_www_data "php artisan schedule:work" &

# Kick first community-list sync early so a fresh install is ready before the minute tick
run_www_data "php artisan resolver:sync-lists" &

# AWG live stats WebSocket
run_www_data "php artisan awg:ws-serve" &

# Telegram bot long-polling worker (idles when webhook mode / not configured)
run_www_data "php artisan telegram:bot" &
>>>>>>> a34ec4d81547d4963b761827020a578f3957b1c6

wait "${SERVE_PID}"
