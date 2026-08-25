#!/usr/bin/env bash
# Rebuild/restart the Go app container (and optionally frontend) without a full stack rebuild.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_CONTAINER="${APP_CONTAINER:-awggui-app}"
CADDY_CONTAINER="${CADDY_CONTAINER:-awggui-caddy}"
FE_BUILD_TAG="${FE_BUILD_TAG:-awggui-frontend-build:tmp}"
COMPOSE_FILE="${COMPOSE_FILE:-${ROOT}/docker-compose.yml}"
ENV_FILE="${ENV_FILE:-${ROOT}/.env}"
PROJECT_NAME="${PROJECT_NAME:-awggui}"

DEPLOY_BACKEND=1
DEPLOY_FRONTEND=1

usage() {
  cat <<'EOF'
Usage: hot-deploy.sh [--backend-only | --frontend-only | --all]

  --backend-only   Rebuild awggui-app (Go) image and recreate the container
  --frontend-only  Build SPA via caddy/Dockerfile (frontend stage) and copy into awggui-caddy
  --all            Backend + frontend (default)
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

compose() {
  if [[ -f "${ENV_FILE}" ]]; then
    docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
  else
    docker compose -p "${PROJECT_NAME}" -f "${COMPOSE_FILE}" "$@"
  fi
}

deploy_backend() {
  echo "==> Rebuild Go backend image and recreate ${APP_CONTAINER}"
  compose build app
  compose up -d --no-deps --force-recreate app
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

echo "==> Done."
