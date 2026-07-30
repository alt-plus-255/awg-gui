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
UPGRADE_MODE=0
REPAIR_MODE=0
PANEL_PORT_DEFAULT=8877
AWG_PORT_DEFAULT=51820
INTERNAL_SUBNET_DEFAULT="10.66.66.0/24"
PEER_DNS_DEFAULT="1.1.1.1"
ALLOWED_IPS_DEFAULT="0.0.0.0/0, ::/0"

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

usage() {
  cat <<EOF
Usage: $0 [--yes] [--help]

Installs AmneziaWG 2.0 + Laravel 12 + Quasar admin (Docker project awggui).
Before installing missing system packages (curl/jq, Docker) asks y/N
(unless --yes). Downloads sing-box vendor tarball for AWG image if missing.
Then prompts: panel port, AWG port, endpoint, subnet, DNS, AllowedIPs.
Creates src/.env from src/.env.example with random DB password (fresh install).

If an existing install is detected, offers abort or upgrade (with --yes: upgrade).
Upgrade keeps .env, volumes and database/AWG data; rebuilds images and runs migrations.

Management after install: awg-gui help
Uninstall: ./awg-gui-uninstall.sh
EOF
}

for arg in "$@"; do
  case "$arg" in
    --yes|-y) YES=1 ;;
    --help|-h) usage; exit 0 ;;
    *) die "Unknown argument: $arg" ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || die "Run as root (sudo)"

compose() {
  docker compose -p "${PROJECT_NAME}" --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

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
    *) die "Unsupported architecture: $m" ;;
  esac
}

sing_box_arch() {
  case "${ARCH}" in
    amd64) echo amd64 ;;
    arm64) echo arm64 ;;
    armhf) echo armv7 ;;
    *) die "Unsupported sing-box architecture: ${ARCH}" ;;
  esac
}

ensure_curl() {
  local need_curl=0 need_jq=0
  command -v curl >/dev/null 2>&1 || need_curl=1
  command -v jq >/dev/null 2>&1 || need_jq=1

  if [[ "${need_curl}" -eq 0 ]]; then
    ok "curl present"
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

  if ! confirm "Не хватает пакетов (${list}). Установить через пакетный менеджер?"; then
    [[ "${need_curl}" -eq 1 ]] && die "curl is required. Install it manually and re-run."
    warn "jq not installed (optional for rich webhook JSON)"
    return
  fi

  log "Installing ${list}..."
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
      command -v curl >/dev/null 2>&1 || die "Cannot install curl on ${OS_ID}. Install curl manually."
      warn "jq not installed (optional for rich webhook JSON)"
      ;;
  esac
  command -v curl >/dev/null 2>&1 || die "curl install failed"
  ok "curl ready"
}

ensure_sing_box_vendor() {
  detect_arch
  local sb_arch dest url
  sb_arch="$(sing_box_arch)"
  dest="${SRC_DIR}/awg/vendor/sing-box-${SING_BOX_VERSION}-linux-${sb_arch}.tar.gz"
  if [[ -f "${dest}" ]]; then
    ok "sing-box vendor present (${dest})"
    return
  fi

  url="https://github.com/SagerNet/sing-box/releases/download/v${SING_BOX_VERSION}/sing-box-${SING_BOX_VERSION}-linux-${sb_arch}.tar.gz"
  if ! confirm "Tarball sing-box отсутствует (${dest}). Скачать с GitHub?"; then
    die "sing-box vendor required for AWG image. Place tarball in src/awg/vendor/ and re-run."
  fi

  mkdir -p "${SRC_DIR}/awg/vendor"
  log "Downloading sing-box ${SING_BOX_VERSION} (${sb_arch})..."
  curl -fsSL -o "${dest}" "${url}"
  ok "Downloaded ${dest}"
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
    die "Non-interactive shell (no TTY). Re-run with --yes"
  fi
  printf '%s' "${ans}"
}

confirm() {
  local msg="$1"
  local default="${2:-n}"
  local ans hint
  if [[ "${YES}" -eq 1 ]]; then
    log "${msg} → yes (--yes)"
    return 0
  fi
  if [[ "${default}" == "y" ]]; then
    hint="[Y/n]"
  else
    hint="[y/N]"
  fi
  ans="$(read_tty "${msg} ${hint}: ")"
  ans="${ans:-${default}}"
  case "${ans}" in
    y|Y|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
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
    warn "Найден оставшийся ${ENV_FILE} без контейнеров — чистая установка с новыми паролями"
    UPGRADE_MODE=0
    REPAIR_MODE=0
    return
  fi

  if detect_incomplete_install; then
    REPAIR_MODE=1
    UPGRADE_MODE=1
    warn "Обнаружена незавершённая установка — продолжаем восстановление автоматически ..."
    return
  fi

  REPAIR_MODE=0

  if [[ "${YES}" -eq 1 ]]; then
    UPGRADE_MODE=1
    log "Existing install detected → upgrade mode (--yes)"
    return
  fi

  echo
  warn "Обнаружена существующая установка awggui."
  echo "  [1] Прервать (рекомендуется uninstall перед чистой установкой)"
  echo "  [2] Обновить (сохранить .env, volumes, данные БД/AWG)"
  local choice=""
  choice="$(read_tty "Выбор [1/2]: ")"
  case "${choice}" in
    2) UPGRADE_MODE=1 ;;
    *) log "Установка прервана."; exit 0 ;;
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
  local host existing_app_url
  host="$(resolve_endpoint_host "${endpoint}")"
  existing_app_url="$(env_get APP_URL "${file}" 2>/dev/null || true)"
  # Do not clobber an existing HTTPS/domain APP_URL (panel SSL / custom domain).
  if [[ -z "${existing_app_url}" \
     || "${existing_app_url}" == "http://localhost:${panel_port}" \
     || "${existing_app_url}" =~ ^http://[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+:[0-9]+$ ]]; then
    env_set "APP_URL" "http://${host}:${panel_port}" "${file}"
  fi
  env_set "SANCTUM_STATEFUL_DOMAINS" \
    "${host},${host}:${panel_port},${host}:7443,localhost,localhost:${panel_port},127.0.0.1,127.0.0.1:${panel_port}" \
    "${file}"
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
  [[ -f "${SRC_DIR}/.env.example" ]] || die "Missing ${SRC_DIR}/.env.example"
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
  ok "Generated PANEL_OPS_TOKEN in ${ENV_FILE}"
}

remove_legacy_certbot_container() {
  docker rm -f awggui-certbot 2>/dev/null || true
}

write_env_from_example() {
  local panel_port="$1" awg_port="$2" endpoint="$3"
  local internal_subnet="$4" peer_dns="$5" allowed_ips="$6"
  local admin_pass="$7" db_pass="$8" app_key="$9"

  [[ -f "${SRC_DIR}/.env.example" ]] || die "Missing ${SRC_DIR}/.env.example"
  cp "${SRC_DIR}/.env.example" "${ENV_FILE}"
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

seed_host_ssl_files() {
  mkdir -p /etc/awg-gui/certs/panel /etc/awg-gui/certs/live/panel \
    /etc/awg-gui/acme/account /etc/awg-gui/acme/pending /etc/awg-gui/acme/challenge
  if [[ -f "${SRC_DIR}/caddy/Caddyfile" ]]; then
    cp "${SRC_DIR}/caddy/Caddyfile" /etc/awg-gui/Caddyfile
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
  chmod 644 /etc/awg-gui/awg-gui.conf /etc/awg-gui/webhook.conf
  install -m 0644 "${SRC_DIR}/systemd/awg-gui.service" /etc/systemd/system/awg-gui.service
  systemctl daemon-reload
  systemctl enable --now awg-gui.service
  ok "CLI /usr/local/bin/awg-gui and systemd awg-gui.service installed"
}

print_helper() {
  echo
  echo -e "${BOLD}────────────────────────────────────────${NC}"
  echo -e "${BOLD}Management (system-wide):${NC}"
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

print_credentials() {
  local url="$1" port="$2" pass="$3"
  echo -e "${GREEN}"
  if [[ -n "${pass}" ]]; then
    cat <<EOF
╔══════════════════════════════════════════════╗
║  AmneziaWG GUI established                   ║
║  URL:      ${url}
║  Port:     ${port}
║  Login:    admin
║  Password: ${pass}
╚══════════════════════════════════════════════╝
EOF
  else
    cat <<EOF
╔══════════════════════════════════════════════╗
║  AmneziaWG GUI upgraded                      ║
║  URL:      ${url}
║  Port:     ${port}
║  Login:    admin
║  Password: (unchanged — use awg-gui password) ║
╚══════════════════════════════════════════════╝
EOF
  fi
  echo -e "${NC}"
}

wait_for_app() {
  log "Waiting for app container..."
  local i
  for i in $(seq 1 60); do
    if compose exec -T app php -v >/dev/null 2>&1; then
      return 0
    fi
    sleep 3
  done
  warn "App container not ready — bootstrap steps may fail"
  return 1
}

wait_for_migrate_lock() {
  log "Waiting for in-container migrations to finish (if any)..."
  compose exec -T app bash -c '
    mkdir -p /var/www/html/storage/framework
    flock -w "${AWG_GUI_MIGRATE_LOCK_TIMEOUT:-300}" /var/www/html/storage/framework/migrate.lock true
  ' || warn "Timed out waiting for migration lock"
}

run_migrations() {
  log "Running migrations..."
  compose exec -T app awg-migrate-locked
}

run_bootstrap() {
  local panel_port="$1" awg_port="$2" endpoint="$3"
  local internal_subnet="$4" peer_dns="$5" allowed_ips="$6"
  local admin_pass="$7"

  run_migrations

  if [[ -n "${admin_pass}" ]]; then
    log "Ensuring admin user..."
    compose exec -T \
      -e ADMIN_PASSWORD="${admin_pass}" \
      app php artisan admin:ensure --username=admin --password="${admin_pass}" --email=admin@localhost
  fi

  log "Bootstrapping AmneziaWG config..."
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
  [[ -f "${COMPOSE_FILE}" ]] || die "Missing ${COMPOSE_FILE}"
  [[ -d /dev/net/tun ]] || warn "/dev/net/tun not found — AWG userspace may still work"

  ensure_curl
  ensure_docker_engine
  ensure_sing_box_vendor
  choose_install_mode

  local panel_port awg_port endpoint internal_subnet peer_dns allowed_ips
  local display_host admin_pass db_pass app_key

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    env_merge_missing_keys
    ok "Using existing ${ENV_FILE} (missing keys merged from .env.example)"
    panel_port="$(env_get PANEL_PORT "${ENV_FILE}" "${PANEL_PORT_DEFAULT}")"
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
    prompt panel_port "Panel port" "${PANEL_PORT_DEFAULT}"
    prompt awg_port "AmneziaWG UDP port (AWG_PORT)" "${AWG_PORT_DEFAULT}"
    prompt endpoint "Server endpoint (public IP/DNS, or auto)" "auto"
    prompt internal_subnet "Internal subnet (INTERNAL_SUBNET)" "${INTERNAL_SUBNET_DEFAULT}"
    prompt peer_dns "Peer DNS (PEER_DNS)" "${PEER_DNS_DEFAULT}"
    prompt allowed_ips "AllowedIPs for clients (ALLOWED_IPS)" "${ALLOWED_IPS_DEFAULT}"

    admin_pass="$(rand_secret 20)"
    db_pass="$(rand_secret 32)"
    app_key="$(gen_app_key)"

    write_env_from_example \
      "${panel_port}" "${awg_port}" "${endpoint}" \
      "${internal_subnet}" "${peer_dns}" "${allowed_ips}" \
      "${admin_pass}" "${db_pass}" "${app_key}"
    ok "Created ${ENV_FILE} from .env.example (random DB password generated)"
  fi

  display_host="$(resolve_endpoint_host "${endpoint}")"
  sync_panel_access_env "${endpoint}" "${panel_port}" "${ENV_FILE}"

  mkdir -p /etc/awg-gui
  seed_host_ssl_files
  env_merge_missing_keys
  ensure_panel_ops_token
  remove_legacy_certbot_container
  log "Freeing Docker build cache (small disks)..."
  docker builder prune -af >/dev/null 2>&1 || true
  log "Building and starting containers (this may take several minutes)..."
  COMPOSE_PARALLEL_LIMIT=1 compose build
  compose up -d

  wait_for_app || true
  wait_for_migrate_lock
  run_bootstrap \
    "${panel_port}" "${awg_port}" "${endpoint}" \
    "${internal_subnet}" "${peer_dns}" "${allowed_ips}" \
    "${admin_pass:-}"

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

  local url="http://${display_host}:${panel_port}"
  print_helper
  if [[ "${REPAIR_MODE}" -eq 1 ]]; then
    if [[ -n "${admin_pass}" ]]; then
      print_credentials "${url}" "${panel_port}" "${admin_pass}"
    else
      print_credentials "${url}" "${panel_port}" ""
    fi
    ok "Восстановление установки завершено"
  elif [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    print_credentials "${url}" "${panel_port}" ""
    ok "Upgrade complete"
  else
    print_credentials "${url}" "${panel_port}" "${admin_pass}"
    ok "Installation complete"
  fi
}

main "$@"
