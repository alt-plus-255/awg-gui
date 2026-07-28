#!/usr/bin/env bash
# Reload or start/stop sing-box based on /config/sing-box.json
set -euo pipefail

CONFIG=/config/sing-box.json
PIDFILE=/run/sing-box.pid
BIN=/usr/local/bin/sing-box
CACHE_FILE=/config/sing-box-cache.db
# Soft cap: bbolt does not shrink; drop oversized cache so disk cannot fill unbounded.
CACHE_MAX_BYTES=$((32 * 1024 * 1024))

prune_cache_if_huge() {
  if [[ ! -f "${CACHE_FILE}" ]]; then
    return 0
  fi
  local size
  size="$(wc -c < "${CACHE_FILE}" | tr -d '[:space:]')"
  if [[ "${size}" =~ ^[0-9]+$ ]] && (( size > CACHE_MAX_BYTES )); then
    echo "[sing-box] pruning oversized cache ${CACHE_FILE} (${size} bytes > ${CACHE_MAX_BYTES})"
    rm -f "${CACHE_FILE}"
  fi
}

# Remove leftover TUN iface/routes from older resolver builds.
cleanup_legacy_tun() {
  local TUN_IFACE=sbox0
  local TUN_MARK=0x2
  local TUN_TABLE=101
  while ip rule show 2>/dev/null | grep -q "fwmark ${TUN_MARK} lookup ${TUN_TABLE}"; do
    ip rule del fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || break
  done
  ip route flush table "${TUN_TABLE}" 2>/dev/null || true
  ip link delete "${TUN_IFACE}" 2>/dev/null || true
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
    cleanup_legacy_tun
    return 0
  fi

  if ! "${BIN}" check -c "${CONFIG}"; then
    echo "[sing-box] config check failed" >&2
    return 1
  fi

  prune_cache_if_huge
  stop_singbox
  cleanup_legacy_tun
  "${BIN}" run -c "${CONFIG}" &
  echo $! > "${PIDFILE}"
  echo "[sing-box] started pid=$(cat "${PIDFILE}")"
}

start_singbox
