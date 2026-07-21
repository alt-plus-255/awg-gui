#!/usr/bin/env bash
# Hot-deploy code changes without rebuilding Docker images (safe for small disks).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_CONTAINER="${APP_CONTAINER:-awggui-app}"
CADDY_CONTAINER="${CADDY_CONTAINER:-awggui-caddy}"

echo "==> Backend PHP (app/) -> ${APP_CONTAINER}"
docker cp "${ROOT}/backend/app/." "${APP_CONTAINER}:/var/www/html/app/"

if [[ -d "${ROOT}/backend/lang" ]]; then
  echo "==> Backend lang/ -> ${APP_CONTAINER}"
  docker cp "${ROOT}/backend/lang/." "${APP_CONTAINER}:/var/www/html/lang/"
fi

echo "==> Backend bootstrap -> ${APP_CONTAINER}"
docker cp "${ROOT}/backend/bootstrap/app.php" "${APP_CONTAINER}:/var/www/html/bootstrap/app.php"

echo "==> Backend routes -> ${APP_CONTAINER}"
docker cp "${ROOT}/backend/routes/." "${APP_CONTAINER}:/var/www/html/routes/"

echo "==> Laravel cache clear"
docker exec "${APP_CONTAINER}" php artisan config:clear --ansi 2>/dev/null || true
docker exec "${APP_CONTAINER}" php artisan route:clear --ansi 2>/dev/null || true

if [[ -d "${ROOT}/frontend/dist/spa" ]]; then
  echo "==> Frontend dist/spa -> ${CADDY_CONTAINER}:/srv"
  docker cp "${ROOT}/frontend/dist/spa/." "${CADDY_CONTAINER}:/srv/"
else
  echo "==> Skip frontend: run 'npm run build' in frontend/ first"
fi

echo "==> Done. Containers were NOT rebuilt."
