#!/usr/bin/env bash
# bundle-uninstall.sh — production uninstall (/opt/awg-gui)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
YES=0
REMOVE_IMAGES=0
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
Usage: $0 [--yes] [--images] [--purge] [--help]

  --yes      Skip confirmation
  --images   Also remove local awggui Docker images
  --purge    Also remove ${SCRIPT_DIR}
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --images) REMOVE_IMAGES=1 ;;
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
  read -r -p "Remove awggui containers, volumes, CLI and systemd unit? [y/N]: " ans
  [[ "${ans}" =~ ^[Yy]$ ]] || { echo "Aborted"; exit 0; }
}

confirm_uninstall

compose_down() {
  local args=(down -v --remove-orphans)
  if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
    args+=(--rmi local)
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
}

fallback_remove_network() {
  docker network rm awggui_net 2>/dev/null || true
}

if systemctl list-unit-files 2>/dev/null | grep -q '^awg-gui.service'; then
  systemctl disable --now awg-gui.service 2>/dev/null || true
fi
rm -f /etc/systemd/system/awg-gui.service
systemctl daemon-reload || true
ok "systemd unit removed"

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
ok "CLI and /etc/awg-gui removed"

if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
  docker images --format '{{.Repository}}:{{.Tag}}' | grep -E '^awggui-' | while read -r img; do
    docker rmi -f "${img}" 2>/dev/null || true
  done
  ok "Local awggui images removed"
fi

if [[ "${PURGE}" -eq 1 && -d "${SCRIPT_DIR}" ]]; then
  rm -rf "${SCRIPT_DIR}"
  ok "Removed ${SCRIPT_DIR}"
fi

echo
ok "Production uninstall finished."
