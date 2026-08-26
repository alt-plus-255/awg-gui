#!/usr/bin/env bash
set -euo pipefail

CONFIG_DIR=/config
mkdir -p "${CONFIG_DIR}" "${CONFIG_DIR}/rulesets"
mkdir -p /run

declare -A LAST_MTIMES
# Per-boot: kernel awg-quick failed/timed out for this iface — do not retry kernel path
# (amneziawg.ko oops on setconf can wedge the netns if retried every few seconds).
declare -A KERNEL_FAILED
LAST_SB_MTIME=0
# Re-check userspace→kernel migration periodically (seconds since epoch of last check).
LAST_KERNEL_MIGRATE_CHECK=0
AWG_QUICK_TIMEOUT="${AWG_QUICK_TIMEOUT:-20}"
AWG_SETCONF_TIMEOUT="${AWG_SETCONF_TIMEOUT:-15}"

kernel_module_loaded() {
  # Host kernel module is visible in the container via sysfs/lsmod.
  [[ -d /sys/module/amneziawg ]] && return 0
  lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg
}

iface_userspace_running() {
  local IFACE="$1"
  pgrep -f "amneziawg-go ${IFACE}" >/dev/null 2>&1
}

run_with_timeout() {
  local secs="$1"
  shift
  if command -v timeout >/dev/null 2>&1; then
    timeout --signal=KILL "${secs}s" "$@"
    return $?
  fi
  "$@"
}

cleanup_iface() {
  local IFACE="$1"
  local CONF="${CONFIG_DIR}/${IFACE}.conf"
  pkill -f "amneziawg-go ${IFACE}" 2>/dev/null || true
  # Prefer netlink delete first — avoids another awg-quick into a wedged kernel path.
  ip link delete "${IFACE}" 2>/dev/null || true
  if [[ -f "${CONF}" ]] && ip link show "${IFACE}" &>/dev/null; then
    run_with_timeout 10 awg-quick down "${CONF}" 2>/dev/null || true
    ip link delete "${IFACE}" 2>/dev/null || true
  fi
  sleep 0.5
}

awg_quick_up() {
  local CONF="$1"
  run_with_timeout "${AWG_QUICK_TIMEOUT}" awg-quick up "${CONF}"
}

apply_userspace() {
  local IFACE="$1"
  local CONF="$2"

  cleanup_iface "${IFACE}"

  amneziawg-go "${IFACE}" &
  sleep 1
  if ! ip link show "${IFACE}" &>/dev/null; then
    echo "[awg] ERROR: amneziawg-go failed to create ${IFACE}" >&2
    return 1
  fi

  local addr
  addr="$(awk -F' = ' '/^Address/{print $2; exit}' "${CONF}" || true)"
  if [[ -n "${addr}" ]]; then
    ip address replace "${addr}" dev "${IFACE}" 2>/dev/null || true
  fi

  local strip_file rc=0
  strip_file="$(mktemp)"
  if ! awg-quick strip "${CONF}" >"${strip_file}" 2>/dev/null; then
    echo "[awg] ERROR: awg-quick strip failed for ${IFACE}" >&2
    rm -f "${strip_file}"
    return 1
  fi
  if ! run_with_timeout "${AWG_SETCONF_TIMEOUT}" awg setconf "${IFACE}" "${strip_file}"; then
    echo "[awg] ERROR: userspace awg setconf failed/timed out for ${IFACE}" >&2
    rc=1
  fi
  rm -f "${strip_file}"
  if [[ "${rc}" -ne 0 ]]; then
    return 1
  fi

  iptables -C FORWARD -i "${IFACE}" -j ACCEPT 2>/dev/null \
    || iptables -A FORWARD -i "${IFACE}" -j ACCEPT || true
  local EGRESS
  EGRESS="$(ip -4 route show default 0.0.0.0/0 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')"
  if [[ -z "${EGRESS}" ]]; then
    EGRESS="$(ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if ($i=="dev") {print $(i+1); exit}}')"
  fi
  EGRESS="${EGRESS:-eth0}"
  iptables -t nat -C POSTROUTING -o "${EGRESS}" -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -o "${EGRESS}" -j MASQUERADE || true
  # Drop legacy eth+ MASQUERADE if present from older images.
  iptables -t nat -D POSTROUTING -o eth+ -j MASQUERADE 2>/dev/null || true

  echo "[awg] ${IFACE} userspace path active"
  return 0
}

apply_config() {
  local IFACE="$1"
  local CONF="${CONFIG_DIR}/${IFACE}.conf"
  local want_kernel=0
  local skip_kernel=0

  if [[ ! -f "${CONF}" ]]; then
    return 1
  fi

  if [[ "${KERNEL_FAILED[$IFACE]:-0}" == "1" ]]; then
    skip_kernel=1
  fi

  if kernel_module_loaded && [[ "${skip_kernel}" -eq 0 ]]; then
    want_kernel=1
  fi

  if ip link show "${IFACE}" &>/dev/null; then
    cleanup_iface "${IFACE}"
  fi

  if [[ "${want_kernel}" -eq 1 ]]; then
    if awg_quick_up "${CONF}"; then
      echo "[awg] ${IFACE} is up via awg-quick (kernel)"
      return 0
    fi
    echo "[awg] WARN: amneziawg module loaded but awg-quick failed/timed out for ${IFACE}; retrying once..."
    cleanup_iface "${IFACE}"
    sleep 2
    if awg_quick_up "${CONF}"; then
      echo "[awg] ${IFACE} is up via awg-quick (kernel, retry)"
      return 0
    fi
    echo "[awg] ERROR: kernel path failed for ${IFACE} — falling back to userspace (streaming will be slower); will not retry kernel this boot"
    KERNEL_FAILED[$IFACE]=1
    cleanup_iface "${IFACE}"
  elif [[ "${skip_kernel}" -eq 1 ]]; then
    echo "[awg] ${IFACE}: kernel path previously failed this boot — userspace amneziawg-go"
  else
    echo "[awg] awg-quick skipped for ${IFACE} (no kernel module) — userspace amneziawg-go"
  fi

  apply_userspace "${IFACE}" "${CONF}" || true
}

maybe_migrate_userspace_to_kernel() {
  # When the host installs amneziawg after AWG already started on userspace,
  # migrate without waiting for a config file mtime change.
  local now
  now="$(date +%s)"
  if (( now - LAST_KERNEL_MIGRATE_CHECK < 15 )); then
    return 0
  fi
  LAST_KERNEL_MIGRATE_CHECK="${now}"

  if ! kernel_module_loaded; then
    return 0
  fi

  shopt -s nullglob
  local conf
  for conf in "${CONFIG_DIR}"/awg*.conf; do
    local IFACE
    IFACE="$(basename "${conf}" .conf)"
    if [[ "${KERNEL_FAILED[$IFACE]:-0}" == "1" ]]; then
      continue
    fi
    if iface_userspace_running "${IFACE}"; then
      echo "[awg] Kernel module detected — migrating ${IFACE} from userspace to awg-quick"
      apply_config "${IFACE}" || true
      LAST_MTIMES[$IFACE]="$(stat -c %Y "${conf}" 2>/dev/null || echo 0)"
    fi
  done
}

sync_configs() {
  shopt -s nullglob
  local conf
  for conf in "${CONFIG_DIR}"/awg*.conf; do
    local IFACE
    IFACE="$(basename "${conf}" .conf)"
    local MT
    MT="$(stat -c %Y "${conf}" 2>/dev/null || echo 0)"
    local LAST="${LAST_MTIMES[$IFACE]:-0}"

    if [[ "${MT}" != "${LAST}" ]]; then
      echo "[awg] Config changed for ${IFACE}, reloading..."
      apply_config "${IFACE}" || true
      LAST_MTIMES[$IFACE]="${MT}"
    elif [[ -f "${conf}" ]] && ! ip link show "${IFACE}" &>/dev/null; then
      echo "[awg] Interface ${IFACE} missing, re-applying..."
      apply_config "${IFACE}" || true
      LAST_MTIMES[$IFACE]="${MT}"
    fi
  done
  maybe_migrate_userspace_to_kernel
}

# gcompat may show process comm as ld-musl-*/ld-linux-* — never rely on pgrep -x sing-box.
singbox_is_running() {
  if [[ -f /run/sing-box.pid ]]; then
    local pid
    pid="$(cat /run/sing-box.pid 2>/dev/null || true)"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
      return 0
    fi
  fi
  pgrep -f '/usr/local/bin/sing-box run -c /config/sing-box.json' >/dev/null 2>&1
}

sync_singbox() {
  local SB="${CONFIG_DIR}/sing-box.json"
  local MT=0
  if [[ -f "${SB}" ]]; then
    MT="$(stat -c %Y "${SB}" 2>/dev/null || echo 0)"
  fi
  if [[ "${MT}" != "${LAST_SB_MTIME}" ]]; then
    echo "[sing-box] config changed, reloading..."
    if [[ -x /config/reload-singbox.sh ]]; then /config/reload-singbox.sh || true; else /usr/local/bin/reload-singbox.sh || true; fi
    LAST_SB_MTIME="${MT}"
  elif [[ -f "${SB}" ]] && ! singbox_is_running; then
    echo "[sing-box] process missing, starting..."
    if [[ -x /config/reload-singbox.sh ]]; then /config/reload-singbox.sh || true; else /usr/local/bin/reload-singbox.sh || true; fi
    LAST_SB_MTIME="${MT}"
  elif [[ ! -f "${SB}" ]] && singbox_is_running; then
    echo "[sing-box] config removed, stopping..."
    if [[ -x /config/reload-singbox.sh ]]; then /config/reload-singbox.sh || true; else /usr/local/bin/reload-singbox.sh || true; fi
    LAST_SB_MTIME=0
  fi
}

if kernel_module_loaded; then
  echo "[awg] Host kernel module amneziawg is loaded — preferring awg-quick (timeout ${AWG_QUICK_TIMEOUT}s)"
else
  echo "[awg] No amneziawg kernel module — userspace fallback available"
fi

echo "[awg] Waiting for at least one awg*.conf in ${CONFIG_DIR}..."
while true; do
  shopt -s nullglob
  confs=("${CONFIG_DIR}"/awg*.conf)
  if (( ${#confs[@]} > 0 )); then
    break
  fi
  # Start sing-box even while waiting for confs (JSON may already exist).
  sync_singbox
  sleep 5
done

# Prefer resolver up before/while bringing ifaces (iface apply has timeouts; must not block DNS forever).
sync_singbox
sync_configs
sync_singbox

while true; do
  sleep 3
  sync_singbox
  sync_configs
done
