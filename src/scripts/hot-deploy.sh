#!/usr/bin/env bash
# Hot-deploy code changes without rebuilding running container images (15GB-disk friendly).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_CONTAINER="${APP_CONTAINER:-awggui-app}"
CADDY_CONTAINER="${CADDY_CONTAINER:-awggui-caddy}"
FE_BUILD_TAG="${FE_BUILD_TAG:-awggui-frontend-build:tmp}"

DEPLOY_BACKEND=1
DEPLOY_FRONTEND=1

usage() {
  cat <<'EOF'
Usage: hot-deploy.sh [--backend-only | --frontend-only | --all]

  --backend-only   Copy Laravel PHP sources into awggui-app
  --frontend-only  Build SPA via caddy/Dockerfile (frontend stage) and copy into awggui-caddy
  --all            Backend + frontend (default)

Containers are NOT rebuilt; only files inside running containers are updated.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --backend-only)
      DEPLOY_FRONTEND=0
      ;;
    --frontend-only)
      DEPLOY_BACKEND=0
      ;;
    --all)
      DEPLOY_BACKEND=1
      DEPLOY_FRONTEND=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
  shift
done

deploy_backend() {
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

  if [[ -d "${ROOT}/backend/database/migrations" ]]; then
    echo "==> Backend database/migrations -> ${APP_CONTAINER}"
    docker cp "${ROOT}/backend/database/migrations/." "${APP_CONTAINER}:/var/www/html/database/migrations/"
  fi

  echo "==> Laravel cache clear"
  docker exec "${APP_CONTAINER}" php artisan config:clear --ansi 2>/dev/null || true
  docker exec "${APP_CONTAINER}" php artisan route:clear --ansi 2>/dev/null || true
}

deploy_frontend_via_caddy() {
  echo "==> Build frontend (caddy/Dockerfile stage: frontend)"
  DOCKER_BUILDKIT=1 docker build \
    --target frontend \
    -f "${ROOT}/caddy/Dockerfile" \
    -t "${FE_BUILD_TAG}" \
    "${ROOT}"

  local cid=""
  local tmpdir=""
  cid="$(docker create "${FE_BUILD_TAG}")"
  tmpdir="$(mktemp -d)"
  trap 'docker rm -f "${cid}" >/dev/null 2>&1 || true; rm -rf "${tmpdir}"; docker rmi -f "${FE_BUILD_TAG}" >/dev/null 2>&1 || true' RETURN

  echo "==> Extract frontend dist to ${tmpdir}"
  docker cp "${cid}:/build/dist/spa/." "${tmpdir}/"

  echo "==> Frontend dist/spa -> ${CADDY_CONTAINER}:/srv"
  docker cp "${tmpdir}/." "${CADDY_CONTAINER}:/srv/"

  docker rm -f "${cid}" >/dev/null
  cid=""
  rm -rf "${tmpdir}"
  tmpdir=""
  docker rmi -f "${FE_BUILD_TAG}" >/dev/null 2>&1 || true
  docker builder prune -f >/dev/null 2>&1 || true
  trap - RETURN
}

if [[ "${DEPLOY_BACKEND}" -eq 1 ]]; then
  deploy_backend
fi

if [[ "${DEPLOY_FRONTEND}" -eq 1 ]]; then
  deploy_frontend_via_caddy
fi

echo "==> Done. Running containers were NOT rebuilt."
