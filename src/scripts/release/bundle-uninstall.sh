#!/usr/bin/env bash
# bundle-uninstall.sh — production uninstall (/opt/awg-gui)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
YES=0
# Images + build cache are always removed so the VDS is left clean.
# --images is accepted for backwards compatibility (no-op).
REMOVE_IMAGES=1
KEEP_IMAGES=0
PURGE=0

if [[ -f /etc/awg-gui/awg-gui.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/awg-gui/awg-gui.conf
fi

COMPOSE_FILE="${COMPOSE_FILE:-${SCRIPT_DIR}/runtime/docker-compose.yml}"
ENV_FILE="${ENV_FILE:-${SCRIPT_DIR}/runtime/.env}"
PROJECT_NAME="${PROJECT_NAME:-awggui}"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

log() { echo -e "${CYAN}[awg-gui-uninstall]${NC} $*"; }
ok() { echo -e "${GREEN}[ok]${NC} $*"; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

usage() {
  cat <<EOF
Usage: $0 [--yes] [--keep-images] [--images] [--purge] [--help]

Removes awggui containers, volumes, project images, Docker build cache,
dangling layers, project logs (Docker json logs, update.log, journal),
CLI and systemd unit.

  --yes          Skip confirmation
  --keep-images  Keep awggui Docker images and build cache
  --images       Accepted for compatibility (images are removed by default)
  --purge        Also remove ${SCRIPT_DIR}
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --images) REMOVE_IMAGES=1 ;;
    --keep-images) KEEP_IMAGES=1; REMOVE_IMAGES=0 ;;
    --purge) PURGE=1 ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $arg" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)"

confirm_uninstall() {
  if [[ "${YES}" -eq 1 ]]; then
    return 0
  fi
  if [[ ! -t 0 ]]; then
    log "Non-interactive shell — skipping confirmation (--yes implied)"
    YES=1
    return 0
  fi
  local ans=""
  read -r -p "Remove awggui stack, volumes, images, logs and build cache? [y/N]: " ans
  [[ "${ans}" =~ ^[Yy]$ ]] || { echo "Aborted"; exit 0; }
}

confirm_uninstall

remove_project_logs() {
  log "Removing project logs ..."
  local c logpath

  while read -r c; do
    [[ -n "${c}" ]] || continue
    logpath="$(docker inspect -f '{{.LogPath}}' "${c}" 2>/dev/null || true)"
    if [[ -n "${logpath}" && -e "${logpath}" ]]; then
      : > "${logpath}" 2>/dev/null || truncate -s 0 "${logpath}" 2>/dev/null || rm -f "${logpath}" 2>/dev/null || true
    fi
  done < <(docker ps -aq --filter "label=com.docker.compose.project=${PROJECT_NAME}" 2>/dev/null || true)

  for c in awggui-caddy awggui-app awggui-db awggui-awg awggui-docker-proxy awggui-panel-ops awggui-certbot; do
    logpath="$(docker inspect -f '{{.LogPath}}' "${c}" 2>/dev/null || true)"
    if [[ -n "${logpath}" && -e "${logpath}" ]]; then
      : > "${logpath}" 2>/dev/null || truncate -s 0 "${logpath}" 2>/dev/null || rm -f "${logpath}" 2>/dev/null || true
    fi
  done

  rm -f /etc/awg-gui/update.log /etc/awg-gui/update.state 2>/dev/null || true
  rm -f /etc/awg-gui/awg-kernel.log /etc/awg-gui/awg-kernel.state 2>/dev/null || true
  rm -f /etc/awg-gui/awg-kernel-host.sh 2>/dev/null || true
  find /tmp -maxdepth 1 -type d -name 'awg-gui-extract.*' -exec rm -rf {} + 2>/dev/null || true
  find /tmp -maxdepth 1 -type f \( -name 'awg-gui*.log' -o -name 'awg-gui-*.log' \) -delete 2>/dev/null || true

  systemctl reset-failed awg-gui.service 2>/dev/null || true
  if command -v journalctl >/dev/null 2>&1; then
    journalctl --rotate 2>/dev/null || true
    journalctl -u awg-gui.service --flush 2>/dev/null || true
  fi
}

compose_down() {
  local args=(down -v --remove-orphans)
  if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
    args+=(--rmi all)
  fi
  if [[ -f "${ENV_FILE}" ]]; then
    docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "${args[@]}"
  else
    docker compose -p "${PROJECT_NAME}" -f "${COMPOSE_FILE}" "${args[@]}"
  fi
}

fallback_remove_containers() {
  local c
  for c in awggui-caddy awggui-app awggui-db awggui-awg awggui-docker-proxy awggui-panel-ops awggui-certbot; do
    docker rm -f "$c" 2>/dev/null || true
  done
}

fallback_remove_volumes() {
  local v
  for v in awggui_db_data awggui_awg_config awggui_app_storage; do
    docker volume rm -f "$v" 2>/dev/null || true
  done
  while read -r v; do
    [[ -n "${v}" ]] || continue
    docker volume rm -f "${v}" 2>/dev/null || true
  done < <(docker volume ls -q --filter "label=com.docker.compose.project=${PROJECT_NAME}" 2>/dev/null || true)
}

fallback_remove_network() {
  docker network rm awggui_net 2>/dev/null || true
  while read -r n; do
    [[ -n "${n}" ]] || continue
    docker network rm "${n}" 2>/dev/null || true
  done < <(docker network ls -q --filter "label=com.docker.compose.project=${PROJECT_NAME}" 2>/dev/null || true)
}

remove_project_images() {
  local img
  while read -r img; do
    [[ -n "${img}" ]] || continue
    docker rmi -f "${img}" 2>/dev/null || true
  done < <(docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^awggui-' || true)

  while read -r img; do
    [[ -n "${img}" ]] || continue
    docker rmi -f "${img}" 2>/dev/null || true
  done < <(docker images --format '{{.ID}} {{.Repository}}' 2>/dev/null | awk '$2 ~ /^awggui-/ { print $1 }' || true)
}

prune_docker_junk() {
  log "Pruning dangling images and Docker build cache ..."
  docker image prune -f >/dev/null 2>&1 || true
  # BuildKit cache is often the largest leftover after awg/golang image builds
  docker builder prune -af >/dev/null 2>&1 || true
}

if systemctl list-unit-files 2>/dev/null | grep -q '^awg-gui.service'; then
  systemctl disable --now awg-gui.service 2>/dev/null || true
fi
rm -f /etc/systemd/system/awg-gui.service
systemctl daemon-reload || true
ok "systemd unit removed"

remove_project_logs

if [[ -f "${COMPOSE_FILE}" ]]; then
  compose_down || true
  ok "compose down -v completed"
else
  log "Compose file missing — removing containers by name"
  fallback_remove_containers
fi

fallback_remove_containers
fallback_remove_volumes
fallback_remove_network

rm -f /usr/local/bin/awg-gui
rm -rf /etc/awg-gui
ok "CLI, /etc/awg-gui and project logs removed"

if [[ "${PURGE}" -ne 1 && -f "${ENV_FILE}" ]]; then
  rm -f "${ENV_FILE}"
  ok "Removed ${ENV_FILE}"
fi

if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
  remove_project_images
  prune_docker_junk
  ok "Project images, dangling layers and build cache removed"
fi

if [[ "${PURGE}" -eq 1 && -d "${SCRIPT_DIR}" ]]; then
  rm -rf "${SCRIPT_DIR}"
  ok "Removed ${SCRIPT_DIR}"
fi

echo
ok "Production uninstall finished."
