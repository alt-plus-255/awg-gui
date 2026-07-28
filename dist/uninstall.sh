#!/usr/bin/env bash
# dist/uninstall.sh — production online uninstaller (wget one-liner entry point)
set -euo pipefail

YES=0
REMOVE_IMAGES=1
KEEP_IMAGES=0
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

Options:
  --yes          Skip confirmation
  --keep-images  Keep awggui Docker images and build cache
  --images       Accepted for compatibility (images are removed by default)
  --purge        Remove ${INSTALL_DIR} after uninstall
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --images) REMOVE_IMAGES=1 ;;
    --keep-images) KEEP_IMAGES=1; REMOVE_IMAGES=0 ;;
    --purge) PURGE=1 ;;
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $arg" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root: curl -fsSL .../dist/uninstall.sh | sudo bash"

ARGS=()
[[ "${YES}" -eq 1 ]] && ARGS+=(--yes)
[[ "${KEEP_IMAGES}" -eq 1 ]] && ARGS+=(--keep-images)
[[ "${REMOVE_IMAGES}" -eq 1 && "${KEEP_IMAGES}" -eq 0 ]] && ARGS+=(--images)
# Do not forward --purge to the bundle when we still need to prune after it returns;
# purge is applied at the end of this script so /opt/awg-gui is removed last.

# curl|bash has no TTY; installed bundle-uninstall.sh may be an older copy
# that cannot prompt and prints "Aborted" on empty stdin.
if [[ "${YES}" -ne 1 && ! -t 0 ]]; then
  log "Non-interactive shell — skipping confirmation (--yes implied for curl|bash)"
  YES=1
  ARGS+=(--yes)
fi

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
  docker builder prune -af >/dev/null 2>&1 || true
}

remove_project_logs() {
  local PROJECT_NAME="${PROJECT_NAME:-awggui}"
  local c logpath

  log "Removing project logs ..."
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
  find /tmp -maxdepth 1 -type d -name 'awg-gui-extract.*' -exec rm -rf {} + 2>/dev/null || true
  find /tmp -maxdepth 1 -type f \( -name 'awg-gui*.log' -o -name 'awg-gui-*.log' \) -delete 2>/dev/null || true

  systemctl reset-failed awg-gui.service 2>/dev/null || true
  if command -v journalctl >/dev/null 2>&1; then
    journalctl --rotate 2>/dev/null || true
    journalctl -u awg-gui.service --flush 2>/dev/null || true
  fi
}

cleanup_project_artifacts() {
  local PROJECT_NAME="${PROJECT_NAME:-awggui}"
  local c v n

  remove_project_logs

  for c in awggui-caddy awggui-app awggui-db awggui-awg awggui-docker-proxy awggui-panel-ops awggui-certbot; do
    docker rm -f "$c" 2>/dev/null || true
  done
  for v in awggui_db_data awggui_awg_config awggui_app_storage; do
    docker volume rm -f "$v" 2>/dev/null || true
  done
  while read -r v; do
    [[ -n "${v}" ]] || continue
    docker volume rm -f "${v}" 2>/dev/null || true
  done < <(docker volume ls -q --filter "label=com.docker.compose.project=${PROJECT_NAME}" 2>/dev/null || true)
  docker network rm awggui_net 2>/dev/null || true
  while read -r n; do
    [[ -n "${n}" ]] || continue
    docker network rm "${n}" 2>/dev/null || true
  done < <(docker network ls -q --filter "label=com.docker.compose.project=${PROJECT_NAME}" 2>/dev/null || true)
  rm -f /usr/local/bin/awg-gui
  rm -rf /etc/awg-gui

  if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
    remove_project_images
    prune_docker_junk
  fi
}

BUNDLE=""
if [[ -x "${INSTALL_DIR}/bundle-uninstall.sh" ]]; then
  BUNDLE="${INSTALL_DIR}/bundle-uninstall.sh"
elif [[ -f /etc/awg-gui/awg-gui.conf ]]; then
  # shellcheck disable=SC1091
  source /etc/awg-gui/awg-gui.conf
  INSTALL_DIR="${INSTALL_ROOT:-${INSTALL_DIR}}"
  if [[ -x "${INSTALL_DIR}/bundle-uninstall.sh" ]]; then
    BUNDLE="${INSTALL_DIR}/bundle-uninstall.sh"
  fi
fi

if [[ -n "${BUNDLE}" ]]; then
  log "Using installed bundle uninstaller ..."
  # Truncate logs first so even an older bundle leaves no fat json logs behind.
  remove_project_logs
  # Run (do not exec) so this fresh script can still prune cache after older bundles.
  "${BUNDLE}" "${ARGS[@]}" || true
  remove_project_logs
  if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
    remove_project_images
    prune_docker_junk
  fi
  if [[ "${PURGE}" -eq 1 && -d "${INSTALL_DIR}" ]]; then
    rm -rf "${INSTALL_DIR}"
    ok "Removed ${INSTALL_DIR}"
  fi
  ok "Uninstall finished."
  exit 0
fi

log "No production install found at ${INSTALL_DIR} — running fallback cleanup ..."

confirm_uninstall() {
  local target_desc ans=""
  if [[ "${YES}" -eq 1 ]]; then
    return 0
  fi
  if [[ ! -t 0 ]]; then
    die "No interactive terminal for confirmation. Re-run with --yes, for example:
  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes"
  fi
  target_desc="AWG GUI"
  if [[ "${PURGE}" -eq 1 ]]; then
    target_desc="${target_desc} and ${INSTALL_DIR}"
  fi
  read -r -p "Are you sure you want to remove ${target_desc}? [Y/n]: " ans
  [[ -z "${ans}" || "${ans}" =~ ^[Yy]$ ]] || { echo "Aborted"; exit 0; }
}

confirm_uninstall

COMPOSE_FILE="${COMPOSE_FILE:-${INSTALL_DIR}/runtime/docker-compose.yml}"
ENV_FILE="${ENV_FILE:-${INSTALL_DIR}/runtime/.env}"
PROJECT_NAME="${PROJECT_NAME:-awggui}"

systemctl disable --now awg-gui.service 2>/dev/null || true
rm -f /etc/systemd/system/awg-gui.service
systemctl daemon-reload 2>/dev/null || true

remove_project_logs

if [[ -f "${COMPOSE_FILE}" ]]; then
  down_args=(down -v --remove-orphans)
  [[ "${REMOVE_IMAGES}" -eq 1 ]] && down_args+=(--rmi all)
  if [[ -f "${ENV_FILE}" ]]; then
    docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "${down_args[@]}" || true
  else
    docker compose -p "${PROJECT_NAME}" -f "${COMPOSE_FILE}" "${down_args[@]}" || true
  fi
fi

cleanup_project_artifacts

if [[ "${PURGE}" -eq 1 && -d "${INSTALL_DIR}" ]]; then
  rm -rf "${INSTALL_DIR}"
fi

ok "Uninstall finished."
