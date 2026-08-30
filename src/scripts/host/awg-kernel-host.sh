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
  echo "usage: $0 {status|install|uninstall|recover|prepare-for-container-stop}" >&2
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

module_blacklisted() {
  # Manual oops workaround often leaves this file; it survives package reinstall.
  [[ -f /etc/modprobe.d/blacklist-amneziawg.conf ]]
}

clear_module_blacklist() {
  rm -f /etc/modprobe.d/blacklist-amneziawg.conf 2>/dev/null || true
}

write_module_blacklist() {
  mkdir -p /etc/modprobe.d 2>/dev/null || true
  printf '%s\n' 'blacklist amneziawg' > /etc/modprobe.d/blacklist-amneziawg.conf
}

# Hard wall-clock limit even when coreutils `timeout` is missing (minimal images).
_run_timed() {
  local secs="$1"
  shift
  if command -v timeout >/dev/null 2>&1; then
    timeout --signal=KILL "${secs}s" "$@" 2>/dev/null
    return $?
  fi
  "$@" &
  local pid=$!
  local waited=0
  while kill -0 "${pid}" 2>/dev/null; do
    if [[ "${waited}" -ge "${secs}" ]]; then
      kill -9 "${pid}" 2>/dev/null || true
      wait "${pid}" 2>/dev/null || true
      return 124
    fi
    sleep 1
    waited=$((waited + 1))
  done
  wait "${pid}"
}

unload_module_timed() {
  if ! module_loaded; then
    return 0
  fi
  _run_timed 5 modprobe -r "${MODULE_NAME}" 2>/dev/null && return 0
  return 1
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

# Docker state for awggui-awg: missing | created | running | restarting | …
awg_container_state() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "missing"
    return
  fi
  docker inspect -f '{{.State.Status}}' "${AWG_CONTAINER}" 2>/dev/null || echo "missing"
}

awg_container_exec_ready() {
  [[ "$(awg_container_state)" == "running" ]]
}

# docker exec only when the container is running; stderr suppressed (avoid OCI noise during recreate).
awg_container_exec() {
  if ! awg_container_exec_ready; then
    return 125
  fi
  docker exec "${AWG_CONTAINER}" "$@" 2>/dev/null
}

wait_awg_container_running() {
  local max_wait="${1:-60}"
  local i state
  for i in $(seq 1 "${max_wait}"); do
    state="$(awg_container_state)"
    if [[ "${state}" == "running" ]]; then
      return 0
    fi
    if [[ "${state}" == "missing" ]]; then
      return 1
    fi
    sleep 1
  done
  return 1
}

awg_datapath() {
  if ! command -v docker >/dev/null 2>&1; then
    echo "unknown"
    return
  fi
  local state
  state="$(awg_container_state)"
  if [[ "${state}" != "running" ]]; then
    echo "unknown"
    return
  fi
  if awg_container_exec sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "'; then
    echo "userspace"
    return
  fi
  if awg_container_exec sh -c 'for iface in $(ip -o link show 2>/dev/null | awk -F": " "{print \$2}" | grep -E "^awg[0-9]+$"); do pgrep -f "amneziawg-go ${iface}" >/dev/null 2>&1 && exit 0; done; exit 1'; then
    echo "userspace"
    return
  fi
  if module_loaded; then
    echo "kernel"
    return
  fi
  echo "unknown"
}

iface_datapaths_json() {
  if ! command -v docker >/dev/null 2>&1; then
    printf '[]'
    return
  fi
  if ! awg_container_exec_ready; then
    printf '[]'
    return
  fi
  local lines="" ec=0
  lines="$(awg_container_exec sh -c '
    for conf in /config/awg*.conf; do
      [ -f "$conf" ] || continue
      iface=$(basename "$conf" .conf)
      mode=unknown
      if pgrep -f "amneziawg-go ${iface}" >/dev/null 2>&1; then
        mode=userspace
      elif ip link show "${iface}" >/dev/null 2>&1 && { [ -d /sys/module/amneziawg ] || lsmod 2>/dev/null | awk "{print \$1}" | grep -qx amneziawg; }; then
        mode=kernel
      fi
      printf "%s:%s\n" "$iface" "$mode"
    done
  ' 2>/dev/null)" || ec=$?
  if [[ "${ec}" -ne 0 ]] || printf '%s' "${lines}" | grep -qi 'OCI runtime exec failed'; then
    printf '[]'
    return
  fi
  printf '['
  local first=1
  local line iface mode
  while IFS= read -r line; do
    line="${line//$'\r'/}"
    [ -z "${line}" ] && continue
    iface="${line%%:*}"
    mode="${line#*:}"
    [ -z "${iface}" ] && continue
    [ "${first}" -eq 0 ] && printf ','
    first=0
    printf '{"iface":%s,"mode":%s}' "$(json_escape "${iface}")" "$(json_escape "${mode}")"
  done <<< "${lines}"
  printf ']'
}

cmd_status() {
  local family loaded pkg path detail blacklisted container_state
  family="$(detect_family)"
  loaded=false
  pkg=false
  blacklisted=false
  module_loaded && loaded=true
  package_installed && pkg=true
  module_blacklisted && blacklisted=true
  container_state="$(awg_container_state)"
  path="$(awg_datapath)"
  detail="os_family=${family};container=${container_state}"
  if [[ "${blacklisted}" == "true" ]]; then
    detail="${detail};blacklist=1"
  fi
  printf '{'
  printf '"module_loaded":%s,' "${loaded}"
  printf '"package_installed":%s,' "${pkg}"
  printf '"module_blacklisted":%s,' "${blacklisted}"
  printf '"awg_datapath":%s,' "$(json_escape "${path}")"
  printf '"iface_datapaths":%s,' "$(iface_datapaths_json)"
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
  clear_module_blacklist
  modprobe "${MODULE_NAME}"
  # Persist across reboot so AWG does not fall back to userspace after kernel update/reboot.
  mkdir -p /etc/modules-load.d 2>/dev/null || true
  printf '%s\n' "${MODULE_NAME}" > /etc/modules-load.d/amneziawg.conf 2>/dev/null || true
}

unload_module() {
  modprobe -r "${MODULE_NAME}" 2>/dev/null || true
  rm -f /etc/modules-load.d/amneziawg.conf 2>/dev/null || true
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
  if ! command -v docker >/dev/null 2>&1 || ! docker inspect "${AWG_CONTAINER}" >/dev/null 2>&1; then
    return 0
  fi
  docker restart "${AWG_CONTAINER}" >/dev/null 2>&1 || true
  wait_awg_container_running 90 || return 0
  # Wait for entrypoint to prefer awg-quick when the module is loaded.
  local i
  for i in $(seq 1 30); do
    sleep 2
    if ! awg_container_exec sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "'; then
      if awg_container_exec sh -c 'ip link show type wireguard >/dev/null 2>&1 || ip link show type amneziawg >/dev/null 2>&1 || ls /sys/class/net/awg* >/dev/null 2>&1'; then
        return 0
      fi
    fi
  done
}

force_awg_kernel_datapath() {
  # After installing the module, nudge entrypoint to drop amneziawg-go even if conf mtime unchanged.
  if ! command -v docker >/dev/null 2>&1 || ! docker inspect "${AWG_CONTAINER}" >/dev/null 2>&1; then
    return 0
  fi
  wait_awg_container_running 90 || return 0
  awg_container_exec sh -c '
    if [ -d /sys/module/amneziawg ]; then
      for c in /config/awg*.conf; do
        [ -f "$c" ] || continue
        touch "$c"
      done
    fi
  ' || true
  local i
  for i in $(seq 1 20); do
    sleep 2
    awg_container_exec_ready || continue
    if awg_container_exec sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "'; then
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
  if ! module_loaded; then
    write_state "ok" "AmneziaWG kernel module installed; module not loaded yet — reboot or modprobe amneziawg"
  elif ! awg_container_exec_ready; then
    write_state "ok" "AmneziaWG kernel module installed; AWG container still starting — refresh status in a minute"
  elif ! awg_container_exec sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "'; then
    write_state "ok" "AmneziaWG kernel module installed; AWG using kernel datapath"
  else
    write_state "error" "Kernel module loaded but AWG still on userspace (awg-quick/setconf failed — check dmesg for amneziawg oops). AWG will retry kernel after backoff or use stable userspace."
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

clear_awg_kernel_bad_markers() {
  if ! command -v docker >/dev/null 2>&1 || ! docker inspect "${AWG_CONTAINER}" >/dev/null 2>&1; then
    return 0
  fi
  awg_container_exec sh -c 'rm -f /run/awg-kernel-bad /config/awg-kernel-bad' || true
}

cmd_recover() {
  write_state "running" "Recovering AmneziaWG kernel datapath..."
  clear_module_blacklist
  if module_loaded; then
    unload_module_timed || true
  fi
  if package_installed || module_loaded; then
    load_module || modprobe "${MODULE_NAME}" 2>/dev/null || true
  elif ! module_loaded; then
    load_module 2>/dev/null || modprobe "${MODULE_NAME}" 2>/dev/null || true
  fi
  clear_awg_kernel_bad_markers
  force_awg_kernel_datapath
  restart_awg_container
  force_awg_kernel_datapath
  if module_loaded && awg_container_exec_ready && ! awg_container_exec sh -c 'ps aux 2>/dev/null | grep -q "[a]mneziawg-go "'; then
    write_state "ok" "AWG recovered to kernel datapath"
    echo "AWG recovered to kernel datapath"
    return 0
  fi
  if module_loaded && ! awg_container_exec_ready; then
    write_state "ok" "Kernel module loaded; AWG container still starting — refresh status in a minute"
    echo "AWG recover: container still starting"
    return 0
  fi
  if module_loaded; then
    write_state "error" "Module loaded but AWG still on userspace (awg-quick/setconf failed — check dmesg for amneziawg oops)"
  elif package_installed; then
    write_state "error" "Package installed but module not loaded — check modprobe/dkms build"
  else
    write_state "error" "Kernel module not installed — run install first"
  fi
  echo "AWG recover finished (see state for datapath result)" >&2
}

# Before upgrade/recreate: unload host module so awg-quick/setconf cannot wedge Docker stop.
# If unload fails (wedged kernel path), blacklist and unload again — AWG will use userspace.
cmd_prepare_for_container_stop() {
  local action="none"
  if module_blacklisted; then
    # Blacklist means userspace path — never modprobe -r here (wedged module can hang forever).
    action="blacklist_already_present"
    printf '{"module_blacklisted":true,"module_loaded":%s,"action":%s}\n' \
      "$(module_loaded && echo true || echo false)" \
      "$(json_escape "${action}")"
    return 0
  fi
  if ! module_loaded; then
    printf '{"module_blacklisted":false,"module_loaded":false,"action":"none"}\n'
    return 0
  fi
  echo "Unloading ${MODULE_NAME} before AWG container stop..." >&2
  if unload_module_timed; then
    action="unloaded"
    printf '{"module_blacklisted":false,"module_loaded":false,"action":%s}\n' "$(json_escape "${action}")"
    return 0
  fi
  echo "WARN: cannot unload ${MODULE_NAME} — applying blacklist for safe userspace" >&2
  write_module_blacklist
  action="blacklisted"
  printf '{"module_blacklisted":true,"module_loaded":%s,"action":%s}\n' \
    "$(module_loaded && echo true || echo false)" \
    "$(json_escape "${action}")"
  return 0
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
  recover)
    with_lock cmd_recover
    ;;
  prepare-for-container-stop)
    cmd_prepare_for_container_stop
    ;;
  *)
    usage
    ;;
esac
