#!/usr/bin/env bash
# dist/uninstall.sh — production online uninstaller (wget one-liner entry point)
set -euo pipefail

YES=0
REMOVE_IMAGES=1
KEEP_IMAGES=0
PURGE=0
INSTALL_DIR="${AWG_GUI_INSTALL_DIR:-/opt/awg-gui}"
GITHUB_REPO="${AWG_GUI_GITHUB_REPO:-alt-plus-255/awg-gui}"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

log() { echo -e "${CYAN}[uninstall]${NC} $*" >&2; }
ok() { echo -e "${GREEN}[ok]${NC} $*" >&2; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

load_install_i18n() {
  local base c
  base="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)" || true
  for c in \
    ${base:+"${base}/install-i18n.sh"} \
    ${base:+"${base}/../src/scripts/lib/install-i18n.sh"}
  do
    if [[ -f "${c}" ]]; then
      # shellcheck disable=SC1090
      source "${c}"
      return 0
    fi
  done

  local url tmp
  tmp="$(mktemp /tmp/awg-gui-install-i18n.XXXXXX)"
  for url in \
    "https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/install-i18n.sh" \
    "https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/src/scripts/lib/install-i18n.sh"
  do
    if curl -fsSL "${url}" -o "${tmp}" 2>/dev/null; then
      # shellcheck disable=SC1090
      source "${tmp}"
      rm -f "${tmp}"
      return 0
    fi
  done
  rm -f "${tmp}"
  AWG_GUI_LANG="${AWG_GUI_LANG:-ru}"
  t() { local k="$1"; shift; if [[ $# -gt 0 ]]; then printf -- "$k" "$@"; else printf '%s' "$k"; fi; }
  set_awg_gui_lang() { AWG_GUI_LANG="$1"; AWG_GUI_LANG_EXPLICIT=1; }
  select_install_lang() { :; }
  normalize_awg_gui_lang() { :; }
  is_yes_answer() { case "$1" in y|Y|yes|YES|д|Д|да|ДА) return 0 ;; *) return 1 ;; esac; }
  confirm_hint() { [[ "${1:-n}" == "y" ]] && printf '%s' '[Y/n]' || printf '%s' '[y/N]'; }
}

load_install_i18n

usage() {
  normalize_awg_gui_lang 2>/dev/null || true
  if [[ "${AWG_GUI_LANG:-ru}" == "en" ]]; then
    cat <<EOF
Usage:
  curl -fsSL https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/uninstall.sh | sudo bash
  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes --lang=en

Options:
  $(t usage_opt_yes_uninstall)
  $(t usage_opt_keep_images)
  $(t usage_opt_images)
  $(t usage_opt_purge)
  $(t opt_lang)
EOF
  else
    cat <<EOF
Usage:
  curl -fsSL https://raw.githubusercontent.com/${GITHUB_REPO}/refs/heads/main/dist/uninstall.sh | sudo bash
  curl -fsSL .../dist/uninstall.sh | sudo bash -s -- --yes --lang=en

Options:
  $(t usage_opt_yes_uninstall)
  $(t usage_opt_keep_images)
  $(t usage_opt_images)
  $(t usage_opt_purge)
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
    --dir=*) INSTALL_DIR="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) die "$(t err_unknown_arg "$arg")" ;;
  esac
done

normalize_awg_gui_lang
export AWG_GUI_LANG

[[ "$(id -u)" -eq 0 ]] || die "$(t err_run_as_root_uninstall_curl)"

select_install_lang
export AWG_GUI_LANG

ARGS=()
[[ "${YES}" -eq 1 ]] && ARGS+=(--yes)
[[ "${KEEP_IMAGES}" -eq 1 ]] && ARGS+=(--keep-images)
[[ "${REMOVE_IMAGES}" -eq 1 && "${KEEP_IMAGES}" -eq 0 ]] && ARGS+=(--images)
ARGS+=(--lang="${AWG_GUI_LANG}")
# Do not forward --purge to the bundle when we still need to prune after it returns;
# purge is applied at the end of this script so /opt/awg-gui is removed last.

# curl|bash has no TTY; installed bundle-uninstall.sh may be an older copy
# that cannot prompt and prints "Aborted" on empty stdin.
if [[ "${YES}" -ne 1 && ! -t 0 && ! -r /dev/tty ]]; then
  log "$(t log_noninteractive_yes_curl)"
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
  docker builder prune -af >/dev/null 2>&1 || true
}

remove_project_logs() {
  local PROJECT_NAME="${PROJECT_NAME:-awggui}"
  local c logpath

  log "$(t log_removing_logs)"
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
  rm -f /etc/logrotate.d/awg-gui /etc/cron.hourly/awg-gui-logrotate 2>/dev/null || true
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
  log "$(t log_using_bundle_uninstaller)"
  # Truncate logs first so even an older bundle leaves no fat json logs behind.
  remove_project_logs
  # Run (do not exec) so this fresh script can still prune cache after older bundles.
  export AWG_GUI_LANG
  "${BUNDLE}" "${ARGS[@]}" || true
  remove_project_logs
  if [[ "${REMOVE_IMAGES}" -eq 1 ]]; then
    remove_project_images
    prune_docker_junk
  fi
  if [[ "${PURGE}" -eq 1 && -d "${INSTALL_DIR}" ]]; then
    rm -rf "${INSTALL_DIR}"
    ok "$(t ok_removed_path "${INSTALL_DIR}")"
  fi
  ok "$(t ok_uninstall_finished)"
  exit 0
fi

log "$(t log_no_install_fallback "${INSTALL_DIR}")"

confirm_uninstall() {
  local target_desc ans="" hint
  if [[ "${YES}" -eq 1 ]]; then
    return 0
  fi
  if [[ ! -t 0 && ! -r /dev/tty ]]; then
    die "$(t err_no_tty_uninstall_curl)"
  fi
  target_desc="$(t confirm_target_awg)"
  if [[ "${PURGE}" -eq 1 ]]; then
    target_desc="$(t confirm_target_awg_and_dir "${INSTALL_DIR}")"
  fi
  hint="$(confirm_hint y)"
  if [[ -r /dev/tty ]]; then
    printf '%s' "$(t confirm_remove_target "${target_desc}") ${hint}: " > /dev/tty
    read -r ans < /dev/tty || true
  else
    read -r -p "$(t confirm_remove_target "${target_desc}") ${hint}: " ans || true
  fi
  ans="${ans:-y}"
  if is_yes_answer "${ans}"; then
    return 0
  fi
  echo "$(t msg_aborted)"
  exit 0
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

ok "$(t ok_uninstall_finished)"
