#!/usr/bin/env bash
# awg-gui-uninstall.sh — remove awggui Docker stack + CLI + systemd unit
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
YES=0
REMOVE_IMAGES=0

if [[ -f /etc/awg-gui/awg-gui.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/awg-gui/awg-gui.conf
fi

COMPOSE_FILE="${COMPOSE_FILE:-${SCRIPT_DIR}/src/docker-compose.yml}"
ENV_FILE="${ENV_FILE:-${SCRIPT_DIR}/src/.env}"
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
Usage: $0 [--yes] [--images] [--help]

Removes awggui containers, volumes, CLI and systemd unit.
Paths are read from /etc/awg-gui/awg-gui.conf when present.
Repository files and src/.env are kept. Docker Engine is not removed.

  --yes      Skip confirmation
  --images   Also remove locally built project images
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --images) REMOVE_IMAGES=1 ;;
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
    die "No interactive terminal for confirmation. Re-run with --yes, for example:
  sudo ./awg-gui-uninstall.sh --yes"
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
  for c in awggui-caddy awggui-app awggui-db awggui-awg; do
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
  log "Compose file missing — removing containers by name if present"
  fallback_remove_containers
fi

fallback_remove_containers
fallback_remove_volumes
fallback_remove_network

rm -f /usr/local/bin/awg-gui
rm -rf /etc/awg-gui
ok "CLI and /etc/awg-gui removed"

echo
ok "Uninstall finished. Repository files and src/.env were kept. Docker Engine was not removed."
