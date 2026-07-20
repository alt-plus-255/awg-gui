#!/usr/bin/env bash
set -euo pipefail

CONFIG_DIR=/config
mkdir -p "${CONFIG_DIR}" "${CONFIG_DIR}/rulesets"
mkdir -p /run

declare -A LAST_MTIMES
LAST_SB_MTIME=0

apply_config() {
  local IFACE="$1"
  local CONF="${CONFIG_DIR}/${IFACE}.conf"

  if [[ ! -f "${CONF}" ]]; then
    return 1
  fi

  if ip link show "${IFACE}" &>/dev/null; then
    awg-quick down "${CONF}" 2>/dev/null || true
    pkill -f "amneziawg-go ${IFACE}" 2>/dev/null || true
    sleep 1
  fi

  if awg-quick up "${CONF}"; then
    echo "[awg] ${IFACE} is up via awg-quick"
    return 0
  fi

  echo "[awg] awg-quick failed for ${IFACE}, trying userspace amneziawg-go"
  amneziawg-go "${IFACE}" &
  sleep 1
  local addr
  addr="$(awk -F' = ' '/^Address/{print $2; exit}' "${CONF}" || true)"
  if [[ -n "${addr}" ]]; then
    ip address replace "${addr}" dev "${IFACE}" 2>/dev/null || true
  fi
  awg setconf "${IFACE}" < <(awg-quick strip "${CONF}") || true
  iptables -t nat -C POSTROUTING -o eth+ -j MASQUERADE 2>/dev/null \
    || iptables -t nat -A POSTROUTING -o eth+ -j MASQUERADE || true
  iptables -C FORWARD -i "${IFACE}" -j ACCEPT 2>/dev/null \
    || iptables -A FORWARD -i "${IFACE}" -j ACCEPT || true
  echo "[awg] ${IFACE} userspace path active"
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
  elif [[ -f "${SB}" ]] && ! pgrep -x sing-box >/dev/null 2>&1; then
    echo "[sing-box] process missing, starting..."
    if [[ -x /config/reload-singbox.sh ]]; then /config/reload-singbox.sh || true; else /usr/local/bin/reload-singbox.sh || true; fi
    LAST_SB_MTIME="${MT}"
  elif [[ ! -f "${SB}" ]] && pgrep -x sing-box >/dev/null 2>&1; then
    echo "[sing-box] config removed, stopping..."
    if [[ -x /config/reload-singbox.sh ]]; then /config/reload-singbox.sh || true; else /usr/local/bin/reload-singbox.sh || true; fi
    LAST_SB_MTIME=0
  fi
}

echo "[awg] Waiting for at least one awg*.conf in ${CONFIG_DIR}..."
while true; do
  shopt -s nullglob
  confs=("${CONFIG_DIR}"/awg*.conf)
  if (( ${#confs[@]} > 0 )); then
    break
  fi
  sleep 5
done

sync_configs
sync_singbox

while true; do
  sleep 3
  sync_configs
  sync_singbox
done
