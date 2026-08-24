#!/usr/bin/env bash
# On-demand sing-box probe for subscription ping tests (no TUN, separate from production).
set -euo pipefail

CONFIG=/config/sing-box-ping.json
PIDFILE=/run/sing-box-ping.pid
BIN=/usr/local/bin/sing-box
LOG_FILE=/config/sing-box-ping.log
LOG_MAX_BYTES=$((10 * 1024 * 1024))
ACTION="${1:-start}"

rotate_log_if_huge() {
  local file="${1:-}"
  [[ -n "${file}" && -f "${file}" ]] || return 0
  local size
  size="$(wc -c < "${file}" | tr -d '[:space:]')"
  if [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -gt "${LOG_MAX_BYTES}" ]; then
    echo "[sing-box-ping] rotating oversized log ${file} (${size} bytes > ${LOG_MAX_BYTES})"
    rm -f "${file}.1"
    mv -f "${file}" "${file}.1"
  fi
}

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

  rotate_log_if_huge "${LOG_FILE}"
  # setsid: survive parent exit (docker exec from panel kills the session otherwise).
  : >>"${LOG_FILE}"
  setsid "${BIN}" run -c "${CONFIG}" >>"${LOG_FILE}" 2>&1 </dev/null &
  echo $! > "${PIDFILE}"

  local pid
  pid="$(cat "${PIDFILE}")"
  local i
  for i in 1 2 3 4 5 6 7 8 9 10; do
    if kill -0 "${pid}" 2>/dev/null; then
      echo "[sing-box-ping] started pid=${pid}"
      return 0
    fi
    sleep 0.2
  done

  echo "[sing-box-ping] failed to stay running (pid=${pid}); last log:" >&2
  tail -n 40 "${LOG_FILE}" >&2 || true
  rm -f "${PIDFILE}"
  return 1
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
