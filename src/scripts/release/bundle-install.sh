#!/usr/bin/env bash
# bundle-install.sh — production install (inside /opt/awg-gui after .run extract)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR="${SCRIPT_DIR}/runtime"
COMPOSE_FILE="${RUNTIME_DIR}/docker-compose.yml"
ENV_FILE="${RUNTIME_DIR}/.env"
ENV_EXAMPLE="${RUNTIME_DIR}/.env.example"
PROJECT_NAME=awggui
YES=0
SKIP_KERNEL=0
DEBUG=0
UPGRADE_MODE=0
REPAIR_MODE=0
BUNDLE_VERSION=""
PANEL_PORT_DEFAULT=8877
AWG_PORT_DEFAULT=51820
INTERNAL_SUBNET_DEFAULT="10.66.66.0/24"
PEER_DNS_DEFAULT="1.1.1.1"
ALLOWED_IPS_DEFAULT="0.0.0.0/0, ::/0"
KERNEL_HOST_SCRIPT_SRC="${SCRIPT_DIR}/host/awg-kernel-host.sh"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

log() { echo -e "${CYAN}[awg-gui-install]${NC} $*"; }
ok() { echo -e "${GREEN}[ok]${NC} $*"; }
warn() { echo -e "${YELLOW}[warn]${NC} $*"; }
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
Usage: $0 [--yes] [--no-awg-kernel] [--debug] [--lang=ru|en] [--help]

Production install: loads pre-built Docker images and starts awggui stack.
Asks about AmneziaWG kernel module (recommended for YouTube/Instagram; default Y).

  $(t usage_opt_yes)
  $(t usage_opt_no_kernel)
  $(t usage_opt_debug)
  $(t opt_lang)
EOF
  else
    cat <<EOF
Usage: $0 [--yes] [--no-awg-kernel] [--debug] [--lang=ru|en] [--help]

Production-установка: загружает готовые Docker-образы и запускает стек awggui.
Спрашивает про модуль ядра AmneziaWG (рекомендуется для YouTube/Instagram; по умолчанию Y).

  $(t usage_opt_yes)
  $(t usage_opt_no_kernel)
  $(t usage_opt_debug)
  $(t opt_lang)
EOF
  fi
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --no-awg-kernel) SKIP_KERNEL=1 ;;
    --debug) DEBUG=1 ;;
    --lang=*) set_awg_gui_lang "${arg#*=}" ;;
    --help|-h) normalize_awg_gui_lang; usage; exit 0 ;;
    *) die "$(t err_unknown_arg "$arg")" ;;
  esac
done

normalize_awg_gui_lang
export AWG_GUI_LANG

if [[ "${AWG_GUI_SKIP_KERNEL:-0}" == "1" ]]; then
  SKIP_KERNEL=1
fi
if [[ "${AWG_GUI_DEBUG:-0}" == "1" ]]; then
  DEBUG=1
fi

[[ "$(id -u)" -eq 0 ]] || die "$(t err_run_as_root)"

# Language was already chosen by online installer / run-header when --lang is explicit;
# otherwise ask here (interactive) before Docker work.
select_install_lang
export AWG_GUI_LANG

compose() {
  docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

_ENSURE_DOCKER=""
if [[ -f "${SCRIPT_DIR}/lib/ensure-docker.sh" ]]; then
  _ENSURE_DOCKER="${SCRIPT_DIR}/lib/ensure-docker.sh"
elif [[ -f "${SCRIPT_DIR}/../lib/ensure-docker.sh" ]]; then
  _ENSURE_DOCKER="${SCRIPT_DIR}/../lib/ensure-docker.sh"
else
  die "$(t err_missing_ensure_docker)"
fi
# shellcheck source=../lib/ensure-docker.sh
source "${_ENSURE_DOCKER}"
unset _ENSURE_DOCKER

ensure_curl() {
  command -v curl >/dev/null 2>&1 || die "$(t err_curl_required)"
  ok "$(t ok_curl_present)"
}

read_tty() {
  local prompt="$1"
  local ans=""
  if [[ -r /dev/tty ]]; then
    printf '%s' "${prompt}" > /dev/tty
    read -r ans < /dev/tty || true
  elif [[ -t 0 ]]; then
    read -r -p "${prompt}" ans || true
  else
    die "$(t err_no_tty_use_yes)"
  fi
  printf '%s' "${ans}"
}

confirm() {
  local msg="$1"
  local default="${2:-n}"
  local ans hint
  if [[ "${YES}" -eq 1 ]]; then
    log "$(t log_confirm_yes "${msg}")"
    return 0
  fi
  hint="$(confirm_hint "${default}")"
  ans="$(read_tty "${msg} ${hint}: ")"
  ans="${ans:-${default}}"
  if is_yes_answer "${ans}"; then
    return 0
  fi
  return 1
}

prompt() {
  local var="$1" msg="$2" def="$3"
  local val
  if [[ "${YES}" -eq 1 ]]; then
    printf -v "${var}" '%s' "${def}"
    return
  fi
  val="$(read_tty "${msg} [${def}]: ")"
  if [[ -z "${val}" ]]; then
    printf -v "${var}" '%s' "${def}"
  else
    printf -v "${var}" '%s' "${val}"
  fi
}

install_awg_kernel_module() {
  mkdir -p /etc/awg-gui
  if [[ -f "${KERNEL_HOST_SCRIPT_SRC}" ]]; then
    install -m 0755 "${KERNEL_HOST_SCRIPT_SRC}" /etc/awg-gui/awg-kernel-host.sh
  else
    warn "$(t warn_kernel_helper_missing "${KERNEL_HOST_SCRIPT_SRC}")"
    env_set "AWG_KERNEL_WANTED" "0" "${ENV_FILE}" 2>/dev/null || true
    return 0
  fi

  if [[ "${SKIP_KERNEL}" -eq 1 ]]; then
    log "$(t log_skip_kernel)"
    env_set "AWG_KERNEL_WANTED" "0" "${ENV_FILE}" 2>/dev/null || true
    return 0
  fi

  local kernel_status=""
  kernel_status="$(/etc/awg-gui/awg-kernel-host.sh status 2>/dev/null || true)"
  if echo "${kernel_status}" | grep -qE '"package_installed":true|"module_loaded":true'; then
    ok "$(t ok_kernel_already)"
    env_set "AWG_KERNEL_WANTED" "1" "${ENV_FILE}" 2>/dev/null || true
    return 0
  fi

  if confirm "$(t confirm_install_kernel)" "y"; then
    env_set "AWG_KERNEL_WANTED" "1" "${ENV_FILE}" 2>/dev/null || true
    log "$(t log_installing_kernel)"
    if /etc/awg-gui/awg-kernel-host.sh install; then
      ok "$(t ok_kernel_installed)"
    else
      warn "$(t warn_kernel_failed)"
    fi
  else
    env_set "AWG_KERNEL_WANTED" "0" "${ENV_FILE}" 2>/dev/null || true
    log "$(t log_kernel_skipped_user)"
  fi
}

env_get() {
  local key="$1" file="$2" default="${3:-}"
  local val=""
  if [[ -f "${file}" ]]; then
    val="$(grep -E "^${key}=" "${file}" 2>/dev/null | tail -1 | cut -d= -f2- || true)"
    if [[ "${val}" =~ ^\"(.*)\"$ ]]; then
      val="${BASH_REMATCH[1]}"
      val="${val//\\\"/\"}"
      val="${val//\\\\/\\}"
    elif [[ "${val}" =~ ^\'(.*)\'$ ]]; then
      val="${BASH_REMATCH[1]}"
    fi
  fi
  if [[ -n "${val}" ]]; then
    echo "${val}"
  else
    echo "${default}"
  fi
}

EXPECTED_CONTAINERS=(
  awggui-caddy awggui-app awggui-db awggui-awg
  awggui-docker-proxy awggui-panel-ops
)

has_awggui_containers() {
  local c names
  names="$(docker ps -a --format '{{.Names}}' 2>/dev/null || true)"
  for c in "${EXPECTED_CONTAINERS[@]}"; do
    if echo "${names}" | grep -qx "${c}"; then
      return 0
    fi
  done
  return 1
}

detect_existing_install() {
  has_awggui_containers && return 0
  if [[ -f "${ENV_FILE}" ]]; then
    [[ -n "$(env_get DB_PASSWORD "${ENV_FILE}")" ]] && return 0
  fi
  return 1
}

detect_install_complete() {
  if [[ -f /etc/awg-gui/install.state ]]; then
    return 0
  fi
  [[ -x /usr/local/bin/awg-gui ]] || return 1
  [[ -f /etc/systemd/system/awg-gui.service ]] || return 1
  local c names
  names="$(docker ps --format '{{.Names}}' 2>/dev/null || true)"
  for c in "${EXPECTED_CONTAINERS[@]}"; do
    echo "${names}" | grep -qx "${c}" || return 1
  done
  return 0
}

detect_incomplete_install() {
  detect_existing_install && ! detect_install_complete
}

choose_install_mode() {
  if ! detect_existing_install; then
    UPGRADE_MODE=0
    REPAIR_MODE=0
    return
  fi

  # Leftover .env after uninstall (no containers): do not reuse old ADMIN_PASSWORD.
  if ! has_awggui_containers; then
    warn "$(t warn_leftover_env "${ENV_FILE}")"
    UPGRADE_MODE=0
    REPAIR_MODE=0
    return
  fi

  if detect_incomplete_install; then
    REPAIR_MODE=1
    UPGRADE_MODE=1
    warn "$(t warn_incomplete_repair)"
    return
  fi

  REPAIR_MODE=0

  if [[ "${YES}" -eq 1 ]]; then
    UPGRADE_MODE=1
    log "$(t log_existing_upgrade_yes)"
    return
  fi

  echo
  warn "$(t warn_existing_install)"
  echo "$(t choice_abort)"
  echo "$(t choice_upgrade)"
  local choice=""
  choice="$(read_tty "$(t prompt_choice_1_2)")"
  case "${choice}" in
    2) UPGRADE_MODE=1 ;;
    *) log "$(t log_install_aborted)"; exit 0 ;;
  esac
}

detect_bundle_version() {
  local tar_file="" version=""
  for tar_file in "${SCRIPT_DIR}"/images/awggui-all-*.tar.gz "${SCRIPT_DIR}"/images/awggui-all-*.tar; do
    [[ -f "${tar_file}" ]] || continue
    version="$(basename "${tar_file}" .tar.gz)"
    version="$(basename "${version}" .tar)"
    version="${version#awggui-all-}"
    BUNDLE_VERSION="${version}"
    return 0
  done
  if [[ -f "${COMPOSE_FILE}" ]]; then
    version="$(grep -Eo 'image:[[:space:]]*awggui-app:[^[:space:]]+' "${COMPOSE_FILE}" | head -1 | sed 's/.*awggui-app://')"
    if [[ -n "${version}" ]]; then
      BUNDLE_VERSION="${version}"
      return 0
    fi
  fi
  return 1
}

# Panel update-runner may die when panel-ops is recreated; finalize its state from the host install.
finalize_running_update_state() {
  local new_status="$1"
  local message="$2"
  local state_file=/etc/awg-gui/update.state
  local raw pid target started finished

  [[ -f "${state_file}" ]] || return 0
  raw="$(cat "${state_file}" 2>/dev/null || true)"
  [[ "${raw}" == *'"status": "running"'* || "${raw}" == *'"status":"running"'* ]] || return 0

  pid="$(printf '%s' "${raw}" | tr -d '\n' | sed -n 's/.*"pid"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p')"
  target="$(printf '%s' "${raw}" | tr -d '\n' | sed -n 's/.*"target_version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
  started="$(printf '%s' "${raw}" | tr -d '\n' | sed -n 's/.*"started_at"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
  finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

  # Keep JSON simple (messages are fixed English strings without quotes).
  cat > "${state_file}" <<EOF
{
  "pid": ${pid:-0},
  "status": "${new_status}",
  "target_version": "${target}",
  "started_at": "${started}",
  "finished_at": "${finished}",
  "message": "${message}"
}
EOF
}

on_install_exit() {
  local ec=$?
  if [[ "${UPGRADE_MODE}" -eq 1 && "${ec}" -ne 0 ]]; then
    finalize_running_update_state "failed" "Update failed with exit code ${ec}."
  fi
}

mark_install_complete() {
  local version="${BUNDLE_VERSION}"
  if [[ -z "${version}" ]]; then
    detect_bundle_version || true
    version="${BUNDLE_VERSION}"
  fi
  mkdir -p /etc/awg-gui
  cat > /etc/awg-gui/install.state <<EOF
completed_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
bundle_version=${version:-unknown}
install_root=${SCRIPT_DIR}
EOF
}

detect_public_ip() {
  local ip=""
  ip="$(curl -4 -fsS --max-time 5 https://ifconfig.me 2>/dev/null || true)"
  ip="$(printf '%s' "${ip}" | tr -d '[:space:]')"
  if [[ ! "${ip}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    ip="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    ip="$(printf '%s' "${ip}" | tr -d '[:space:]')"
  fi
  if [[ ! "${ip}" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  echo "${ip:-127.0.0.1}"
}

resolve_endpoint_host() {
  local endpoint="${1:-}"
  if [[ -z "${endpoint}" || "${endpoint}" == "auto" ]]; then
    detect_public_ip
  else
    printf '%s\n' "${endpoint}"
  fi
}

sync_panel_access_env() {
  local endpoint="$1" panel_port="$2" file="$3"
  local host existing_app_url ssl_enabled
  host="$(resolve_endpoint_host "${endpoint}")"
  existing_app_url="$(env_get APP_URL "${file}" 2>/dev/null || true)"
  ssl_enabled="$(env_get SSL_ENABLED /etc/awg-gui/webhook.conf 2>/dev/null || true)"
  # Drop stale https APP_URL left after a failed/disabled SSL attempt.
  if [[ "${ssl_enabled}" != "1" && "${existing_app_url}" =~ ^https:// ]]; then
    existing_app_url=""
  fi
  if [[ -z "${existing_app_url}" \
     || "${existing_app_url}" == "http://localhost:${panel_port}" \
     || "${existing_app_url}" =~ ^http://[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]]; then
    env_set "APP_URL" "http://${host}:${panel_port}" "${file}"
  fi
  env_set "SANCTUM_STATEFUL_DOMAINS" \
    "${host},${host}:${panel_port},${host}:7443,localhost,localhost:${panel_port},127.0.0.1,127.0.0.1:${panel_port}" \
    "${file}"
  env_set "SESSION_SECURE_COOKIE" "false" "${file}"
}

rand_secret() {
  local len="${1:-24}" raw
  raw="$(dd if=/dev/urandom bs=$((len * 4)) count=1 status=none 2>/dev/null \
    | base64 -w0 2>/dev/null \
    || dd if=/dev/urandom bs=$((len * 4)) count=1 status=none 2>/dev/null | base64 | tr -d '\n')"
  raw="$(printf '%s' "${raw}" | tr -dc 'A-Za-z0-9')"
  printf '%s' "${raw:0:${len}}"
}

gen_app_key() {
  echo "base64:$(head -c 32 /dev/urandom | base64 -w0 2>/dev/null || head -c 32 /dev/urandom | base64)"
}

# Quote values that break `source .env` (e.g. ALLOWED_IPS with "0.0.0.0/0, ::/0").
env_quote_value() {
  local val="$1"
  if [[ "${val}" =~ [[:space:]\#\$\`\"\'\\\&\;\|\(\)\<\>] ]]; then
    val="${val//\\/\\\\}"
    val="${val//\"/\\\"}"
    printf '"%s"' "${val}"
  else
    printf '%s' "${val}"
  fi
}

env_set() {
  local key="$1" val="$2" file="$3"
  local tmp rendered
  rendered="$(env_quote_value "${val}")"
  tmp="$(mktemp)"
  if grep -q "^${key}=" "${file}" 2>/dev/null; then
    awk -v k="${key}" -v v="${rendered}" '
      BEGIN { found=0 }
      $0 ~ "^" k "=" { print k "=" v; found=1; next }
      { print }
      END { if (!found) print k "=" v }
    ' "${file}" > "${tmp}"
  else
    cp "${file}" "${tmp}"
    printf '%s=%s\n' "${key}" "${rendered}" >> "${tmp}"
  fi
  mv "${tmp}" "${file}"
}

env_merge_missing_keys() {
  [[ -f "${ENV_EXAMPLE}" ]] || die "$(t err_missing_path "${ENV_EXAMPLE}")"
  if [[ ! -f "${ENV_FILE}" ]]; then
    cp "${ENV_EXAMPLE}" "${ENV_FILE}"
    chmod 600 "${ENV_FILE}"
    return
  fi
  while IFS= read -r line || [[ -n "${line}" ]]; do
    [[ "${line}" =~ ^[[:space:]]*# ]] && continue
    [[ "${line}" =~ ^[[:space:]]*$ ]] && continue
    if [[ "${line}" =~ ^([A-Za-z_][A-Za-z0-9_]*)= ]]; then
      local key="${BASH_REMATCH[1]}"
      if ! grep -q "^${key}=" "${ENV_FILE}" 2>/dev/null; then
        printf '%s\n' "${line}" >> "${ENV_FILE}"
      fi
    fi
  done < "${ENV_EXAMPLE}"
  chmod 600 "${ENV_FILE}"
}

write_env_from_example() {
  local panel_port="$1" awg_port="$2" endpoint="$3"
  local internal_subnet="$4" peer_dns="$5" allowed_ips="$6"
  local admin_pass="$7" db_pass="$8" app_key="$9"

  [[ -f "${ENV_EXAMPLE}" ]] || die "$(t err_missing_path "${ENV_EXAMPLE}")"
  cp "${ENV_EXAMPLE}" "${ENV_FILE}"
  chmod 600 "${ENV_FILE}"

  env_set "PANEL_PORT" "${panel_port}" "${ENV_FILE}"
  env_set "PANEL_HTTPS_PORT" "7443" "${ENV_FILE}"
  env_set "AWG_PORT" "${awg_port}" "${ENV_FILE}"
  env_set "APP_KEY" "${app_key}" "${ENV_FILE}"
  env_set "ADMIN_PASSWORD" "${admin_pass}" "${ENV_FILE}"
  env_set "DB_PASSWORD" "${db_pass}" "${ENV_FILE}"
  env_set "SERVER_ENDPOINT" "${endpoint}" "${ENV_FILE}"
  env_set "INTERNAL_SUBNET" "${internal_subnet}" "${ENV_FILE}"
  env_set "PEER_DNS" "${peer_dns}" "${ENV_FILE}"
  env_set "ALLOWED_IPS" "${allowed_ips}" "${ENV_FILE}"
  env_set "PANEL_OPS_TOKEN" "$(openssl rand -hex 32 2>/dev/null || rand_secret 64)" "${ENV_FILE}"
  sync_panel_access_env "${endpoint}" "${panel_port}" "${ENV_FILE}"
}

ensure_panel_ops_token() {
  [[ -f "${ENV_FILE}" ]] || return 0
  local token
  token="$(env_get PANEL_OPS_TOKEN "${ENV_FILE}")"
  if [[ -n "${token}" ]]; then
    return 0
  fi
  token="$(openssl rand -hex 32 2>/dev/null || rand_secret 64)"
  env_set "PANEL_OPS_TOKEN" "${token}" "${ENV_FILE}"
  ok "$(t ok_panel_ops_token "${ENV_FILE}")"
}

remove_legacy_certbot_container() {
  docker rm -f awggui-certbot 2>/dev/null || true
}

cleanup_loaded_image_archives() {
  local tar_file removed=0
  for tar_file in "${SCRIPT_DIR}"/images/awggui-all-*.tar.gz "${SCRIPT_DIR}"/images/awggui-all-*.tar; do
    [[ -f "${tar_file}" ]] || continue
    rm -f "${tar_file}"
    removed=1
  done
  if [[ "${removed}" -eq 1 ]]; then
    ok "$(t ok_removed_archives "${SCRIPT_DIR}")"
  fi
}

# Drop previous awggui:* tags left after upgrade. In-use images stay (docker rmi refuses without -f).
cleanup_unused_project_images() {
  local img removed=0
  log "$(t log_removing_unused_images)"
  while read -r img; do
    [[ -n "${img}" ]] || continue
    [[ "${img}" == *":<none>" ]] && continue
    if docker rmi "${img}" >/dev/null 2>&1; then
      removed=$((removed + 1))
      log "$(t log_removed_unused_image "${img}")"
    fi
  done < <(docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^awggui-' || true)

  docker image prune -f >/dev/null 2>&1 || true

  if [[ "${removed}" -gt 0 ]]; then
    ok "$(t ok_removed_n_images "${removed}")"
  else
    ok "$(t ok_no_unused_images)"
  fi
}

cleanup_tmp_install_artifacts() {
  find /tmp -maxdepth 1 -type d \( -name 'awg-gui-install.*' -o -name 'awg-gui-extract.*' \) \
    -exec rm -rf {} + 2>/dev/null || true
  find /tmp -maxdepth 1 -type f \
    \( -name 'awg-gui-install.sh' -o -name 'awg-gui-ensure-docker.*' \
       -o -name 'awg-gui*.log' -o -name 'awg-gui-*.log' \) \
    -delete 2>/dev/null || true
  docker rmi alpine:3.20 >/dev/null 2>&1 || true
}

cleanup_after_install() {
  cleanup_loaded_image_archives
  cleanup_unused_project_images
  cleanup_tmp_install_artifacts
}

load_images() {
  local tar_file=""
  for tar_file in "${SCRIPT_DIR}"/images/awggui-all-*.tar.gz "${SCRIPT_DIR}"/images/awggui-all-*.tar; do
    [[ -f "${tar_file}" ]] && break
  done
  [[ -f "${tar_file}" ]] || die "$(t err_missing_image_archive "${SCRIPT_DIR}")"

  detect_bundle_version || true

  log "$(t log_loading_images "${tar_file}")"
  docker load -i "${tar_file}"
  ok "$(t ok_images_loaded)"
  cleanup_loaded_image_archives
}

seed_host_ssl_files() {
  mkdir -p /etc/awg-gui/certs/panel /etc/awg-gui/certs/live/panel \
    /etc/awg-gui/acme/account /etc/awg-gui/acme/pending /etc/awg-gui/acme/challenge
  # App container writes these as www-data (UID/GID 33 on Debian php images).
  chown -R 33:33 /etc/awg-gui/acme /etc/awg-gui/certs 2>/dev/null || true
  chmod -R a+rwX /etc/awg-gui/acme /etc/awg-gui/certs
  if [[ -f "${RUNTIME_DIR}/caddy/Caddyfile" ]]; then
    cp "${RUNTIME_DIR}/caddy/Caddyfile" /etc/awg-gui/Caddyfile
    chown 33:33 /etc/awg-gui/Caddyfile 2>/dev/null || true
    chmod a+rw /etc/awg-gui/Caddyfile
  fi
}

install_cli_and_systemd() {
  mkdir -p /etc/awg-gui
  install -m 0755 "${RUNTIME_DIR}/bin/awg-gui" /usr/local/bin/awg-gui
  cat > /etc/awg-gui/awg-gui.conf <<EOF
INSTALL_ROOT=${SCRIPT_DIR}
COMPOSE_FILE=${COMPOSE_FILE}
ENV_FILE=${ENV_FILE}
PROJECT_NAME=${PROJECT_NAME}
EOF
  touch /etc/awg-gui/webhook.conf
  chmod 644 /etc/awg-gui/awg-gui.conf
  chown 33:33 /etc/awg-gui/webhook.conf 2>/dev/null || true
  chmod a+rw /etc/awg-gui/webhook.conf
  install -m 0644 "${RUNTIME_DIR}/systemd/awg-gui.service" /etc/systemd/system/awg-gui.service
  systemctl daemon-reload
  systemctl enable --now awg-gui.service
  ok "$(t ok_cli_systemd)"
}

print_helper() {
  local repo="${AWG_GUI_GITHUB_REPO:-alt-plus-255/awg-gui}"
  echo
  echo -e "${BOLD}────────────────────────────────────────${NC}"
  echo -e "${BOLD}$(t helper_management)${NC}"
  echo "  awg-gui help"
  echo "  awg-gui status"
  echo "  awg-gui ensure-up"
  echo
  echo -e "${BOLD}$(t helper_uninstall_prod)${NC}"
  echo "  curl -fsSL https://raw.githubusercontent.com/${repo}/refs/heads/main/dist/uninstall.sh | sudo bash"
  echo -e "${BOLD}────────────────────────────────────────${NC}"
  echo
}

print_install_result_json() {
  local url="$1" port="$2" pass="$3" ok="${4:-true}"
  local pass_json="null"
  if [[ -n "${pass}" ]]; then
    pass_json="$(printf '%s' "${pass}" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))' 2>/dev/null \
      || printf '"%s"' "${pass//\"/\\\"}")"
  fi
  printf 'AWG_GUI_RESULT={"ok":%s,"panel_port":%s,"username":"admin","password":%s,"url":"%s"}\n' \
    "${ok}" "${port}" "${pass_json}" "${url}"
}

print_credentials() {
  local url="$1" port="$2" pass="$3"
  local title
  echo -e "${GREEN}"
  if [[ -n "${pass}" ]]; then
    title="$(t banner_established)"
    cat <<EOF
╔══════════════════════════════════════════════╗
║  ${title}
║  URL:      ${url}
║  Port:     ${port}
║  Login:    admin
║  Password: ${pass}
╚══════════════════════════════════════════════╝
EOF
  else
    title="$(t banner_upgraded)"
    cat <<EOF
╔══════════════════════════════════════════════╗
║  ${title}
║  URL:      ${url}
║  Port:     ${port}
║  Login:    admin
║  Password: $(t banner_password_unchanged)
╚══════════════════════════════════════════════╝
EOF
  fi
  echo -e "${NC}"
  print_install_result_json "${url}" "${port}" "${pass}" "true"
}

wait_for_app() {
  log "$(t log_waiting_app)"
  local i
  for i in $(seq 1 60); do
    if compose exec -T app php -v >/dev/null 2>&1; then
      return 0
    fi
    sleep 3
  done
  warn "$(t warn_app_not_ready)"
  return 1
}

container_state() {
  local name="$1"
  docker inspect -f '{{.State.Status}}' "${name}" 2>/dev/null || true
}

container_health() {
  local name="$1"
  docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "${name}" 2>/dev/null || true
}

wait_for_runtime_services() {
  log "$(t log_waiting_runtime)"
  local attempts="${1:-60}" sleep_sec="${2:-3}" i c state health pending status
  for i in $(seq 1 "${attempts}"); do
    pending=0
    for c in "${EXPECTED_CONTAINERS[@]}"; do
      state="$(container_state "${c}")"
      health="$(container_health "${c}")"
      status="${state}"
      [[ -n "${health}" ]] && status="${status}/${health}"

      if [[ "${state}" != "running" ]]; then
        pending=1
        continue
      fi
      if [[ -n "${health}" && "${health}" != "healthy" ]]; then
        pending=1
        continue
      fi
    done

    if [[ "${pending}" -eq 0 ]]; then
      ok "$(t ok_all_containers)"
      return 0
    fi

    sleep "${sleep_sec}"
  done

  return 1
}

verify_public_http() {
  local panel_port="$1"
  local url="http://127.0.0.1:${panel_port}/api/login/info"
  local body_file http_code attempt
  body_file="$(mktemp)"
  # Caddy/app may still be binding right after compose up — retry quietly.
  for attempt in $(seq 1 20); do
    http_code="$(curl -sS -o "${body_file}" -w '%{http_code}' --max-time 5 "${url}" 2>/dev/null || true)"
    if [[ "${http_code}" == "200" ]] && grep -q '"panel_url"' "${body_file}" 2>/dev/null; then
      rm -f "${body_file}"
      ok "$(t ok_public_api "${url}")"
      return 0
    fi
    sleep 2
  done
  rm -f "${body_file}"
  return 1
}

print_startup_diagnostics() {
  [[ "${DEBUG}" -eq 1 ]] || return 0
  echo
  warn "$(t warn_startup_diag)"
  compose ps || true
  local c
  for c in "${EXPECTED_CONTAINERS[@]}"; do
    echo
    warn "$(t warn_recent_logs "${c}")"
    docker logs --tail 60 "${c}" 2>&1 || true
  done
}

maybe_hint_debug() {
  if [[ "${DEBUG}" -eq 1 ]]; then
    return 0
  fi
  warn "$(t warn_debug_hint)"
}

verify_installation_runtime() {
  local panel_port="$1"

  wait_for_runtime_services 60 3 || {
    print_startup_diagnostics
    maybe_hint_debug
    die "$(t err_services_not_ready)"
  }

  verify_public_http "${panel_port}" || {
    print_startup_diagnostics
    maybe_hint_debug
    die "$(t err_panel_unreachable)"
  }
}

wait_for_migrate_lock() {
  log "$(t log_waiting_migrations)"
  compose exec -T app bash -c '
    mkdir -p /var/www/html/storage/framework
    flock -w "${AWG_GUI_MIGRATE_LOCK_TIMEOUT:-300}" /var/www/html/storage/framework/migrate.lock true
  ' || warn "$(t warn_migrate_lock)"
}

run_migrations() {
  log "$(t log_running_migrations)"
  compose exec -T app awg-migrate-locked
}

run_bootstrap() {
  local panel_port="$1" awg_port="$2" endpoint="$3"
  local internal_subnet="$4" peer_dns="$5" allowed_ips="$6"
  local admin_pass="$7"

  run_migrations

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    # Create admin only if missing; existing password is never overwritten.
    log "$(t log_ensuring_admin_preserve)"
    if [[ -n "${admin_pass}" ]]; then
      compose exec -T \
        -e ADMIN_PASSWORD="${admin_pass}" \
        app php artisan admin:ensure --username=admin --password="${admin_pass}" --email=admin@localhost \
        || warn "$(t warn_admin_ensure_skipped)"
    else
      compose exec -T app php artisan admin:ensure --username=admin --email=admin@localhost \
        || warn "$(t warn_admin_ensure_skipped)"
    fi
  elif [[ -n "${admin_pass}" ]]; then
    log "$(t log_ensuring_admin)"
    compose exec -T \
      -e ADMIN_PASSWORD="${admin_pass}" \
      app php artisan admin:ensure --username=admin --password="${admin_pass}" --email=admin@localhost
  fi

  log "$(t log_bootstrapping_awg)"
  compose exec -T \
    -e SERVER_ENDPOINT="${endpoint}" \
    -e AWG_PORT="${awg_port}" \
    -e PANEL_PORT="${panel_port}" \
    -e PANEL_HTTPS_PORT=7443 \
    -e INTERNAL_SUBNET="${internal_subnet}" \
    -e PEER_DNS="${peer_dns}" \
    -e ALLOWED_IPS="${allowed_ips}" \
    app php artisan awg:bootstrap || true
}

main() {
  [[ -f "${COMPOSE_FILE}" ]] || die "$(t err_missing_path "${COMPOSE_FILE}")"
  [[ -d /dev/net/tun ]] || warn "$(t warn_tun_missing)"

  trap on_install_exit EXIT

  ensure_curl
  ensure_docker_engine
  choose_install_mode

  local panel_port awg_port endpoint internal_subnet peer_dns allowed_ips
  local display_host admin_pass db_pass app_key

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    env_merge_missing_keys
    ok "$(t ok_using_existing_env "${ENV_FILE}")"
    panel_port="$(env_get PANEL_PORT "${ENV_FILE}" "${PANEL_PORT_DEFAULT}")"
    awg_port="$(env_get AWG_PORT "${ENV_FILE}" "${AWG_PORT_DEFAULT}")"
    endpoint="$(env_get SERVER_ENDPOINT "${ENV_FILE}" "auto")"
    [[ -n "${endpoint}" ]] || endpoint="auto"
    if [[ "${REPAIR_MODE}" -eq 1 ]]; then
      endpoint="auto"
      env_set "SERVER_ENDPOINT" "auto" "${ENV_FILE}"
    fi
    internal_subnet="$(env_get INTERNAL_SUBNET "${ENV_FILE}" "${INTERNAL_SUBNET_DEFAULT}")"
    peer_dns="$(env_get PEER_DNS "${ENV_FILE}" "${PEER_DNS_DEFAULT}")"
    allowed_ips="$(env_get ALLOWED_IPS "${ENV_FILE}" "${ALLOWED_IPS_DEFAULT}")"
    admin_pass="$(env_get ADMIN_PASSWORD "${ENV_FILE}")"
  else
    prompt panel_port "$(t prompt_panel_port)" "${PANEL_PORT_DEFAULT}"
    prompt awg_port "$(t prompt_awg_port)" "${AWG_PORT_DEFAULT}"
    prompt endpoint "$(t prompt_endpoint)" "auto"
    prompt internal_subnet "$(t prompt_internal_subnet)" "${INTERNAL_SUBNET_DEFAULT}"
    prompt peer_dns "$(t prompt_peer_dns)" "${PEER_DNS_DEFAULT}"
    prompt allowed_ips "$(t prompt_allowed_ips)" "${ALLOWED_IPS_DEFAULT}"

    admin_pass="$(rand_secret 20)"
    db_pass="$(rand_secret 32)"
    app_key="$(gen_app_key)"

    write_env_from_example \
      "${panel_port}" "${awg_port}" "${endpoint}" \
      "${internal_subnet}" "${peer_dns}" "${allowed_ips}" \
      "${admin_pass}" "${db_pass}" "${app_key}"
    ok "$(t ok_created_env "${ENV_FILE}")"
  fi

  display_host="$(resolve_endpoint_host "${endpoint}")"
  sync_panel_access_env "${endpoint}" "${panel_port}" "${ENV_FILE}"

  mkdir -p /etc/awg-gui
  seed_host_ssl_files
  env_merge_missing_keys
  ensure_panel_ops_token
  install_awg_kernel_module
  remove_legacy_certbot_container
  load_images

  log "$(t log_starting_containers)"
  compose up -d

  wait_for_app || true
  wait_for_migrate_lock
  run_bootstrap \
    "${panel_port}" "${awg_port}" "${endpoint}" \
    "${internal_subnet}" "${peer_dns}" "${allowed_ips}" \
    "${admin_pass:-}"
  verify_installation_runtime "${panel_port}"

  if [[ "${UPGRADE_MODE}" -eq 0 || ! -f /etc/awg-gui/webhook.conf ]] || ! grep -q '^PANEL_PORT=' /etc/awg-gui/webhook.conf 2>/dev/null; then
    cat > /etc/awg-gui/webhook.conf <<EOF
WEBHOOK_URL=
PANEL_PORT=${panel_port}
PANEL_HTTPS_PORT=7443
SERVER_ENDPOINT=${endpoint}
PANEL_DOMAIN=
SSL_ENABLED=0
EOF
  elif [[ "${REPAIR_MODE}" -eq 1 ]]; then
    env_set "SERVER_ENDPOINT" "${endpoint}" /etc/awg-gui/webhook.conf
  fi

  install_cli_and_systemd
  mark_install_complete
  cleanup_after_install

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    finalize_running_update_state "success" "Update completed successfully."
  fi

  local url="http://${display_host}:${panel_port}"
  print_helper
  if [[ "${REPAIR_MODE}" -eq 1 ]]; then
    if [[ -n "${admin_pass}" ]]; then
      print_credentials "${url}" "${panel_port}" "${admin_pass}"
    else
      print_credentials "${url}" "${panel_port}" ""
    fi
    ok "$(t ok_repair_complete)"
  elif [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    print_credentials "${url}" "${panel_port}" ""
    ok "$(t ok_upgrade_complete)"
  else
    print_credentials "${url}" "${panel_port}" "${admin_pass}"
    ok "$(t ok_install_complete)"
  fi
}

main "$@"
