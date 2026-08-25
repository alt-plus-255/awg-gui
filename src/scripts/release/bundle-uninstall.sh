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

_I18N=""
if [[ -f "${SCRIPT_DIR}/lib/install-i18n.sh" ]]; then
  _I18N="${SCRIPT_DIR}/lib/install-i18n.sh"
elif [[ -f "${SCRIPT_DIR}/../lib/install-i18n.sh" ]]; then
  _I18N="${SCRIPT_DIR}/../lib/install-i18n.sh"
fi
if [[ -n "${_I18N}" ]]; then
  # shellcheck disable=SC1090
  source "${_I18N}"
fi
unset _I18N

usage() {
  if [[ "${AWG_GUI_LANG:-ru}" == "en" ]]; then
    cat <<EOF
Usage: $0 [--yes] [--keep-images] [--images] [--purge] [--lang=ru|en] [--help]

Removes awggui containers, volumes, project images, Docker build cache,
dangling layers, project logs (Docker json logs, update.log, journal),
CLI and systemd unit.

  $(t usage_opt_yes_uninstall)
  $(t usage_opt_keep_images)
  $(t usage_opt_images)
  $(t usage_opt_purge_dir "${SCRIPT_DIR}")
  $(t opt_lang)
EOF
  else
    cat <<EOF
Usage: $0 [--yes] [--keep-images] [--images] [--purge] [--lang=ru|en] [--help]

Удаляет контейнеры awggui, volumes, образы проекта, Docker build cache,
dangling-слои, логи проекта (Docker json logs, update.log, journal),
CLI и unit systemd.

  $(t usage_opt_yes_uninstall)
  $(t usage_opt_keep_images)
  $(t usage_opt_images)
  $(t usage_opt_purge_dir "${SCRIPT_DIR}")
  $(t opt_lang)
EOF
  fi
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --images) REMOVE_IMAGES=1 ;;
    --keep-images) KEEP_IMAGES=1; REMOVE_IMAGES=0 ;;
    --purge) PURGE=1 ;;
    --lang=*) set_awg_gui_lang "${arg#*=}" ;;
    --help|-h) normalize_awg_gui_lang; usage; exit 0 ;;
    *) die "$(t err_unknown_arg "$arg")" ;;
  esac
done

normalize_awg_gui_lang
export AWG_GUI_LANG

[[ "$(id -u)" -eq 0 ]] || die "$(t err_run_as_root)"

select_install_lang
export AWG_GUI_LANG

confirm_uninstall() {
  if [[ "${YES}" -eq 1 ]]; then
    return 0
  fi
  if [[ ! -t 0 && ! -r /dev/tty ]]; then
    log "$(t log_noninteractive_yes)"
    YES=1
    return 0
  fi
  local ans="" hint
  hint="$(confirm_hint n)"
  if [[ -r /dev/tty ]]; then
    printf '%s' "$(t confirm_remove_stack) ${hint}: " > /dev/tty
    read -r ans < /dev/tty || true
  else
    read -r -p "$(t confirm_remove_stack) ${hint}: " ans || true
  fi
  if is_yes_answer "${ans}"; then
    return 0
  fi
  echo "$(t msg_aborted)"
  exit 0
}

confirm_uninstall

remove_project_logs() {
  log "$(t log_removing_logs)"
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
  rm -f /etc/logrotate.d/awg-gui /etc/cron.hourly/awg-gui-logrotate 2>/dev/null || true
  rm -f /etc/awg-gui/*.log /etc/awg-gui/*.log.1 /etc/awg-gui/*.log.1.gz 2>/dev/null || true
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

  while read -r img; do
    [[ -n "${img}" ]] || continue
    docker rmi -f "${img}" 2>/dev/null || true
  done < <(
    docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^(php|docker\.io/library/php)(:|$)' || true
  )
}

prune_docker_junk() {
  log "$(t log_pruning_docker)"
  docker image prune -f >/dev/null 2>&1 || true
  # BuildKit cache is often the largest leftover after awg/golang image builds
  docker builder prune -af >/dev/null 2>&1 || true
}

if systemctl list-unit-files 2>/dev/null | grep -q '^awg-gui.service'; then
  systemctl disable --now awg-gui.service 2>/dev/null || true
fi
rm -f /etc/systemd/system/awg-gui.service
systemctl daemon-reload || true
ok "$(t ok_systemd_removed)"

remove_project_logs

if [[ -f "${COMPOSE_FILE}" ]]; then
  compose_down || true
  ok "$(t ok_compose_down)"
else
  log "$(t log_compose_missing_short)"
  fallback_remove_containers
fi

fallback_remove_containers
fallback_remove_volumes
fallback_remove_network

rm -f /usr/local/bin/awg-gui
rm -rf /etc/awg-gui
ok "$(t ok_cli_etc_removed)"

if [[ "${PURGE}" -ne 1 && -f "${ENV_FILE}" ]]; then
  rm -f "${ENV_FILE}"
  ok "$(t ok_removed_path "${ENV_FILE}")"
fi

if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
  remove_project_images
  prune_docker_junk
  ok "$(t ok_images_cache_removed)"
fi

if [[ "${PURGE}" -eq 1 && -d "${SCRIPT_DIR}" ]]; then
  rm -rf "${SCRIPT_DIR}"
  ok "$(t ok_removed_path "${SCRIPT_DIR}")"
fi

echo
ok "$(t ok_uninstall_finished_prod)"
