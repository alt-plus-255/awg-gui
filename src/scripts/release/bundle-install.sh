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

Production install: loads pre-built Docker images and starts awggui stack.
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

ensure_curl() {
  command -v curl >/dev/null 2>&1 || die "curl is required"
  ok "curl present"
}

install_docker() {
  if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    ok "Docker and Compose already installed"
    systemctl enable --now docker 2>/dev/null || true
    return
  fi

  if ! confirm "Docker не установлен. Установить из официальных репозиториев?"; then
    die "Docker is required. https://docs.docker.com/engine/install/"
  fi

  log "Installing Docker Engine ..."
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
      die "Unsupported OS '${OS_ID}'. Install Docker manually."
      ;;
  esac

  systemctl enable --now docker
  docker compose version >/dev/null 2>&1 || die "docker compose plugin missing"
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

EXPECTED_CONTAINERS=(
  awggui-caddy awggui-app awggui-db awggui-awg
  awggui-docker-proxy awggui-panel-ops awggui-certbot
)

detect_existing_install() {
  local c names
  names="$(docker ps -a --format '{{.Names}}' 2>/dev/null || true)"
  for c in "${EXPECTED_CONTAINERS[@]}"; do
    if echo "${names}" | grep -qx "${c}"; then
      return 0
    fi
  done
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
  echo "  [1] Прервать"
  echo "  [2] Обновить (сохранить .env, volumes, данные БД/AWG)"
  local choice=""
  read -r -p "Выбор [1/2]: " choice || true
  case "${choice}" in
    2) UPGRADE_MODE=1 ;;
    *) log "Установка прервана."; exit 0 ;;
  esac
}

mark_install_complete() {
  local version=""
  local tar_file=""
  for tar_file in "${SCRIPT_DIR}"/images/awggui-all-*.tar.gz "${SCRIPT_DIR}"/images/awggui-all-*.tar; do
    [[ -f "${tar_file}" ]] || continue
    version="$(basename "${tar_file}" .tar.gz)"
    version="$(basename "${version}" .tar)"
    version="${version#awggui-all-}"
    break
  done
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
  if [[ -z "${ip}" ]]; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  echo "${ip:-127.0.0.1}"
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

env_set() {
  local key="$1" val="$2" file="$3"
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
  [[ -f "${ENV_EXAMPLE}" ]] || die "Missing ${ENV_EXAMPLE}"
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

  [[ -f "${ENV_EXAMPLE}" ]] || die "Missing ${ENV_EXAMPLE}"
  cp "${ENV_EXAMPLE}" "${ENV_FILE}"
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
  env_set "PANEL_OPS_TOKEN" "$(openssl rand -hex 32 2>/dev/null || rand_secret 64)" "${ENV_FILE}"
  env_set "SANCTUM_STATEFUL_DOMAINS" \
    "${endpoint},${endpoint}:${panel_port},${endpoint}:7443,localhost,localhost:${panel_port},127.0.0.1,127.0.0.1:${panel_port}" \
    "${ENV_FILE}"
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

load_images() {
  local tar_file=""
  for tar_file in "${SCRIPT_DIR}"/images/awggui-all-*.tar.gz "${SCRIPT_DIR}"/images/awggui-all-*.tar; do
    [[ -f "${tar_file}" ]] && break
  done
  [[ -f "${tar_file}" ]] || die "Missing ${SCRIPT_DIR}/images/awggui-all-*.tar.gz"

  log "Loading Docker images from ${tar_file} ..."
  docker load -i "${tar_file}"
  ok "Images loaded"
}

seed_host_ssl_files() {
  mkdir -p /etc/awg-gui/certs/panel /etc/awg-gui/certbot/hooks /etc/awg-gui/certbot/challenge
  if [[ -f "${RUNTIME_DIR}/caddy/Caddyfile" ]]; then
    cp "${RUNTIME_DIR}/caddy/Caddyfile" /etc/awg-gui/Caddyfile
  fi
  if [[ -d "${RUNTIME_DIR}/caddy/host-files/certbot/hooks" ]]; then
    cp "${RUNTIME_DIR}/caddy/host-files/certbot/hooks/"*.sh /etc/awg-gui/certbot/hooks/ 2>/dev/null || true
    chmod +x /etc/awg-gui/certbot/hooks/*.sh 2>/dev/null || true
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
  chmod 644 /etc/awg-gui/awg-gui.conf /etc/awg-gui/webhook.conf
  install -m 0644 "${RUNTIME_DIR}/systemd/awg-gui.service" /etc/systemd/system/awg-gui.service
  systemctl daemon-reload
  systemctl enable --now awg-gui.service
  ok "CLI /usr/local/bin/awg-gui and systemd awg-gui.service installed"
}

print_helper() {
  local repo="${AWG_GUI_GITHUB_REPO:-alt-plus-255/awg-gui}"
  echo
  echo -e "${BOLD}────────────────────────────────────────${NC}"
  echo -e "${BOLD}Management:${NC}"
  echo "  awg-gui help"
  echo "  awg-gui status"
  echo "  awg-gui ensure-up"
  echo
  echo -e "${BOLD}Uninstall (production):${NC}"
  echo "  curl -fsSL https://raw.githubusercontent.com/${repo}/refs/heads/main/dist/uninstall.sh | sudo bash"
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
  install_docker
  choose_install_mode

  local panel_port awg_port endpoint internal_subnet peer_dns allowed_ips
  local detected_ip admin_pass db_pass app_key

  if [[ "${UPGRADE_MODE}" -eq 1 ]]; then
    env_merge_missing_keys
    ok "Using existing ${ENV_FILE}"
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
    ok "Created ${ENV_FILE}"
  fi

  mkdir -p /etc/awg-gui
  seed_host_ssl_files
  env_merge_missing_keys
  ensure_panel_ops_token
  remove_legacy_certbot_container
  load_images

  log "Starting containers ..."
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
  fi

  install_cli_and_systemd
  mark_install_complete

  local url="http://${endpoint}:${panel_port}"
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
