#!/usr/bin/env bash
set -euo pipefail

CONFIG_DIR=/config
mkdir -p "${CONFIG_DIR}" "${CONFIG_DIR}/rulesets"
mkdir -p /run

declare -A LAST_MTIMES
# Per-iface: kernel awg-quick failed/timed out — skip kernel briefly (oops on setconf can wedge
# the netns if retried every few seconds). Cleared after KERNEL_FAILED_COOLDOWN for another try.
declare -A KERNEL_FAILED
declare -A KERNEL_FAILED_AT
LAST_SB_MTIME=0
# Re-check userspace→kernel migration periodically (seconds since epoch of last check).
LAST_KERNEL_MIGRATE_CHECK=0
AWG_QUICK_TIMEOUT="${AWG_QUICK_TIMEOUT:-20}"
AWG_SETCONF_TIMEOUT="${AWG_SETCONF_TIMEOUT:-15}"
KERNEL_FAILED_COOLDOWN="${KERNEL_FAILED_COOLDOWN:-300}"

kernel_module_loaded() {
  # Host kernel module is visible in the container via sysfs/lsmod.
  [[ -d /sys/module/amneziawg ]] && return 0
  lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg
}

kernel_failed_active() {
  local IFACE="$1"
  local now failed_at
  [[ "${KERNEL_FAILED[$IFACE]:-0}" == "1" ]] || return 1
  failed_at="${KERNEL_FAILED_AT[$IFACE]:-0}"
  now="$(date +%s)"
  if (( now - failed_at >= KERNEL_FAILED_COOLDOWN )); then
    echo "[awg] ${IFACE}: KERNEL_FAILED cooldown (${KERNEL_FAILED_COOLDOWN}s) elapsed — will retry kernel path"
    unset 'KERNEL_FAILED[$IFACE]'
    unset 'KERNEL_FAILED_AT[$IFACE]'
    return 1
  fi
  return 0
}

mark_kernel_failed() {
  local IFACE="$1"
  KERNEL_FAILED[$IFACE]=1
  KERNEL_FAILED_AT[$IFACE]="$(date +%s)"
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

# Run PostUp/PostDown from conf with %i → iface (userspace path cannot rely on awg-quick hooks).
run_conf_hook() {
  local CONF="$1"
  local HOOK="$2"
  local IFACE="$3"
  local cmd=""

  [[ -f "${CONF}" ]] || return 0
  cmd="$(awk -v hook="${HOOK}" '
    $1 == hook && $2 == "=" {
      sub(/^[^=]+=[[:space:]]*/, "")
      print
      exit
    }
  ' "${CONF}" 2>/dev/null || true)"
  [[ -z "${cmd}" ]] && return 0
  cmd="${cmd//%i/${IFACE}}"
  echo "[awg] ${IFACE}: running ${HOOK}"
  # PostUp/PostDown are authored by the panel; failures must not abort iface bring-up.
  sh -c "${cmd}" || true
}

# IPv4 network prefix for Address like 10.66.66.1/24 → 10.66.66.0/24 (pure bash, no python).
ipv4_network_cidr() {
  local ip="$1" bits="$2"
  local o1 o2 o3 o4 ipnum mask net
  IFS=. read -r o1 o2 o3 o4 <<EOF
${ip}
EOF
  [[ -n "${o1}" && -n "${o2}" && -n "${o3}" && -n "${o4}" ]] || return 1
  bits=$((bits))
  (( bits >= 0 && bits <= 32 )) || return 1
  ipnum=$(( (o1 << 24) + (o2 << 16) + (o3 << 8) + o4 ))
  if (( bits == 0 )); then
    mask=0
  else
    mask=$(( (0xFFFFFFFF << (32 - bits)) & 0xFFFFFFFF ))
  fi
  net=$(( ipnum & mask ))
  printf '%d.%d.%d.%d/%d' $(( (net >> 24) & 255 )) $(( (net >> 16) & 255 )) $(( (net >> 8) & 255 )) $(( net & 255 )) "${bits}"
}

# Ensure Address CIDR has a connected route (address added while iface was down often skips it).
ensure_connected_route() {
  local IFACE="$1"
  local ADDR="$2"
  local host bits net

  [[ -z "${ADDR}" ]] && return 0
  # Already have a non-default route via this iface?
  if ip -4 route show dev "${IFACE}" 2>/dev/null | grep -qvE '^default|[[:space:]]default[[:space:]]'; then
    return 0
  fi

  host="${ADDR%%/*}"
  bits="${ADDR##*/}"
  [[ -z "${host}" || "${host}" == "${ADDR}" ]] && return 0
  [[ -z "${bits}" || "${bits}" == "${ADDR}" ]] && bits=32

  net="$(ipv4_network_cidr "${host}" "${bits}" 2>/dev/null || true)"
  [[ -z "${net}" ]] && return 0
  ip -4 route replace "${net}" dev "${IFACE}" proto kernel scope link src "${host}" 2>/dev/null \
    || ip -4 route replace "${net}" dev "${IFACE}" 2>/dev/null \
    || true
}

cleanup_iface() {
  local IFACE="$1"
  local CONF="${CONFIG_DIR}/${IFACE}.conf"

  # Tear down iptables/DNS/marks while the iface still exists (userspace skips awg-quick down hooks).
  if [[ -f "${CONF}" ]] && ip link show "${IFACE}" &>/dev/null; then
    run_conf_hook "${CONF}" "PostDown" "${IFACE}"
  fi

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

  # Bring iface up before Address so the kernel installs a connected route.
  ip link set "${IFACE}" up 2>/dev/null || true

  local addr
  addr="$(awk -F' = ' '/^Address/{print $2; exit}' "${CONF}" || true)"
  if [[ -n "${addr}" ]]; then
    ip address replace "${addr}" dev "${IFACE}" 2>/dev/null || true
    ensure_connected_route "${IFACE}" "${addr}"
  fi

  # Full PostUp from panel-generated conf (FORWARD, MASQUERADE, DNS REDIRECT, resolver-mark).
  run_conf_hook "${CONF}" "PostUp" "${IFACE}"

  # Idempotent fallbacks if PostUp was empty/old or partially failed.
  iptables -C FORWARD -i "${IFACE}" -j ACCEPT 2>/dev/null \
    || iptables -A FORWARD -i "${IFACE}" -j ACCEPT || true
  iptables -C FORWARD -o "${IFACE}" -j ACCEPT 2>/dev/null \
    || iptables -A FORWARD -o "${IFACE}" -j ACCEPT || true
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

  if kernel_failed_active "${IFACE}"; then
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
      unset 'KERNEL_FAILED[$IFACE]'
      unset 'KERNEL_FAILED_AT[$IFACE]'
      return 0
    fi
    echo "[awg] WARN: amneziawg module loaded but awg-quick failed/timed out for ${IFACE}; retrying once..."
    cleanup_iface "${IFACE}"
    sleep 2
    if awg_quick_up "${CONF}"; then
      echo "[awg] ${IFACE} is up via awg-quick (kernel, retry)"
      unset 'KERNEL_FAILED[$IFACE]'
      unset 'KERNEL_FAILED_AT[$IFACE]'
      return 0
    fi
    echo "[awg] ERROR: kernel path failed for ${IFACE} — falling back to userspace (streaming will be slower); retry kernel after ${KERNEL_FAILED_COOLDOWN}s"
    mark_kernel_failed "${IFACE}"
    cleanup_iface "${IFACE}"
  elif [[ "${skip_kernel}" -eq 1 ]]; then
    echo "[awg] ${IFACE}: kernel path recently failed — userspace amneziawg-go (cooldown ${KERNEL_FAILED_COOLDOWN}s)"
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
    # Clear expired KERNEL_FAILED so migration can run after cooldown.
    if kernel_failed_active "${IFACE}"; then
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
