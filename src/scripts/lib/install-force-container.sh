# install-force-container.sh — force-stop/remove stuck Docker containers (esp. awggui-awg).
# Requires from caller: log(), ok(), warn(), die(), and t() (install-i18n).
# Safe to source multiple times.
#
# AmneziaWG containers with NET_ADMIN / tun / kernel datapath can leave Docker
# unable to kill them ("tried to kill container, but did not receive an exit event").
# Compose then hangs on recreate during upgrades. Call
# prepare_awg_containers_for_recreate before `compose up`, use
# compose_upgrade_with_awg_recovery for upgrades, and
# compose_up_with_awg_recovery for fresh install/repair.

if [[ -n "${_AWG_GUI_FORCE_CONTAINER_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
_AWG_GUI_FORCE_CONTAINER_LOADED=1
readonly _AWG_COMPOSE_UP_TIMEOUT="${AWG_COMPOSE_UP_TIMEOUT:-300}"
readonly _AWG_FORCE_REMOVE_BUDGET="${AWG_FORCE_REMOVE_BUDGET:-120}"
readonly _AWG_COMPOSE_DOWN_TIMEOUT="${AWG_COMPOSE_DOWN_TIMEOUT:-180}"

_upgrade_log_phase() {
  local phase="$1"
  local ts msg=""
  ts="$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || true)"
  [[ -n "${ts}" ]] || return 0
  if [[ -f /etc/awg-gui/update.log ]]; then
    echo "[${ts}] [phase] ${phase}" >> /etc/awg-gui/update.log 2>/dev/null || true
  fi
  case "${phase}" in
    quiesce) msg="$(t log_upgrade_phase_quiesce)" ;;
    kernel) msg="$(t log_upgrade_phase_kernel)" ;;
    awg_remove) msg="$(t log_upgrade_phase_awg_remove)" ;;
    compose) msg="$(t log_upgrade_phase_compose)" ;;
    *) msg="Upgrade phase: ${phase}" ;;
  esac
  log "${msg}"
}

_force_container_run_timeout() {
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

# Like _force_container_run_timeout but keep stderr for exit-event detection.
_force_container_run_timeout_capture() {
  local secs="$1"
  shift
  if command -v timeout >/dev/null 2>&1; then
    timeout --signal=KILL "${secs}s" "$@" 2>&1
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

_force_container_exit_event_stuck() {
  local text="$1"
  printf '%s' "${text}" | grep -qi 'did not receive an exit event'
}

_force_container_oci_zombie_stuck() {
  local text="$1"
  printf '%s' "${text}" | grep -qiE 'failed to open /proc/[0-9]+/ns/ipc|OCI runtime exec failed'
}

_force_remove_budget_exceeded() {
  local deadline="$1"
  [[ "$(date +%s)" -ge "${deadline}" ]]
}

_force_container_zombie_pid() {
  local name="$1"
  local pid=""
  pid="$(_force_container_pid "${name}")"
  [[ -n "${pid}" && ! -d "/proc/${pid}" ]]
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

_force_host_kernel_module_loaded() {
  lsmod 2>/dev/null | awk '{print $1}' | grep -qx amneziawg
}

_force_host_kernel_blacklisted() {
  [[ -f /etc/modprobe.d/blacklist-amneziawg.conf ]]
}

_force_kernel_host_script() {
  if [[ -x /etc/awg-gui/awg-kernel-host.sh ]]; then
    printf '%s' /etc/awg-gui/awg-kernel-host.sh
    return 0
  fi
  return 1
}

# Container or persisted config marks kernel datapath as broken (setconf/oops).
_force_awg_kernel_bad_marker() {
  if docker inspect awggui-awg >/dev/null 2>&1; then
    if [[ "$(_force_container_status awggui-awg)" == "running" ]]; then
      if _force_container_run_timeout 5 docker exec awggui-awg test -f /config/awg-kernel-bad 2>/dev/null; then
        return 0
      fi
      if _force_container_run_timeout 5 docker exec awggui-awg test -f /run/awg-kernel-bad 2>/dev/null; then
        return 0
      fi
    fi
  fi
  local vol
  vol="$(docker volume ls -q --filter name=awg_config 2>/dev/null | head -1 || true)"
  [[ -n "${vol}" ]] || return 1
  local img
  img="$(docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep -E '^awggui-awg:' | head -1 || true)"
  [[ -n "${img}" ]] || return 1
  _force_container_run_timeout 15 docker run --rm --entrypoint '' -v "${vol}:/config:ro" "${img}" \
    sh -c 'test -f /config/awg-kernel-bad' 2>/dev/null
}

# Unload/blacklist host amneziawg before stopping awggui-awg — prevents netlink wedge on upgrade.
prepare_host_kernel_before_awg_recreate() {
  local need=0
  local script action=""

  if _force_host_kernel_module_loaded; then
    need=1
  fi
  if _force_host_kernel_blacklisted; then
    need=1
  fi
  if _force_awg_kernel_bad_marker; then
    need=1
  fi
  if [[ "${need}" -eq 0 ]]; then
    return 0
  fi

  log "$(t log_preparing_host_kernel_awg_stop)"

  if script="$(_force_kernel_host_script)"; then
    action="$(_force_container_run_timeout_capture 15 "${script}" prepare-for-container-stop 2>/dev/null || true)"
    case "${action}" in
      *'"action":"unloaded"'*)
        ok "$(t ok_host_kernel_unloaded)"
        ;;
      *'"action":"blacklisted"'*)
        warn "$(t warn_host_kernel_blacklisted)"
        ;;
      *'"action":"blacklist_already_present"'*)
        ok "$(t ok_host_kernel_blacklist_present)"
        ;;
    esac
    return 0
  fi

  # Fallback when helper script is missing (dev tree install).
  if _force_host_kernel_blacklisted; then
    ok "$(t ok_host_kernel_blacklist_present)"
    return 0
  fi
  if _force_host_kernel_module_loaded; then
    if _force_container_run_timeout 5 modprobe -r amneziawg 2>/dev/null; then
      ok "$(t ok_host_kernel_unloaded)"
      return 0
    fi
    warn "$(t warn_host_kernel_blacklisted)"
    mkdir -p /etc/modprobe.d 2>/dev/null || true
    printf '%s\n' 'blacklist amneziawg' > /etc/modprobe.d/blacklist-amneziawg.conf
  fi
}

# Best-effort teardown of AWG/WG ifaces + userspace inside a still-running container.
_force_container_soft_teardown_awg() {
  local name="$1"
  local skip_quick=0
  [[ "$(_force_container_status "${name}")" == "running" ]] || return 0
  if _force_host_kernel_module_loaded || _force_host_kernel_blacklisted || _force_awg_kernel_bad_marker; then
    skip_quick=1
  fi
  _force_container_run_timeout 25 docker exec "${name}" sh -c "
    skip_quick=${skip_quick}
    if [[ \"\${skip_quick}\" -eq 0 ]]; then
      for conf in /config/*.conf; do
        [ -f \"\$conf\" ] || continue
        timeout 8 awg-quick down \"\$conf\" >/dev/null 2>&1 || true
      done
    fi
    pkill -9 -f amneziawg-go >/dev/null 2>&1 || true
    pkill -9 sing-box >/dev/null 2>&1 || true
    ip -o link show 2>/dev/null | awk -F\": \" \"{print \$2}\" | cut -d@ -f1 | while read -r iface; do
      case \"\$iface\" in
        lo|eth*|docker*|br-*|cni*|veth*) continue ;;
        *)
          if command -v timeout >/dev/null 2>&1; then
            timeout --signal=KILL 2 ip link delete \"\$iface\" >/dev/null 2>&1 || true
          else
            ip link delete \"\$iface\" >/dev/null 2>&1 || true
          fi
          ;;
      esac
    done
  " >/dev/null 2>&1 || true
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

_force_container_restart_docker_for_stuck() {
  local name="$1"
  local reason="$2"
  if [[ "${reason}" == "exit_event" ]]; then
    warn "$(t warn_exit_event_stuck "${name}")"
  else
    warn "$(t warn_docker_restart_required)"
  fi
  if _force_container_restart_docker; then
    sleep 2
    if _force_container_rm_loop "${name}" 10; then
      ok "$(t ok_force_removed_container "${name}")"
      return 0
    fi
  fi
  return 1
}

# Force-remove one container. Volumes are never touched.
# Returns 0 if gone (or never existed), 1 if still present after all escalations.
force_remove_container() {
  local name="$1"
  local allow_docker_restart="${2:-1}"
  local status="" out="" exit_stuck=0 zombie=0
  local deadline=$(( $(date +%s) + _AWG_FORCE_REMOVE_BUDGET ))

  _force_container_exists "${name}" || return 0

  log "$(t log_force_removing_container "${name}")"

  # 1) Prefer clean AWG teardown so the kernel/netns is less likely to wedge.
  case "${name}" in
    *awggui-awg) _force_container_soft_teardown_awg "${name}" ;;
  esac

  if _force_remove_budget_exceeded "${deadline}"; then
    warn "$(t err_force_remove_timeout "${name}")"
    return 1
  fi

  # 2) docker stop / kill — detect "did not receive an exit event" early.
  out="$(_force_container_run_timeout_capture 20 docker stop -t 8 "${name}" || true)"
  if _force_container_exit_event_stuck "${out}" || _force_container_oci_zombie_stuck "${out}"; then
    exit_stuck=1
  fi
  status="$(_force_container_status "${name}")"
  if [[ "${status}" == "exited" || "${status}" == "created" || "${status}" == "dead" ]]; then
    out="$(_force_container_run_timeout_capture 30 docker rm -f "${name}" || true)"
    if _force_container_exit_event_stuck "${out}" || _force_container_oci_zombie_stuck "${out}"; then
      exit_stuck=1
    fi
    _force_container_exists "${name}" || { ok "$(t ok_force_removed_container "${name}")"; return 0; }
  fi

  if _force_remove_budget_exceeded "${deadline}"; then
    warn "$(t err_force_remove_timeout "${name}")"
    return 1
  fi

  out="$(_force_container_run_timeout_capture 15 docker kill -s KILL "${name}" || true)"
  if _force_container_exit_event_stuck "${out}" || _force_container_oci_zombie_stuck "${out}"; then
    exit_stuck=1
  fi
  sleep 1

  # 3) Host PID + netns iface cleanup
  _force_container_host_kill "${name}"
  if _force_container_zombie_pid "${name}"; then
    zombie=1
  fi
  if [[ "${allow_docker_restart}" == "1" ]] && { [[ "${zombie}" -eq 1 ]] || [[ "${exit_stuck}" -eq 1 ]]; }; then
    local reason="zombie"
    [[ "${exit_stuck}" -eq 1 && "${zombie}" -eq 0 ]] && reason="exit_event"
    if _force_container_restart_docker_for_stuck "${name}" "${reason}"; then
      return 0
    fi
  fi

  if _force_remove_budget_exceeded "${deadline}"; then
    warn "$(t err_force_remove_timeout "${name}")"
    return 1
  fi

  out="$(_force_container_run_timeout_capture 30 docker rm -f "${name}" || true)"
  if _force_container_exit_event_stuck "${out}" || _force_container_oci_zombie_stuck "${out}"; then
    exit_stuck=1
  fi
  if _force_container_rm_loop "${name}" 5; then
    ok "$(t ok_force_removed_container "${name}")"
    return 0
  fi

  # 4) Exit-event wedge or still present → restart Docker engine immediately.
  if [[ "${allow_docker_restart}" == "1" ]] && { [[ "${exit_stuck}" -eq 1 ]] || _force_container_exists "${name}"; }; then
    if _force_container_restart_docker_for_stuck "${name}" "exit_event"; then
      return 0
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

# Stop containers that docker exec into awggui-awg during upgrades.
quiesce_docker_exec_clients() {
  local name stopped=0
  log "$(t log_quiesce_exec_clients)"
  for name in awggui-app awggui-panel-ops awggui-caddy; do
    if docker inspect "${name}" >/dev/null 2>&1; then
      if _force_container_run_timeout 30 docker stop -t 10 "${name}" >/dev/null 2>&1; then
        stopped=1
      fi
    fi
  done
  [[ "${stopped}" -eq 1 ]] && sleep 2
  return 0
}

# Remove awggui-awg and compose recreate leftovers (*_awggui-awg) before up/recreate.
# strict=1: die immediately if AWG cannot be removed (upgrade path).
prepare_awg_containers_for_recreate() {
  local strict="${1:-0}"
  local name
  local names=()
  local failed=0

  prepare_host_kernel_before_awg_recreate

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

  if [[ "${strict}" -eq 1 && "${failed}" -eq 1 ]]; then
    die "$(t err_awg_container_stuck_reboot)"
  fi

  return "${failed}"
}

# Caller must define: compose() wrapping `docker compose ...`.
# timeout(1) cannot invoke shell functions — run compose via bash -c with export -f.
compose_up_timed_capture() {
  local ec=0 out=""
  if ! declare -F compose >/dev/null 2>&1; then
    out="$(_force_container_run_timeout_capture "${_AWG_COMPOSE_UP_TIMEOUT}" compose "$@" 2>&1)" || ec=$?
    printf '%s' "${out}"
    return "${ec}"
  fi
  export -f compose 2>/dev/null || true
  if command -v timeout >/dev/null 2>&1; then
    out="$(timeout --signal=KILL "${_AWG_COMPOSE_UP_TIMEOUT}s" bash -c 'compose "$@"' _ "$@" 2>&1)" || ec=$?
  else
    out="$(compose "$@" 2>&1)" || ec=$?
  fi
  printf '%s' "${out}"
  return "${ec}"
}

compose_up_timed() {
  compose_up_timed_capture "$@" >/dev/null
}

compose_down_timed() {
  local ec=0
  if declare -F compose >/dev/null 2>&1; then
    export -f compose 2>/dev/null || true
    if command -v timeout >/dev/null 2>&1; then
      timeout --signal=KILL "${_AWG_COMPOSE_DOWN_TIMEOUT}s" bash -c 'compose "$@"' _ "$@" >/dev/null 2>&1
      return $?
    fi
    compose "$@" >/dev/null 2>&1
    return $?
  fi
  _force_container_run_timeout "${_AWG_COMPOSE_DOWN_TIMEOUT}" compose "$@" >/dev/null 2>&1
}

_compose_upgrade_up() {
  local phase="$1"
  local allow_die="${2:-1}"
  local out="" ec=0 msg=""
  shift 2
  case "${phase}" in
    base) msg="$(t log_upgrade_compose_phase_base)" ;;
    awg) msg="$(t log_upgrade_compose_phase_awg)" ;;
    front) msg="$(t log_upgrade_compose_phase_front)" ;;
    *) msg="$(t log_upgrade_compose_phase "${phase}")" ;;
  esac
  log "${msg}"
  out="$(compose_up_timed_capture "$@")" || ec=$?
  if [[ "${ec}" -eq 0 ]]; then
    return 0
  fi
  if [[ -n "${out}" ]]; then
    printf '%s\n' "${out}" >&2
  fi
  if [[ "${allow_die}" -eq 0 ]]; then
    return "${ec}"
  fi
  if [[ "${ec}" -eq 124 ]]; then
    die "$(t err_upgrade_compose_timeout "${phase}")"
  fi
  die "$(t err_upgrade_compose_failed "${phase}")"
}

# Phased upgrade: quiesce exec clients, strict AWG remove, then compose up in stages.
compose_upgrade_with_awg_recovery() {
  _upgrade_log_phase quiesce
  quiesce_docker_exec_clients

  _upgrade_log_phase kernel
  prepare_host_kernel_before_awg_recreate

  _upgrade_log_phase awg_remove
  if ! prepare_awg_containers_for_recreate 1; then
    die "$(t err_awg_container_stuck_reboot)"
  fi
  if _awg_iface_containers_present; then
    die "$(t err_awg_container_stuck_reboot)"
  fi

  _upgrade_log_phase compose
  _compose_upgrade_up base 1 up -d --no-deps db docker-proxy
  if ! _compose_upgrade_up awg 0 up -d --no-deps awg; then
    warn "$(t warn_compose_up_stuck_retry)"
    prepare_awg_containers_for_recreate 1 || die "$(t err_awg_container_stuck_reboot)"
    _compose_upgrade_up awg 1 up -d --no-deps awg
  fi
  _compose_upgrade_up front 1 up -d app panel-ops caddy --remove-orphans
  return 0
}

# compose up with pre-cleanup and one recovery retry on stuck-kill errors.
# Aborts (die) if awg container cannot be removed — avoid doomed recreate loops.
compose_up_with_awg_recovery() {
  prepare_awg_containers_for_recreate 0 || true
  if _awg_iface_containers_present; then
    die "$(t err_awg_container_stuck_reboot)"
  fi

  if compose_up_timed up -d --remove-orphans; then
    return 0
  fi

  warn "$(t warn_compose_up_stuck_retry)"
  quiesce_docker_exec_clients || true
  prepare_awg_containers_for_recreate 0 || true

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

  compose_up_timed up -d --remove-orphans
}
