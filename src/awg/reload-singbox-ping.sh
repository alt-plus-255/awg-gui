#!/usr/bin/env bash
# On-demand sing-box probe for subscription ping tests (no TUN, separate from production).
set -euo pipefail

CONFIG=/config/sing-box-ping.json
PIDFILE=/run/sing-box-ping.pid
BIN=/usr/local/bin/sing-box
ACTION="${1:-start}"

stop_ping() {
  if [[ -f "${PIDFILE}" ]]; then
    local pid
    pid="$(cat "${PIDFILE}" 2>/dev/null || true)"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
      kill "${pid}" 2>/dev/null || true
      sleep 0.5
      kill -9 "${pid}" 2>/dev/null || true
    fi
    rm -f "${PIDFILE}"
  fi
}

start_ping() {
  if [[ ! -f "${CONFIG}" ]]; then
    echo "[sing-box-ping] config missing: ${CONFIG}" >&2
    return 1
  fi

  if ! "${BIN}" check -c "${CONFIG}"; then
    echo "[sing-box-ping] config check failed" >&2
    return 1
  fi

  if [[ -f "${PIDFILE}" ]]; then
    local pid
    pid="$(cat "${PIDFILE}" 2>/dev/null || true)"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
      echo "[sing-box-ping] already running pid=${pid}"
      return 0
    fi
  fi

  "${BIN}" run -c "${CONFIG}" &
  echo $! > "${PIDFILE}"
  echo "[sing-box-ping] started pid=$(cat "${PIDFILE}")"
}

reload_ping() {
  if [[ ! -f "${CONFIG}" ]]; then
    stop_ping
    return 0
  fi

  if [[ -f "${PIDFILE}" ]]; then
    local pid
    pid="$(cat "${PIDFILE}" 2>/dev/null || true)"
    if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
      if ! "${BIN}" check -c "${CONFIG}"; then
        echo "[sing-box-ping] config check failed on reload" >&2
        return 1
      fi
      kill -HUP "${pid}" 2>/dev/null || {
        stop_ping
        start_ping
        return $?
      }
      echo "[sing-box-ping] reloaded pid=${pid}"
      return 0
    fi
  fi

  start_ping
}

case "${ACTION}" in
  start) start_ping ;;
  stop) stop_ping ;;
  reload) reload_ping ;;
  *)
    echo "usage: $0 {start|stop|reload}" >&2
    exit 1
    ;;
esac
