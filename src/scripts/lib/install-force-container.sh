# install-force-container.sh — force-stop/remove stuck Docker containers (esp. awggui-awg).
# Requires from caller: log(), ok(), warn(), die(), and t() (install-i18n).
# Safe to source multiple times.
#
# AmneziaWG containers with NET_ADMIN / tun / kernel datapath can leave Docker
# unable to kill them ("tried to kill container, but did not receive an exit event").
# Compose then hangs on recreate during upgrades. Call
# prepare_awg_containers_for_recreate before `compose up`, and use
# compose_up_with_awg_recovery for start+retry.

if [[ -n "${_AWG_GUI_FORCE_CONTAINER_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
_AWG_GUI_FORCE_CONTAINER_LOADED=1

_force_container_run_timeout() {
  local secs="$1"
  shift
  if command -v timeout >/dev/null 2>&1; then
    timeout --signal=KILL "${secs}s" "$@" 2>/dev/null
    return $?
  fi
  "$@"
}

# Like _force_container_run_timeout but keep stderr for exit-event detection.
_force_container_run_timeout_capture() {
  local secs="$1"
  shift
  if command -v timeout >/dev/null 2>&1; then
    timeout --signal=KILL "${secs}s" "$@" 2>&1
    return $?
  fi
  "$@" 2>&1
}

_force_container_exit_event_stuck() {
  local text="$1"
  printf '%s' "${text}" | grep -qi 'did not receive an exit event'
}

_force_container_exists() {
  local name="$1"
  docker inspect "${name}" >/dev/null 2>&1
}

_force_container_status() {
  docker inspect -f '{{.State.Status}}' "$1" 2>/dev/null || true
}

_force_container_pid() {
  local pid
  pid="$(docker inspect -f '{{.State.Pid}}' "$1" 2>/dev/null || true)"
  if [[ -n "${pid}" && "${pid}" != "0" ]]; then
    printf '%s' "${pid}"
  fi
}

# Best-effort teardown of AWG/WG ifaces + userspace inside a still-running container.
_force_container_soft_teardown_awg() {
  local name="$1"
  [[ "$(_force_container_status "${name}")" == "running" ]] || return 0
  _force_container_run_timeout 25 docker exec "${name}" sh -c '
    for conf in /config/*.conf; do
      [ -f "$conf" ] || continue
      awg-quick down "$conf" >/dev/null 2>&1 || true
    done
    pkill -9 -f amneziawg-go >/dev/null 2>&1 || true
    pkill -9 sing-box >/dev/null 2>&1 || true
    ip -o link show 2>/dev/null | awk -F": " "{print \$2}" | cut -d@ -f1 | while read -r iface; do
      case "$iface" in
        lo|eth*|docker*|br-*|cni*|veth*) continue ;;
        *) ip link delete "$iface" >/dev/null 2>&1 || true ;;
      esac
    done
  ' >/dev/null 2>&1 || true
}

# Delete tunnel ifaces in the container network namespace via nsenter (when exec fails).
_force_container_nsenter_cleanup() {
  local pid="$1"
  local iface
  [[ -n "${pid}" ]] || return 0
  [[ -d "/proc/${pid}" ]] || return 0
  command -v nsenter >/dev/null 2>&1 || return 0
  while read -r iface; do
    [[ -n "${iface}" ]] || continue
    case "${iface}" in
      lo|eth*|docker*|br-*|cni*|veth*) continue ;;
      *) nsenter -t "${pid}" -n -- ip link delete "${iface}" >/dev/null 2>&1 || true ;;
    esac
  done < <(nsenter -t "${pid}" -n -- ip -o link show 2>/dev/null | awk -F': ' '{print $2}' | cut -d@ -f1 || true)
}

_force_container_wait_docker() {
  local i
  for i in $(seq 1 90); do
    if docker info >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

_force_container_restart_docker() {
  warn "$(t warn_restart_docker_stuck)"
  if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files docker.service >/dev/null 2>&1; then
    systemctl restart docker >/dev/null 2>&1 || true
  elif command -v service >/dev/null 2>&1; then
    service docker restart >/dev/null 2>&1 || true
  else
    return 1
  fi
  _force_container_wait_docker
}

_force_container_host_kill() {
  local name="$1"
  local pid=""
  pid="$(_force_container_pid "${name}")"
  if [[ -n "${pid}" ]]; then
    warn "$(t warn_kill_container_host_pid "${name}" "${pid}")"
    _force_container_nsenter_cleanup "${pid}"
    kill -9 "${pid}" 2>/dev/null || true
    if [[ -r "/proc/${pid}/task/${pid}/children" ]]; then
      # shellcheck disable=SC2046
      kill -9 $(cat "/proc/${pid}/task/${pid}/children" 2>/dev/null) 2>/dev/null || true
    fi
    sleep 1
  fi
}

_force_container_rm_loop() {
  local name="$1"
  local rounds="${2:-5}"
  local i
  _force_container_run_timeout 30 docker rm -f "${name}" >/dev/null 2>&1 || true
  for i in $(seq 1 "${rounds}"); do
    _force_container_exists "${name}" || return 0
    sleep 1
    _force_container_run_timeout 10 docker rm -f "${name}" >/dev/null 2>&1 || true
  done
  _force_container_exists "${name}" && return 1
  return 0
}

# Force-remove one container. Volumes are never touched.
# Returns 0 if gone (or never existed), 1 if still present after all escalations.
force_remove_container() {
  local name="$1"
  local allow_docker_restart="${2:-1}"
  local status="" out="" exit_stuck=0

  _force_container_exists "${name}" || return 0

  log "$(t log_force_removing_container "${name}")"

  # 1) Prefer clean AWG teardown so the kernel/netns is less likely to wedge.
  case "${name}" in
    *awggui-awg) _force_container_soft_teardown_awg "${name}" ;;
  esac

  # 2) docker stop / kill — detect "did not receive an exit event" early.
  out="$(_force_container_run_timeout_capture 20 docker stop -t 8 "${name}" || true)"
  if _force_container_exit_event_stuck "${out}"; then
    exit_stuck=1
  fi
  status="$(_force_container_status "${name}")"
  if [[ "${status}" == "exited" || "${status}" == "created" || "${status}" == "dead" ]]; then
    out="$(_force_container_run_timeout_capture 30 docker rm -f "${name}" || true)"
    if _force_container_exit_event_stuck "${out}"; then
      exit_stuck=1
    fi
    _force_container_exists "${name}" || { ok "$(t ok_force_removed_container "${name}")"; return 0; }
  fi

  out="$(_force_container_run_timeout_capture 15 docker kill -s KILL "${name}" || true)"
  if _force_container_exit_event_stuck "${out}"; then
    exit_stuck=1
  fi
  sleep 1

  # 3) Host PID + netns iface cleanup
  _force_container_host_kill "${name}"
  out="$(_force_container_run_timeout_capture 30 docker rm -f "${name}" || true)"
  if _force_container_exit_event_stuck "${out}"; then
    exit_stuck=1
  fi
  if _force_container_rm_loop "${name}" 5; then
    ok "$(t ok_force_removed_container "${name}")"
    return 0
  fi

  # 4) Exit-event wedge or still present → restart Docker engine immediately.
  if [[ "${allow_docker_restart}" == "1" ]] && { [[ "${exit_stuck}" -eq 1 ]] || _force_container_exists "${name}"; }; then
    if [[ "${exit_stuck}" -eq 1 ]]; then
      warn "$(t warn_exit_event_stuck "${name}")"
    fi
    if _force_container_restart_docker; then
      sleep 2
      if _force_container_rm_loop "${name}" 10; then
        ok "$(t ok_force_removed_container "${name}")"
        return 0
      fi
    fi
  fi

  if _force_container_exists "${name}"; then
    warn "$(t warn_container_still_stuck "${name}")"
    return 1
  fi
  ok "$(t ok_force_removed_container "${name}")"
  return 0
}

# Drop Created leftovers and Compose recreate orphans (hex_awggui-*).
# Keeps running db / docker-proxy.
_force_remove_created_and_orphan_awggui() {
  local name
  while read -r name; do
    [[ -n "${name}" ]] || continue
    case "${name}" in
      awggui-db|awggui-docker-proxy) continue ;;
    esac
    # Compose recreate leftovers: 665dfc6e29ed_awggui-awg / _awggui-caddy / etc.
    if [[ "${name}" =~ ^[0-9a-f]+_awggui- ]]; then
      force_remove_container "${name}" 1 || true
      continue
    fi
    if [[ "$(_force_container_status "${name}")" == "created" ]]; then
      _force_container_run_timeout 20 docker rm -f "${name}" >/dev/null 2>&1 || true
    fi
  done < <(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^([0-9a-f]+_)?awggui-' || true)
}

_awg_iface_containers_present() {
  docker ps -a --format '{{.Names}}' 2>/dev/null | grep -qE '(^|_)awggui-awg$'
}

# Remove awggui-awg and compose recreate leftovers (*_awggui-awg) before up/recreate.
prepare_awg_containers_for_recreate() {
  local name
  local names=()
  local failed=0

  _force_remove_created_and_orphan_awggui

  while read -r name; do
    [[ -n "${name}" ]] || continue
    names+=("${name}")
  done < <(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '(^|_)awggui-awg$' || true)

  if [[ "${#names[@]}" -eq 0 ]]; then
    return 0
  fi

  log "$(t log_preparing_awg_recreate)"
  for name in "${names[@]}"; do
    if ! force_remove_container "${name}" 1; then
      failed=1
    fi
  done

  # Second pass for Created leftovers after awg remove.
  _force_remove_created_and_orphan_awggui

  return "${failed}"
}

# compose up with pre-cleanup and one recovery retry on stuck-kill errors.
# Caller must define: compose() wrapping `docker compose ...`.
# Aborts (die) if awg container cannot be removed — avoid doomed recreate loops.
compose_up_with_awg_recovery() {
  prepare_awg_containers_for_recreate || true
  if _awg_iface_containers_present; then
    die "$(t err_awg_container_stuck_reboot)"
  fi

  if compose up -d --remove-orphans; then
    return 0
  fi

  warn "$(t warn_compose_up_stuck_retry)"
  prepare_awg_containers_for_recreate || true

  # Broader pass: force-remove containers compose may have tried to recreate
  # (keep healthy db / docker-proxy when possible).
  local name
  while read -r name; do
    [[ -n "${name}" ]] || continue
    case "${name}" in
      awggui-db|awggui-docker-proxy) continue ;;
      *awggui-awg*|awggui-awg|awggui-caddy|awggui-app|awggui-panel-ops)
        force_remove_container "${name}" 1 || true
        ;;
    esac
  done < <(docker ps -a --format '{{.Names}}' 2>/dev/null | grep -E '^([0-9a-f]+_)?awggui-' || true)

  _force_remove_created_and_orphan_awggui

  if _awg_iface_containers_present; then
    die "$(t err_awg_container_stuck_reboot)"
  fi

  compose up -d --remove-orphans
}
