#!/usr/bin/env bash
# dist/uninstall.sh — production online uninstaller (wget one-liner entry point)
set -euo pipefail

YES=0
REMOVE_IMAGES=0
PURGE=0
INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

log() { echo -e "${CYAN}[uninstall]${NC} $*" >&2; }
ok() { echo -e "${GREEN}[ok]${NC} $*" >&2; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

usage() {
  cat <<EOF
Usage:
  curl -fsSL https://raw.githubusercontent.com/alt-plus-255/awg-gui/refs/heads/main/dist/uninstall.sh | sudo bash
  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --images

Options:
  --yes      Skip confirmation
  --images   Also remove local awggui Docker images
  --purge    Remove ${INSTALL_DIR} after uninstall
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --images) REMOVE_IMAGES=1 ;;
    --purge) PURGE=1 ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $arg" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root: curl -fsSL .../dist/uninstall.sh | sudo bash"

ARGS=()
[[ "${YES}" -eq 1 ]] && ARGS+=(--yes)
[[ "${REMOVE_IMAGES}" -eq 1 ]] && ARGS+=(--images)
[[ "${PURGE}" -eq 1 ]] && ARGS+=(--purge)

# curl|bash has no TTY; installed bundle-uninstall.sh may be an older copy
# that cannot prompt and prints "Aborted" on empty stdin.
if [[ "${YES}" -ne 1 && ! -t 0 ]]; then
  log "Non-interactive shell — skipping confirmation (--yes implied for curl|bash)"
  YES=1
  ARGS+=(--yes)
fi

if [[ -x "${INSTALL_DIR}/bundle-uninstall.sh" ]]; then
  log "Using installed bundle uninstaller ..."
  exec "${INSTALL_DIR}/bundle-uninstall.sh" "${ARGS[@]}"
fi

if [[ -f /etc/awg-gui/awg-gui.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/awg-gui/awg-gui.conf
  INSTALL_DIR="${INSTALL_ROOT:-${INSTALL_DIR}}"
  if [[ -x "${INSTALL_DIR}/bundle-uninstall.sh" ]]; then
    exec "${INSTALL_DIR}/bundle-uninstall.sh" "${ARGS[@]}"
  fi
fi

log "No production install found at ${INSTALL_DIR} — running fallback cleanup ..."

confirm_uninstall() {
  if [[ "${YES}" -eq 1 ]]; then
    return 0
  fi
  if [[ ! -t 0 ]]; then
    die "No interactive terminal for confirmation. Re-run with --yes, for example:
  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes"
  fi
  local ans=""
  read -r -p "Remove awggui containers, volumes, CLI and systemd? [y/N]: " ans
  [[ "${ans}" =~ ^[Yy]$ ]] || { echo "Aborted"; exit 0; }
}

confirm_uninstall

COMPOSE_FILE="${COMPOSE_FILE:-${INSTALL_DIR}/runtime/docker-compose.yml}"
ENV_FILE="${ENV_FILE:-${INSTALL_DIR}/runtime/.env}"
PROJECT_NAME="${PROJECT_NAME:-awggui}"

systemctl disable --now awg-gui.service 2>/dev/null || true
rm -f /etc/systemd/system/awg-gui.service
systemctl daemon-reload 2>/dev/null || true

if [[ -f "${COMPOSE_FILE}" ]]; then
  down_args=(down -v --remove-orphans)
  [[ "${REMOVE_IMAGES}" -eq 1 ]] && down_args+=(--rmi local)
  if [[ -f "${ENV_FILE}" ]]; then
    docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "${down_args[@]}" || true
  else
    docker compose -p "${PROJECT_NAME}" -f "${COMPOSE_FILE}" "${down_args[@]}" || true
  fi
fi

for c in awggui-caddy awggui-app awggui-db awggui-awg; do docker rm -f "$c" 2>/dev/null || true; done
for v in awggui_db_data awggui_awg_config awggui_app_storage; do docker volume rm -f "$v" 2>/dev/null || true; done
docker network rm awggui_net 2>/dev/null || true
rm -f /usr/local/bin/awg-gui
rm -rf /etc/awg-gui

if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
  docker images --format '{{.Repository}}:{{.Tag}}' | grep -E '^awggui-' | while read -r img; do
    docker rmi -f "${img}" 2>/dev/null || true
  done
fi

if [[ "${PURGE}" -eq 1 && -d "${INSTALL_DIR}" ]]; then
  rm -rf "${INSTALL_DIR}"
fi

ok "Uninstall finished."
