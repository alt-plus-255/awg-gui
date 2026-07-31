#!/usr/bin/env bash
# Reload or start/stop sing-box based on /config/sing-box.json
set -euo pipefail

CONFIG=/config/sing-box.json
PIDFILE=/run/sing-box.pid
BIN=/usr/local/bin/sing-box
CACHE_FILE=/config/sing-box-cache.db
LOG_FILE=/config/sing-box.log
# Soft cap: bbolt does not shrink; drop oversized cache so disk cannot fill unbounded.
CACHE_MAX_BYTES=$((32 * 1024 * 1024))

prune_cache_if_huge() {
  if [[ ! -f "${CACHE_FILE}" ]]; then
    return 0
  fi
  local size
  size="$(wc -c < "${CACHE_FILE}" | tr -d '[:space:]')"
  if [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -gt "${CACHE_MAX_BYTES}" ]; then
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
  # Do not use pkill -x sing-box: gcompat renames process comm.
  pkill -f "${BIN} run -c ${CONFIG}" 2>/dev/null || true
  sleep 0.2
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

  # setsid: survive parent exit (docker exec from panel kills the session otherwise).
  # Redirect stdio so a closed exec tty cannot SIGPIPE the daemon.
  : >>"${LOG_FILE}"
  setsid "${BIN}" run -c "${CONFIG}" >>"${LOG_FILE}" 2>&1 </dev/null &
  echo $! > "${PIDFILE}"

  local pid
  pid="$(cat "${PIDFILE}")"
  local i
  for i in 1 2 3 4 5 6 7 8 9 10; do
    if kill -0 "${pid}" 2>/dev/null; then
      echo "[sing-box] started pid=${pid}"
      return 0
    fi
    sleep 0.2
  done

  echo "[sing-box] failed to stay running (pid=${pid}); last log:" >&2
  tail -n 40 "${LOG_FILE}" >&2 || true
  rm -f "${PIDFILE}"
  return 1
}

start_singbox
