#!/usr/bin/env bash
# awg-gui-install.sh — install AmneziaWG GUI stack only
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="${SCRIPT_DIR}/src"
COMPOSE_FILE="${SRC_DIR}/docker-compose.yml"
ENV_FILE="${SRC_DIR}/.env"
PROJECT_NAME=awggui
SING_BOX_VERSION=1.12.12
YES=0
UPGRADE_MODE=0
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

install_docker() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "Docker and Compose already installed"
    systemctl enable --now docker 2>/dev/null || true
    return
  fi

  local missing=()
  command -v docker >/dev/null 2>&1 || missing+=("Docker Engine")
  if command -v docker >/dev/null 2>&1; then
    docker compose version >/dev/null 2>&1 || missing+=("Docker Compose plugin")
  else
    missing+=("Docker Compose plugin")
  fi
  local list
  list="$(printf '%s, ' "${missing[@]}")"
  list="${list%, }"

  if ! confirm "Не установлен: ${list}. Установить Docker из официальных репозиториев?"; then
    die "Docker is required. Install manually: https://docs.docker.com/engine/install/ then re-run."
  fi

  log "Installing Docker Engine from official repositories..."
  log "Docs: https://docs.docker.com/engine/install/"
  detect_os
  detect_arch

  case "${OS_ID}" in
    ubuntu)
      apt-get update -y
      apt-get install -y ca-certificates curl
      install -m 0755 -d /etc/apt/keyrings
      curl -fsSL "https://download.docker.com/linux/ubuntu/gpg" -o /etc/apt/keyrings/docker.asc
      chmod a+r /etc/apt/keyrings/docker.asc
      echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${OS_VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
      apt-get update -y
      apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      ;;
    debian|raspbian)
      apt-get update -y
      apt-get install -y ca-certificates curl
      install -m 0755 -d /etc/apt/keyrings
      curl -fsSL "https://download.docker.com/linux/debian/gpg" -o /etc/apt/keyrings/docker.asc
      chmod a+r /etc/apt/keyrings/docker.asc
      echo "deb [arch=${ARCH} signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian ${OS_VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
      apt-get update -y
      apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      ;;
    fedora)
      dnf -y install dnf-plugins-core
      dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo
      dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      ;;
    centos|rhel|rocky|almalinux)
      if command -v dnf >/dev/null 2>&1; then
        dnf -y install dnf-plugins-core
        dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
        dnf install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      else
        yum install -y yum-utils
        yum-config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
        yum install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
      fi
      ;;
    *)
      die "Unsupported OS '${OS_ID}'. Install Docker manually: https://docs.docker.com/engine/install/"
      ;;
  esac

  systemctl enable --now docker
  docker compose version >/dev/null 2>&1 || die "docker compose plugin missing after install"
  ok "Docker installed"
}

confirm() {
  local msg="$1"
  local ans
  if [[ "${YES}" -eq 1 ]]; then
    log "${msg} → yes (--yes)"
    return 0
  fi
  read -r -p "${msg} [y/N]: " ans || true
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
  read -r -p "${msg} [${def}]: " val || true
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
  fi
  if [[ -n "${val}" ]]; then
    echo "${val}"
  else
    echo "${default}"
  fi
}

detect_existing_install() {
  local c names
  names="$(docker ps -a --format '{{.Names}}' 2>/dev/null || true)"
  for c in awggui-caddy awggui-app awggui-db awggui-awg; do
    if echo "${names}" | grep -qx "${c}"; then
      return 0
    fi
  done
  if [[ -f "${ENV_FILE}" ]]; then
    [[ -n "$(env_get DB_PASSWORD "${ENV_FILE}")" ]] && return 0
  fi
  return 1
}

choose_install_mode() {
  if ! detect_existing_install; then
    UPGRADE_MODE=0
    return
  fi

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
  read -r -p "Выбор [1/2]: " choice || true
  case "${choice}" in
    2) UPGRADE_MODE=1 ;;
    *) log "Установка прервана."; exit 0 ;;
  esac
}

detect_public_ip() {
  local ip=""
  ip="$(curl -4 -fsS --max-time 5 https://ifconfig.me 2>/dev/null || true)"
  if [[ -z "${ip}" ]]; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  echo "${ip:-127.0.0.1}"
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

# Set KEY=VALUE in .env (creates from .env.example first)
env_set() {
  local key="$1"
  local val="$2"
  local file="$3"
  local tmp
  tmp="$(mktemp)"
  if grep -q "^${key}=" "${file}" 2>/dev/null; then
    awk -v k="${key}" -v v="${val}" '
      BEGIN { found=0 }
      $0 ~ "^" k "=" { print k "=" v; found=1; next }
      { print }
      END { if (!found) print k "=" v }
    ' "${file}" > "${tmp}"
  else
    cp "${file}" "${tmp}"
    printf '%s=%s\n' "${key}" "${val}" >> "${tmp}"
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
  env_set "APP_URL" "http://${endpoint}:${panel_port}" "${ENV_FILE}"
  env_set "APP_KEY" "${app_key}" "${ENV_FILE}"
  env_set "ADMIN_PASSWORD" "${admin_pass}" "${ENV_FILE}"
  env_set "DB_PASSWORD" "${db_pass}" "${ENV_FILE}"
  env_set "SERVER_ENDPOINT" "${endpoint}" "${ENV_FILE}"
  env_set "INTERNAL_SUBNET" "${internal_subnet}" "${ENV_FILE}"
  env_set "PEER_DNS" "${peer_dns}" "${ENV_FILE}"
  env_set "ALLOWED_IPS" "${allowed_ips}" "${ENV_FILE}"
  env_set "SANCTUM_STATEFUL_DOMAINS" \
    "${endpoint},${endpoint}:${panel_port},${endpoint}:7443,localhost,localhost:${panel_port},127.0.0.1,127.0.0.1:${panel_port}" \
    "${ENV_FILE}"
}

seed_host_ssl_files() {
  mkdir -p /etc/awg-gui/certs/panel /etc/awg-gui/certbot/hooks /etc/awg-gui/certbot/challenge
  if [[ -f "${SRC_DIR}/caddy/Caddyfile" ]]; then
    cp "${SRC_DIR}/caddy/Caddyfile" /etc/awg-gui/Caddyfile
  fi
  if [[ -d "${SRC_DIR}/caddy/host-files/certbot/hooks" ]]; then
    cp "${SRC_DIR}/caddy/host-files/certbot/hooks/"*.sh /etc/awg-gui/certbot/hooks/ 2>/dev/null || true
    chmod +x /etc/awg-gui/certbot/hooks/*.sh 2>/dev/null || true
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

run_bootstrap() {
  local panel_port="$1" awg_port="$2" endpoint="$3"
  local internal_subnet="$4" peer_dns="$5" allowed_ips="$6"
  local admin_pass="$7"

  log "Running migrations..."
  compose exec -T app php artisan migrate --force

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
  install_docker
  ensure_sing_box_vendor
  choose_install_mode

  local panel_port awg_port endpoint internal_subnet peer_dns allowed_ips
  local detected_ip admin_pass db_pass app_key

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    env_merge_missing_keys
    ok "Using existing ${ENV_FILE} (missing keys merged from .env.example)"
    panel_port="$(env_get PANEL_PORT "${ENV_FILE}" "${PANEL_PORT_DEFAULT}")"
    awg_port="$(env_get AWG_PORT "${ENV_FILE}" "${AWG_PORT_DEFAULT}")"
    endpoint="$(env_get SERVER_ENDPOINT "${ENV_FILE}" "$(detect_public_ip)")"
    internal_subnet="$(env_get INTERNAL_SUBNET "${ENV_FILE}" "${INTERNAL_SUBNET_DEFAULT}")"
    peer_dns="$(env_get PEER_DNS "${ENV_FILE}" "${PEER_DNS_DEFAULT}")"
    allowed_ips="$(env_get ALLOWED_IPS "${ENV_FILE}" "${ALLOWED_IPS_DEFAULT}")"
    admin_pass="$(env_get ADMIN_PASSWORD "${ENV_FILE}")"
  else
    detected_ip="$(detect_public_ip)"
    prompt panel_port "Panel port" "${PANEL_PORT_DEFAULT}"
    prompt awg_port "AmneziaWG UDP port (AWG_PORT)" "${AWG_PORT_DEFAULT}"
    prompt endpoint "Server endpoint (public IP/DNS)" "${detected_ip}"
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

  mkdir -p /etc/awg-gui
  seed_host_ssl_files
  log "Freeing Docker build cache (small disks)..."
  docker builder prune -af >/dev/null 2>&1 || true
  log "Building and starting containers (this may take several minutes)..."
  COMPOSE_PARALLEL_LIMIT=1 compose build
  compose up -d

  wait_for_app || true
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
  fi

  install_cli_and_systemd

  local url="http://${endpoint}:${panel_port}"
  print_helper
  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    print_credentials "${url}" "${panel_port}" ""
    ok "Upgrade complete"
  else
    print_credentials "${url}" "${panel_port}" "${admin_pass}"
    ok "Installation complete"
  fi
}

main "$@"
