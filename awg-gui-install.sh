#!/usr/bin/env bash
# awg-gui-install.sh — install AmneziaWG GUI stack only
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="${SCRIPT_DIR}/src"
COMPOSE_FILE="${SRC_DIR}/docker-compose.yml"
ENV_FILE="${SRC_DIR}/.env"
PROJECT_NAME=awggui
SING_BOX_VERSION=1.13.14
YES=0
SKIP_KERNEL=0
UPGRADE_MODE=0
REPAIR_MODE=0
PANEL_PORT_DEFAULT=8877
PANEL_HTTPS_PORT_DEFAULT=7443
AWG_PORT_DEFAULT=51820
INTERNAL_SUBNET_DEFAULT="10.66.66.0/24"
PEER_DNS_DEFAULT="1.1.1.1"
ALLOWED_IPS_DEFAULT="0.0.0.0/0, ::/0"
KERNEL_HOST_SCRIPT_SRC="${SRC_DIR}/scripts/host/awg-kernel-host.sh"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

log() { echo -e "${CYAN}[awg-gui-install]${NC} $*" >&2; }
ok() { echo -e "${GREEN}[ok]${NC} $*" >&2; }
warn() { echo -e "${YELLOW}[warn]${NC} $*" >&2; }
die() { echo -e "${RED}[error]${NC} $*" >&2; exit 1; }

# shellcheck source=src/scripts/lib/install-i18n.sh
source "${SCRIPT_DIR}/src/scripts/lib/install-i18n.sh"
# shellcheck source=src/scripts/lib/install-ports.sh
source "${SCRIPT_DIR}/src/scripts/lib/install-ports.sh"
# shellcheck source=src/scripts/lib/install-force-container.sh
source "${SCRIPT_DIR}/src/scripts/lib/install-force-container.sh"

usage() {
  if [[ "${AWG_GUI_LANG:-ru}" == "en" ]]; then
    cat <<EOF
Usage: $0 [--yes] [--no-awg-kernel] [--lang=ru|en] [--help]

Installs AmneziaWG 2.0 + Go API + Quasar admin (Docker project awggui).
Before installing missing system packages (curl/jq, Docker) asks y/N
(unless --yes). Downloads sing-box vendor tarball for AWG image if missing.
Then prompts: panel port, AWG port, endpoint, subnet, DNS, AllowedIPs.
Also asks about AmneziaWG kernel module (recommended for YouTube/Instagram; default Y).
Creates src/.env from src/.env.example with random DB password (fresh install).

If an existing install is detected, offers abort or upgrade (with --yes: upgrade).
Upgrade keeps .env, volumes and database/AWG data; rebuilds images and runs migrations.

  $(t usage_opt_yes)
  $(t usage_opt_no_kernel)
  $(t opt_lang)

Management after install: awg-gui help
Uninstall: ./awg-gui-uninstall.sh
EOF
  else
    cat <<EOF
Usage: $0 [--yes] [--no-awg-kernel] [--lang=ru|en] [--help]

Устанавливает AmneziaWG 2.0 + Go API + Quasar admin (Docker-проект awggui).
Перед установкой недостающих пакетов (curl/jq, Docker) спрашивает y/N
(если не указан --yes). Скачивает tarball sing-box для образа AWG, если его нет.
Затем спрашивает: порт панели, AWG-порт, endpoint, подсеть, DNS, AllowedIPs.
Также спрашивает про модуль ядра AmneziaWG (рекомендуется для YouTube/Instagram; по умолчанию Y).
Создаёт src/.env из src/.env.example со случайным паролем БД (чистая установка).

При обнаружении существующей установки предлагает прервать или обновить (с --yes: обновление).
Обновление сохраняет .env, volumes и данные БД/AWG; пересобирает образы и выполняет миграции.

  $(t usage_opt_yes)
  $(t usage_opt_no_kernel)
  $(t opt_lang)

Управление после установки: awg-gui help
Удаление: ./awg-gui-uninstall.sh
EOF
  fi
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --no-awg-kernel) SKIP_KERNEL=1 ;;
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

[[ "$(id -u)" -eq 0 ]] || die "$(t err_run_as_root)"

select_install_lang
export AWG_GUI_LANG

compose() {
  env -u DOCKER_HOST docker compose -p "${PROJECT_NAME}" \
    --project-directory "${SRC_DIR}" \
    --env-file "${ENV_FILE}" \
    -f "${COMPOSE_FILE}" "$@"
}
export PROJECT_NAME ENV_FILE COMPOSE_FILE SRC_DIR

detect_os() {
  # shellcheck disable=SC1091
  source /etc/os-release
  OS_ID="${ID:-}"
  OS_VERSION_CODENAME="${VERSION_CODENAME:-}"
  OS_VERSION_ID="${VERSION_ID:-}"
}

detect_arch() {
  local m
  m="$(uname -m)"
  case "$m" in
    x86_64|amd64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    armv7l) ARCH=armhf ;;
    *) die "$(t err_unsupported_arch "$m")" ;;
  esac
}

sing_box_arch() {
  case "${ARCH}" in
    amd64) echo amd64 ;;
    arm64) echo arm64 ;;
    armhf) echo armv7 ;;
    *) die "$(t err_unsupported_singbox_arch "${ARCH}")" ;;
  esac
}

ensure_curl() {
  local need_curl=0 need_jq=0
  command -v curl >/dev/null 2>&1 || need_curl=1
  command -v jq >/dev/null 2>&1 || need_jq=1

  if [[ "${need_curl}" -eq 0 ]]; then
    ok "$(t ok_curl_present)"
  fi
  if [[ "${need_curl}" -eq 0 && "${need_jq}" -eq 0 ]]; then
    return
  fi

  local missing=()
  [[ "${need_curl}" -eq 1 ]] && missing+=("curl")
  [[ "${need_jq}" -eq 1 ]] && missing+=("jq")
  local list
  list="$(printf '%s, ' "${missing[@]}")"
  list="${list%, }"

  if ! confirm "$(t confirm_install_packages "${list}")"; then
    [[ "${need_curl}" -eq 1 ]] && die "$(t err_curl_manual)"
    warn "$(t warn_jq_optional)"
    return
  fi

  log "$(t log_installing_packages "${list}")"
  detect_os
  case "${OS_ID}" in
    ubuntu|debian|raspbian)
      apt-get update -y
      apt-get install -y curl ca-certificates jq
      ;;
    centos|rhel|rocky|almalinux|fedora)
      if command -v dnf >/dev/null 2>&1; then dnf install -y curl ca-certificates jq
      else yum install -y curl ca-certificates jq; fi
      ;;
    *)
      command -v curl >/dev/null 2>&1 || die "$(t err_cannot_install_curl "${OS_ID}")"
      warn "$(t warn_jq_optional)"
      ;;
  esac
  command -v curl >/dev/null 2>&1 || die "$(t err_curl_install_failed)"
  ok "$(t ok_curl_ready)"
}

ensure_sing_box_vendor() {
  detect_arch
  local sb_arch dest url
  sb_arch="$(sing_box_arch)"
  dest="${SRC_DIR}/awg/vendor/sing-box-${SING_BOX_VERSION}-linux-${sb_arch}.tar.gz"
  if [[ -f "${dest}" ]]; then
    ok "$(t ok_singbox_present "${dest}")"
    return
  fi

  url="https://github.com/SagerNet/sing-box/releases/download/v${SING_BOX_VERSION}/sing-box-${SING_BOX_VERSION}-linux-${sb_arch}.tar.gz"
  if ! confirm "$(t confirm_download_singbox "${dest}")"; then
    die "$(t err_singbox_required)"
  fi

  mkdir -p "${SRC_DIR}/awg/vendor"
  log "$(t log_downloading_singbox "${SING_BOX_VERSION}" "${sb_arch}")"
  curl -fsSL -o "${dest}" "${url}"
  ok "$(t ok_downloaded "${dest}")"
}

# shellcheck source=src/scripts/lib/ensure-docker.sh
source "${SCRIPT_DIR}/src/scripts/lib/ensure-docker.sh"

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

  local pkg_installed=0 mod_loaded=0 blacklisted=0
  if echo "${kernel_status}" | grep -qE '"package_installed":true'; then
    pkg_installed=1
  fi
  if echo "${kernel_status}" | grep -qE '"module_loaded":true'; then
    mod_loaded=1
  fi
  if echo "${kernel_status}" | grep -qE '"module_blacklisted":true'; then
    blacklisted=1
  fi
  # Fallback if status JSON missing (helper error) — still skip prompt when module/package on host.
  if [[ -z "${kernel_status}" ]]; then
    lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg && mod_loaded=1
    if dpkg -s amneziawg >/dev/null 2>&1 || dpkg -s amneziawg-dkms >/dev/null 2>&1; then
      pkg_installed=1
    fi
    if rpm -q amneziawg-dkms >/dev/null 2>&1 || rpm -q amneziawg >/dev/null 2>&1; then
      pkg_installed=1
    fi
    [[ -f /etc/modprobe.d/blacklist-amneziawg.conf ]] && blacklisted=1
  fi

  if [[ "${mod_loaded}" -eq 1 ]]; then
    ok "$(t ok_kernel_already)"
    env_set "AWG_KERNEL_WANTED" "1" "${ENV_FILE}" 2>/dev/null || true
    return 0
  fi
  if [[ "${pkg_installed}" -eq 1 ]]; then
    ok "$(t ok_kernel_already)"
    if [[ "${blacklisted}" -eq 1 ]]; then
      env_set "AWG_KERNEL_WANTED" "0" "${ENV_FILE}" 2>/dev/null || true
    else
      env_set "AWG_KERNEL_WANTED" "1" "${ENV_FILE}" 2>/dev/null || true
    fi
    return 0
  fi

  # Non-interactive upgrade (GUI / --yes): never force-install kernel if it was not present.
  # Only install when the user previously opted in (AWG_KERNEL_WANTED=1).
  if [[ "${YES}" -eq 1 && "${UPGRADE_MODE}" -eq 1 ]]; then
    local wanted
    wanted="$(env_get "AWG_KERNEL_WANTED" "${ENV_FILE}" "0")"
    if [[ "${wanted}" != "1" ]]; then
      log "$(t log_kernel_skip_upgrade_not_installed)"
      env_set "AWG_KERNEL_WANTED" "0" "${ENV_FILE}" 2>/dev/null || true
      return 0
    fi
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
  echo "$(t choice_abort_recommend)"
  echo "$(t choice_upgrade)"
  local choice=""
  choice="$(read_tty "$(t prompt_choice_1_2)")"
  case "${choice}" in
    2) UPGRADE_MODE=1 ;;
    *) log "$(t log_install_aborted)"; exit 0 ;;
  esac
}

mark_install_complete() {
  mkdir -p /etc/awg-gui
  cat > /etc/awg-gui/install.state <<EOF
completed_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
bundle_version=source
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

# Resolve "auto"/empty to the public IPv4 of this host (for panel URL / APP_URL).
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
  local host existing_app_url panel_domain https_port domains app_host
  host="$(resolve_endpoint_host "${endpoint}")"
  existing_app_url="$(env_get APP_URL "${file}" 2>/dev/null || true)"
  panel_domain="$(env_get PANEL_DOMAIN /etc/awg-gui/webhook.conf 2>/dev/null || true)"
  https_port="$(env_get PANEL_HTTPS_PORT "${file}" 2>/dev/null || true)"
  if [[ -z "${https_port}" ]]; then
    https_port="$(env_get PANEL_HTTPS_PORT /etc/awg-gui/webhook.conf 2>/dev/null || true)"
  fi
  https_port="${https_port:-${PANEL_HTTPS_PORT_DEFAULT}}"
  # Do not clobber an existing HTTPS/domain APP_URL (panel SSL / custom domain).
  if [[ -z "${existing_app_url}" \
     || "${existing_app_url}" == "http://localhost:${panel_port}" \
     || "${existing_app_url}" =~ ^http://[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]]; then
    env_set "APP_URL" "http://${host}:${panel_port}" "${file}"
    existing_app_url="http://${host}:${panel_port}"
  fi
  domains="${host},${host}:${panel_port},${host}:${https_port},localhost,localhost:${panel_port},localhost:${https_port},127.0.0.1,127.0.0.1:${panel_port},127.0.0.1:${https_port}"
  if [[ -n "${panel_domain}" ]]; then
    domains="${panel_domain},${panel_domain}:${panel_port},${panel_domain}:${https_port},${domains}"
  fi
  if [[ "${existing_app_url}" =~ ^https://([^/:]+) ]]; then
    app_host="${BASH_REMATCH[1]}"
    if [[ -n "${app_host}" && "${domains}" != *"${app_host}:${https_port}"* ]]; then
      domains="${app_host},${app_host}:${panel_port},${app_host}:${https_port},${domains}"
    fi
  fi
  env_set "SANCTUM_STATEFUL_DOMAINS" "${domains}" "${file}"
}

rand_secret() {
  # Avoid `tr | head` under `set -o pipefail` — head closes the pipe and
  # tr gets SIGPIPE (exit 141), which aborts the whole install.
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
  # Never write multiline values — they break Docker Compose .env parsing.
  val="${val//$'\n'/ }"
  val="${val//$'\r'/}"
  if [[ "${val}" =~ [[:space:]\#\$\`\"\'\\\&\;\|\(\)\<\>] ]]; then
    val="${val//\\/\\\\}"
    val="${val//\"/\\\"}"
    printf '"%s"' "${val}"
  else
    printf '%s' "${val}"
  fi
}

# Set KEY=VALUE in .env (creates from .env.example first)
env_set() {
  local key="$1"
  local val="$2"
  local file="$3"
  local tmp rendered
  rendered="$(env_quote_value "${val}")"
  tmp="$(mktemp)"
  if grep -q "^${key}=" "${file}" 2>/dev/null; then
    # Replace key and drop orphan continuation lines from a prior multiline write.
    awk -v k="${key}" -v v="${rendered}" '
      BEGIN { found=0; skipping=0 }
      skipping {
        if ($0 ~ /^[A-Za-z_][A-Za-z0-9_]*=/ || $0 ~ /^[[:space:]]*#/ || $0 ~ /^[[:space:]]*$/) {
          skipping=0
        } else {
          next
        }
      }
      $0 ~ "^" k "=" { print k "=" v; found=1; skipping=1; next }
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
  [[ -f "${SRC_DIR}/.env.example" ]] || die "$(t err_missing_path "${SRC_DIR}/.env.example")"
  if [[ ! -f "${ENV_FILE}" ]]; then
    cp "${SRC_DIR}/.env.example" "${ENV_FILE}"
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
  done < "${SRC_DIR}/.env.example"
  chmod 600 "${ENV_FILE}"
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

# Drop previous awggui:* tags left after upgrade/rebuild. In-use images stay (docker rmi refuses without -f).
# Also drop unused php / php:* base images left from the former panel-ops PHP runtime.
cleanup_unused_project_images() {
  local img cid removed=0
  log "$(t log_removing_unused_images)"

  while read -r cid; do
    [[ -n "${cid}" ]] || continue
    docker rm "${cid}" >/dev/null 2>&1 || true
  done < <(
    {
      docker ps -aq --filter "name=awggui" --filter "status=exited" 2>/dev/null || true
      docker ps -aq --filter "name=awggui" --filter "status=dead" 2>/dev/null || true
    } | awk 'NF && !seen[$0]++'
  )

  while read -r img; do
    [[ -n "${img}" ]] || continue
    [[ "${img}" == *":<none>" ]] && continue
    if docker rmi "${img}" >/dev/null 2>&1; then
      removed=$((removed + 1))
      log "$(t log_removed_unused_image "${img}")"
    fi
  done < <(docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^awggui-' || true)

  while read -r img; do
    [[ -n "${img}" ]] || continue
    [[ "${img}" == *":<none>" ]] && continue
    if docker rmi "${img}" >/dev/null 2>&1; then
      removed=$((removed + 1))
      log "$(t log_removed_unused_image "${img}")"
    fi
  done < <(
    docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^(php|docker\.io/library/php)(:|$)' || true
  )

  docker image prune -f >/dev/null 2>&1 || true
  docker rmi alpine:3.20 >/dev/null 2>&1 || true

  if [[ "${removed}" -gt 0 ]]; then
    ok "$(t ok_removed_n_images "${removed}")"
  else
    ok "$(t ok_no_unused_images)"
  fi
}

write_env_from_example() {
  local panel_port="$1" awg_port="$2" endpoint="$3"
  local internal_subnet="$4" peer_dns="$5" allowed_ips="$6"
  local admin_pass="$7" db_pass="$8" app_key="$9"
  local panel_https_port="${10:-${PANEL_HTTPS_PORT_DEFAULT}}"

  [[ -f "${SRC_DIR}/.env.example" ]] || die "$(t err_missing_path "${SRC_DIR}/.env.example")"
  cp "${SRC_DIR}/.env.example" "${ENV_FILE}"
  chmod 600 "${ENV_FILE}"

  env_set "PANEL_PORT" "${panel_port}" "${ENV_FILE}"
  env_set "PANEL_HTTPS_PORT" "${panel_https_port}" "${ENV_FILE}"
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

seed_host_ssl_files() {
  mkdir -p /etc/awg-gui/certs/panel /etc/awg-gui/certs/live/panel \
    /etc/awg-gui/acme/account /etc/awg-gui/acme/pending /etc/awg-gui/acme/challenge
  # App container writes these as UID/GID 33 (same as former www-data).
  chown -R 33:33 /etc/awg-gui/acme /etc/awg-gui/certs 2>/dev/null || true
  chmod -R a+rwX /etc/awg-gui/acme /etc/awg-gui/certs
  if [[ -f "${SRC_DIR}/caddy/Caddyfile" ]]; then
    cp "${SRC_DIR}/caddy/Caddyfile" /etc/awg-gui/Caddyfile
    chown 33:33 /etc/awg-gui/Caddyfile 2>/dev/null || true
    chmod a+rw /etc/awg-gui/Caddyfile
  fi
}

install_cli_and_systemd() {
  mkdir -p /etc/awg-gui
  install -m 0755 "${SRC_DIR}/bin/awg-gui" /usr/local/bin/awg-gui
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
  install -m 0644 "${SRC_DIR}/systemd/awg-gui.service" /etc/systemd/system/awg-gui.service
  systemctl daemon-reload
  systemctl enable --now awg-gui.service
  ok "$(t ok_cli_systemd)"
}

print_helper() {
  echo
  echo -e "${BOLD}────────────────────────────────────────${NC}"
  echo -e "${BOLD}$(t helper_management_system)${NC}"
  echo
  echo "  awg-gui help"
  echo "  awg-gui status"
  echo "  awg-gui ensure-up"
  echo "  awg-gui restart awg"
  echo "  awg-gui restart panel"
  echo "  awg-gui restart all"
  echo "  awg-gui password                    # random password"
  echo "  awg-gui password --password=SECRET  # set your own"
  echo "  awg-gui 2fa status                  # 2FA status"
  echo "  awg-gui 2fa disable                 # disable 2FA (recovery)"
  echo "  awg-gui endpoint                    # show public VPN endpoint"
  echo "  awg-gui endpoint IP [PORT]          # set public IP/DNS and AWG port"
  echo
  echo "  systemctl status awg-gui     # boot ensure service"
  echo
  echo "  ${SCRIPT_DIR}/awg-gui-uninstall.sh"
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
    if compose exec -T app curl -fsS http://127.0.0.1:8000/up >/dev/null 2>&1; then
      return 0
    fi
    sleep 3
  done
  warn "$(t warn_app_not_ready)"
  return 1
}

wait_for_migrate_lock() {
  log "$(t log_waiting_migrations)"
  compose exec -T app bash -c '
    flock -w "${AWG_GUI_MIGRATE_LOCK_TIMEOUT:-300}" /var/lock/awg-migrate.lock true
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
  local panel_https_port="${8:-${PANEL_HTTPS_PORT_DEFAULT}}"

  run_migrations

  if [[ -n "${admin_pass}" ]]; then
    log "$(t log_ensuring_admin)"
    compose exec -T \
      -e ADMIN_PASSWORD="${admin_pass}" \
      app awgctl admin ensure --username=admin --password="${admin_pass}" --email=admin@localhost
  fi

  log "$(t log_bootstrapping_awg)"
  compose exec -T \
    -e SERVER_ENDPOINT="${endpoint}" \
    -e AWG_PORT="${awg_port}" \
    -e PANEL_PORT="${panel_port}" \
    -e PANEL_HTTPS_PORT="${panel_https_port}" \
    -e INTERNAL_SUBNET="${internal_subnet}" \
    -e PEER_DNS="${peer_dns}" \
    -e ALLOWED_IPS="${allowed_ips}" \
    app awgctl bootstrap || true
}

main() {
  [[ -f "${COMPOSE_FILE}" ]] || die "$(t err_missing_path "${COMPOSE_FILE}")"
  [[ -d /dev/net/tun ]] || warn "$(t warn_tun_missing)"

  ensure_curl
  ensure_docker_engine
  ensure_sing_box_vendor
  choose_install_mode

  local panel_port panel_https_port awg_port endpoint internal_subnet peer_dns allowed_ips
  local display_host admin_pass db_pass app_key

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    env_merge_missing_keys
    ok "$(t ok_using_existing_env_merged "${ENV_FILE}")"
    panel_port="$(env_get PANEL_PORT "${ENV_FILE}" "${PANEL_PORT_DEFAULT}")"
    panel_https_port="$(env_get PANEL_HTTPS_PORT "${ENV_FILE}" "${PANEL_HTTPS_PORT_DEFAULT}")"
    awg_port="$(env_get AWG_PORT "${ENV_FILE}" "${AWG_PORT_DEFAULT}")"
    endpoint="$(env_get SERVER_ENDPOINT "${ENV_FILE}" "auto")"
    [[ -n "${endpoint}" ]] || endpoint="auto"
    # Incomplete install on this host: prefer auto so the panel URL uses this VDS IP.
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
    panel_https_port="${PANEL_HTTPS_PORT_DEFAULT}"

    admin_pass="$(rand_secret 20)"
    db_pass="$(rand_secret 32)"
    app_key="$(gen_app_key)"
  fi

  # Avoid "address already in use" on compose up — remap busy ports and warn.
  reset_install_ports_reserved
  panel_port="$(ensure_host_port "${panel_port}" tcp "PANEL_PORT")"
  panel_https_port="$(ensure_host_port "${panel_https_port}" tcp "PANEL_HTTPS_PORT")"
  awg_port="$(ensure_host_port "${awg_port}" udp "AWG_PORT")"

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    env_set "PANEL_PORT" "${panel_port}" "${ENV_FILE}"
    env_set "PANEL_HTTPS_PORT" "${panel_https_port}" "${ENV_FILE}"
    env_set "AWG_PORT" "${awg_port}" "${ENV_FILE}"
  else
    write_env_from_example \
      "${panel_port}" "${awg_port}" "${endpoint}" \
      "${internal_subnet}" "${peer_dns}" "${allowed_ips}" \
      "${admin_pass}" "${db_pass}" "${app_key}" \
      "${panel_https_port}"
    ok "$(t ok_created_env_dev "${ENV_FILE}")"
  fi

  display_host="$(resolve_endpoint_host "${endpoint}")"
  sync_panel_access_env "${endpoint}" "${panel_port}" "${ENV_FILE}"
  cleanup_env_file_orphans "${ENV_FILE}"

  mkdir -p /etc/awg-gui
  seed_host_ssl_files
  env_merge_missing_keys
  ensure_panel_ops_token
  install_awg_kernel_module
  remove_legacy_certbot_container
  log "$(t log_prune_build_cache)"
  docker builder prune -af >/dev/null 2>&1 || true
  log "$(t log_building_containers)"
  COMPOSE_PARALLEL_LIMIT=1 compose build
  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    compose_upgrade_with_awg_recovery
  else
    compose_up_with_awg_recovery
  fi

  wait_for_app || true
  wait_for_migrate_lock
  run_bootstrap \
    "${panel_port}" "${awg_port}" "${endpoint}" \
    "${internal_subnet}" "${peer_dns}" "${allowed_ips}" \
    "${admin_pass:-}" "${panel_https_port}"

  if [[ "${UPGRADE_MODE}" -eq 0 || ! -f /etc/awg-gui/webhook.conf ]] || ! grep -q '^PANEL_PORT=' /etc/awg-gui/webhook.conf 2>/dev/null; then
    cat > /etc/awg-gui/webhook.conf <<EOF
WEBHOOK_URL=
PANEL_PORT=${panel_port}
PANEL_HTTPS_PORT=${panel_https_port}
SERVER_ENDPOINT=${endpoint}
PANEL_DOMAIN=
SSL_ENABLED=0
EOF
  else
    env_set "PANEL_PORT" "${panel_port}" /etc/awg-gui/webhook.conf
    env_set "PANEL_HTTPS_PORT" "${panel_https_port}" /etc/awg-gui/webhook.conf
    if [[ "${REPAIR_MODE}" -eq 1 ]]; then
      env_set "SERVER_ENDPOINT" "${endpoint}" /etc/awg-gui/webhook.conf
    fi
  fi

  install_cli_and_systemd
  mark_install_complete
  cleanup_unused_project_images

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
