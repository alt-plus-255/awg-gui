#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# PHP 8.5 + older react/* (ws stack) emit noisy deprecations that slow boot.
mkdir -p /tmp/php-extra
printf 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED\n' >/tmp/php-extra/zz-nodeprec.ini
export PHP_INI_SCAN_DIR="/usr/local/etc/php/conf.d:/tmp/php-extra"

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

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

php artisan migrate --force --no-interaction 2>/dev/null || true

# Start HTTP ASAP so Caddy does not 502 while bootstrap/ws warm up.
php artisan serve --host=0.0.0.0 --port=8000 &
SERVE_PID=$!

php artisan awg:bootstrap --no-interaction 2>/dev/null || true

# Background scheduler (resolver:refresh hourly, etc.)
php artisan schedule:work --verbose &

# AWG live stats WebSocket
php artisan awg:ws-serve &

wait "${SERVE_PID}"
