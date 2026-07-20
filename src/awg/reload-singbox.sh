#!/usr/bin/env bash
# Reload or start/stop sing-box based on /config/sing-box.json
set -euo pipefail

CONFIG=/config/sing-box.json
PIDFILE=/run/sing-box.pid
BIN=/usr/local/bin/sing-box

ensure_tun_routing() {
  if [[ -x /config/resolver-tun-routes.sh ]]; then
    /config/resolver-tun-routes.sh || true
    return 0
  fi
  # Fallback when volume helper is missing (older installs).
  TUN_IFACE=sbox0
  TUN_MARK=0x2
  TUN_TABLE=101
  FAKEIP=198.18.0.0/15
  while ip rule show | grep -q "fwmark ${TUN_MARK} lookup ${TUN_TABLE}"; do
    ip rule del fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || break
  done
  ip rule add fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || true
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if ip link show "${TUN_IFACE}" >/dev/null 2>&1; then
      break
    fi
    sleep 0.2
  done
  if ip link show "${TUN_IFACE}" >/dev/null 2>&1; then
    ip link set "${TUN_IFACE}" up 2>/dev/null || true
    ip route replace "${FAKEIP}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null \
      || ip route add "${FAKEIP}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null || true
    ip route replace "${FAKEIP}" dev "${TUN_IFACE}" 2>/dev/null \
      || ip route add "${FAKEIP}" dev "${TUN_IFACE}" 2>/dev/null || true
    echo "[sing-box] tun routing: mark ${TUN_MARK} → ${TUN_IFACE} (${FAKEIP})"
  else
    echo "[sing-box] warn: ${TUN_IFACE} not present yet" >&2
  fi
}

stop_singbox() {
  if [[ -f "${PIDFILE}" ]]; then
    local pid
    pid="$(cat "${PIDFILE}" 2>/dev/null || true)"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
      kill "${pid}" 2>/dev/null || true
      sleep 1
      kill -9 "${pid}" 2>/dev/null || true
    fi
    rm -f "${PIDFILE}"
  fi
  pkill -x sing-box 2>/dev/null || true
}

start_singbox() {
  if [[ ! -f "${CONFIG}" ]]; then
    stop_singbox
    return 0
  fi

  if ! "${BIN}" check -c "${CONFIG}"; then
    echo "[sing-box] config check failed" >&2
    return 1
  fi

  stop_singbox
  "${BIN}" run -c "${CONFIG}" &
  echo $! > "${PIDFILE}"
  echo "[sing-box] started pid=$(cat "${PIDFILE}")"
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if ip link show sbox0 >/dev/null 2>&1; then
      break
    fi
    sleep 0.2
  done
  ensure_tun_routing
}

start_singbox
