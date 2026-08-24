#!/usr/bin/env bash
# Fixed host helper for AmneziaWG kernel module (install/uninstall/status).
# SECURITY: only allowlisted packages/repos/module/container names. No user argv beyond op.
set -euo pipefail

readonly MODULE_NAME=amneziawg
readonly AWG_CONTAINER=awggui-awg
readonly LOCK_FILE=/var/lock/awg-gui-awg-kernel.lock
readonly STATE_FILE=/etc/awg-gui/awg-kernel.state

# Amnezia PPA (Ubuntu/Debian) — fixed.
readonly AMNEZIA_PPA_KEY=57290828
readonly AMNEZIA_PPA_DEB='deb https://ppa.launchpadcontent.net/amnezia/ppa/ubuntu focal main'
readonly AMNEZIA_PPA_DEB_SRC='deb-src https://ppa.launchpadcontent.net/amnezia/ppa/ubuntu focal main'

usage() {
  echo "usage: $0 {status|install|uninstall}" >&2
  exit 2
}

iso_now() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

json_escape() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/\\n}"
  s="${s//$'\r'/\\r}"
  s="${s//$'\t'/\\t}"
  printf '"%s"' "${s}"
}

write_state() {
  local status="$1"
  local message="$2"
  mkdir -p /etc/awg-gui
  printf '{\n  "status": %s,\n  "message": %s,\n  "updated_at": %s\n}\n' \
    "$(json_escape "${status}")" \
    "$(json_escape "${message}")" \
    "$(json_escape "$(iso_now)")" \
    > "${STATE_FILE}"
}

detect_family() {
  # shellcheck disable=SC1091
  if [[ -f /etc/os-release ]]; then
    . /etc/os-release
  else
    echo "unknown"
    return
  fi
  case "${ID:-}:${ID_LIKE:-}" in
    ubuntu:*|linuxmint:*|*:ubuntu*|debian:*|*:debian*)
      echo "debian"
      ;;
    fedora:*|centos:*|rhel:*|rocky:*|almalinux:*|*:fedora*|*:rhel*|*:centos*)
      echo "rhel"
      ;;
    *)
      echo "unsupported"
      ;;
  esac
}

module_loaded() {
  lsmod 2>/dev/null | awk '{print $1}' | grep -qx "${MODULE_NAME}"
}

package_installed_debian() {
  dpkg -s amneziawg >/dev/null 2>&1 || dpkg -s amneziawg-dkms >/dev/null 2>&1
}

package_installed_rhel() {
  rpm -q amneziawg-dkms >/dev/null 2>&1 || rpm -q amneziawg >/dev/null 2>&1
}

package_installed() {
  case "$(detect_family)" in
    debian) package_installed_debian ;;
    rhel) package_installed_rhel ;;
    *) return 1 ;;
  esac
}

awg_datapath() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "unknown"
    return
  fi
  if ! docker inspect "${AWG_CONTAINER}" >/dev/null 2>&1; then
    echo "unknown"
    return
  fi
  if docker exec "${AWG_CONTAINER}" sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "' 2>/dev/null; then
    echo "userspace"
    return
  fi
  if module_loaded; then
    echo "kernel"
    return
  fi
  echo "unknown"
}

cmd_status() {
  local family loaded pkg path detail
  family="$(detect_family)"
  loaded=false
  pkg=false
  module_loaded && loaded=true
  package_installed && pkg=true
  path="$(awg_datapath)"
  detail="os_family=${family}"
  printf '{'
  printf '"module_loaded":%s,' "${loaded}"
  printf '"package_installed":%s,' "${pkg}"
  printf '"awg_datapath":%s,' "$(json_escape "${path}")"
  printf '"os_family":%s,' "$(json_escape "${family}")"
  printf '"detail":%s' "$(json_escape "${detail}")"
  printf '}\n'
}

ensure_debian_headers() {
  local headers="linux-headers-$(uname -r)"
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y -qq software-properties-common python3-launchpadlib gnupg2 "${headers}" || \
    apt-get install -y -qq software-properties-common gnupg2 "${headers}"
}

ensure_debian_repo_ubuntu() {
  export DEBIAN_FRONTEND=noninteractive
  if command -v add-apt-repository >/dev/null 2>&1; then
    add-apt-repository -y ppa:amnezia/ppa
  else
    apt-key adv --keyserver keyserver.ubuntu.com --recv-keys "${AMNEZIA_PPA_KEY}" || true
    if ! grep -qF 'ppa.launchpadcontent.net/amnezia/ppa' /etc/apt/sources.list /etc/apt/sources.list.d/* 2>/dev/null; then
      echo "${AMNEZIA_PPA_DEB}" >> /etc/apt/sources.list.d/amnezia-ppa.list
      echo "${AMNEZIA_PPA_DEB_SRC}" >> /etc/apt/sources.list.d/amnezia-ppa.list
    fi
  fi
  apt-get update -qq
}

install_debian() {
  ensure_debian_headers
  ensure_debian_repo_ubuntu
  export DEBIAN_FRONTEND=noninteractive
  # Allowlist: only these package names.
  apt-get install -y -qq amneziawg
}

install_rhel() {
  if command -v dnf >/dev/null 2>&1; then
    dnf -y install dnf-plugins-core || true
    dnf -y copr enable amneziavpn/amneziawg
    # Allowlist packages only.
    dnf -y install amneziawg-dkms amneziawg-tools
  elif command -v yum >/dev/null 2>&1; then
    yum -y install yum-plugin-copr || true
    yum -y copr enable amneziavpn/amneziawg
    yum -y install amneziawg-dkms amneziawg-tools
  else
    echo "Neither dnf nor yum found" >&2
    return 1
  fi
}

load_module() {
  modprobe "${MODULE_NAME}"
}

unload_module() {
  modprobe -r "${MODULE_NAME}" 2>/dev/null || true
}

# Best-effort host TCP tuning for high-BDP ABR (container sysctls are rejected by runc).
# Failures must never abort kernel install/uninstall.
apply_host_streaming_sysctl() {
  local conf=/etc/sysctl.d/99-awg-gui-streaming.conf
  mkdir -p /etc/sysctl.d 2>/dev/null || true
  cat > "${conf}" <<'EOF' 2>/dev/null || true
# Managed by awg-gui AmneziaWG kernel helper — high-BDP TCP for ABR over nested tunnels.
net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216
net.ipv4.tcp_rmem = 4096 87380 16777216
net.ipv4.tcp_wmem = 4096 65536 16777216
EOF
  sysctl -p "${conf}" >/dev/null 2>&1 || true
  sysctl -w net.ipv4.tcp_congestion_control=bbr >/dev/null 2>&1 || true
  sysctl -w net.core.rmem_max=16777216 >/dev/null 2>&1 || true
  sysctl -w net.core.wmem_max=16777216 >/dev/null 2>&1 || true
}

remove_host_streaming_sysctl_file() {
  # Leave live sysctl values alone; only drop our drop-in so uninstall stays fail-soft.
  rm -f /etc/sysctl.d/99-awg-gui-streaming.conf 2>/dev/null || true
}

uninstall_debian() {
  export DEBIAN_FRONTEND=noninteractive
  apt-get remove -y -qq amneziawg amneziawg-dkms amneziawg-tools 2>/dev/null || true
  apt-get purge -y -qq amneziawg amneziawg-dkms amneziawg-tools 2>/dev/null || true
}

uninstall_rhel() {
  if command -v dnf >/dev/null 2>&1; then
    dnf -y remove amneziawg-dkms amneziawg-tools amneziawg 2>/dev/null || true
  elif command -v yum >/dev/null 2>&1; then
    yum -y remove amneziawg-dkms amneziawg-tools amneziawg 2>/dev/null || true
  fi
}

with_lock() {
  mkdir -p "$(dirname "${LOCK_FILE}")"
  exec 9>"${LOCK_FILE}"
  if ! flock -n 9; then
    echo "Another awg-kernel operation is running" >&2
    return 75
  fi
  "$@"
}

restart_awg_container() {
  if command -v docker >/dev/null 2>&1 && docker inspect "${AWG_CONTAINER}" >/dev/null 2>&1; then
    docker restart "${AWG_CONTAINER}" >/dev/null || true
    # Wait for entrypoint to prefer awg-quick when the module is loaded.
    local i
    for i in $(seq 1 30); do
      sleep 2
      if ! docker exec "${AWG_CONTAINER}" sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "' 2>/dev/null; then
        if docker exec "${AWG_CONTAINER}" sh -c 'ip link show type wireguard >/dev/null 2>&1 || ip link show type amneziawg >/dev/null 2>&1 || ls /sys/class/net/awg* >/dev/null 2>&1'; then
          return 0
        fi
      fi
    done
  fi
}

force_awg_kernel_datapath() {
  # After installing the module, nudge entrypoint to drop amneziawg-go even if conf mtime unchanged.
  if ! command -v docker >/dev/null 2>&1 || ! docker inspect "${AWG_CONTAINER}" >/dev/null 2>&1; then
    return 0
  fi
  docker exec "${AWG_CONTAINER}" sh -c '
    if [ -d /sys/module/amneziawg ]; then
      for c in /config/awg*.conf; do
        [ -f "$c" ] || continue
        touch "$c"
      done
    fi
  ' 2>/dev/null || true
  local i
  for i in $(seq 1 20); do
    sleep 2
    if docker exec "${AWG_CONTAINER}" sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "'; then
      continue
    fi
    if module_loaded; then
      echo "AWG datapath migrated off userspace"
      return 0
    fi
  done
  echo "WARN: AWG may still be on userspace; check docker logs ${AWG_CONTAINER}" >&2
}

cmd_install() {
  local family
  family="$(detect_family)"
  write_state "running" "Installing AmneziaWG kernel module..."
  case "${family}" in
    debian) install_debian ;;
    rhel) install_rhel ;;
    *)
      write_state "error" "Unsupported OS for AmneziaWG kernel module (need Ubuntu/Debian/RHEL family)"
      echo "Unsupported OS family: ${family}" >&2
      return 1
      ;;
  esac
  load_module
  apply_host_streaming_sysctl
  restart_awg_container
  force_awg_kernel_datapath
  if module_loaded && ! docker exec "${AWG_CONTAINER}" sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "' 2>/dev/null; then
    write_state "ok" "AmneziaWG kernel module installed; AWG using kernel datapath"
  else
    write_state "ok" "AmneziaWG kernel module installed; verify AWG datapath in Settings (may need container restart)"
  fi
  echo "AmneziaWG kernel module installed"
}

cmd_uninstall() {
  local family
  family="$(detect_family)"
  write_state "running" "Removing AmneziaWG kernel module..."
  unload_module
  case "${family}" in
    debian) uninstall_debian ;;
    rhel) uninstall_rhel ;;
    unsupported|unknown)
      write_state "error" "Unsupported OS; cannot purge packages safely"
      return 1
      ;;
  esac
  remove_host_streaming_sysctl_file
  restart_awg_container
  write_state "ok" "AmneziaWG kernel module removed; AWG will use userspace fallback"
  echo "AmneziaWG kernel module removed"
}

OP="${1:-}"
case "${OP}" in
  status)
    cmd_status
    ;;
  install)
    with_lock cmd_install
    ;;
  uninstall)
    with_lock cmd_uninstall
    ;;
  *)
    usage
    ;;
esac
