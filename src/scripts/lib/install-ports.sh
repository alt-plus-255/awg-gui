# install-ports.sh — pick free host TCP/UDP ports for awg-gui install.
# Requires from caller: warn(), die(), and t() (install-i18n).
# Safe to source multiple times.

if [[ -n "${_AWG_GUI_INSTALL_PORTS_LOADED:-}" ]]; then
  return 0 2>/dev/null || true
fi
_AWG_GUI_INSTALL_PORTS_LOADED=1

# Space-separated "tcp:8877" / "udp:51820" reserved during this install run.
_INSTALL_PORTS_RESERVED=""

# Drop non-entry lines left by a corrupted multiline .env write.
cleanup_env_file_orphans() {
  local file="$1" tmp
  [[ -f "${file}" ]] || return 0
  tmp="$(mktemp)"
  awk '
    /^[A-Za-z_][A-Za-z0-9_]*=/ { print; next }
    /^[[:space:]]*#/ { print; next }
    /^[[:space:]]*$/ { print; next }
    { next }
  ' "${file}" > "${tmp}"
  mv "${tmp}" "${file}"
  chmod 600 "${file}" 2>/dev/null || true
}

# Return 0 if something is listening on host port (any process).
host_port_listening() {
  local port="$1" proto="$2"
  if command -v ss >/dev/null 2>&1; then
    if [[ "${proto}" == "udp" ]]; then
      ss -H -lun "( sport = :${port} )" 2>/dev/null | grep -q .
    else
      ss -H -ltn "( sport = :${port} )" 2>/dev/null | grep -q .
    fi
    return $?
  fi
  if command -v netstat >/dev/null 2>&1; then
    if [[ "${proto}" == "udp" ]]; then
      netstat -lun 2>/dev/null | grep -qE "[.:]${port}[[:space:]]"
    else
      netstat -ltn 2>/dev/null | grep -qE "[.:]${port}[[:space:]]"
    fi
    return $?
  fi
  if command -v python3 >/dev/null 2>&1; then
    if [[ "${proto}" == "udp" ]]; then
      python3 -c "import socket;s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);s.bind(('',int('${port}')));s.close()" 2>/dev/null && return 1 || return 0
    fi
    python3 -c "import socket;s=socket.socket(socket.AF_INET,socket.SOCK_STREAM);s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);s.bind(('',int('${port}')));s.close()" 2>/dev/null && return 1 || return 0
  fi
  # Cannot probe — assume free so install can proceed.
  return 1
}

# Return 0 if an awggui-* container already publishes this host port.
awggui_publishes_port() {
  local port="$1" proto="$2"
  local ports_info
  ports_info="$(docker ps --filter "name=awggui-" --format '{{.Ports}}' 2>/dev/null || true)"
  [[ -n "${ports_info}" ]] || return 1
  if [[ "${proto}" == "udp" ]]; then
    printf '%s\n' "${ports_info}" | grep -qE "(^|[[:space:],])([0-9.]+|\[::\]|::)?:${port}->[^[:space:],]*/udp"
  else
    printf '%s\n' "${ports_info}" | grep -qE "(^|[[:space:],])([0-9.]+|\[::\]|::)?:${port}->[^[:space:],]*/tcp"
  fi
}

# Return 0 if port cannot be used for a new publish mapping.
port_is_busy() {
  local port="$1" proto="$2"
  if [[ " ${_INSTALL_PORTS_RESERVED} " == *" ${proto}:${port} "* ]]; then
    return 0
  fi
  if awggui_publishes_port "${port}" "${proto}"; then
    return 1
  fi
  host_port_listening "${port}" "${proto}"
}

_random_high_port() {
  local n
  n="$(od -An -N2 -tu2 /dev/urandom 2>/dev/null | tr -d '[:space:]')"
  if [[ -z "${n}" ]]; then
    n="${RANDOM:-12345}"
  fi
  # 20000–59999
  printf '%s' $(( 20000 + (n % 40000) ))
}

# Keep only a valid 1–65535 port; otherwise return default (or empty).
sanitize_port_value() {
  local val="$1" default="${2:-}"
  val="${val%%$'\n'*}"
  val="${val%%$'\r'*}"
  val="${val#"${val%%[![:space:]]*}"}"
  val="${val%"${val##*[![:space:]]}"}"
  if [[ "${val}" =~ ^[1-9][0-9]*$ ]] && (( val <= 65535 )); then
    printf '%s' "${val}"
    return 0
  fi
  printf '%s' "${default}"
}

# Echo a free port. If preferred is free, keep it; otherwise pick a random free port and warn.
# Args: preferred_port proto(tcp|udp) label
# IMPORTANT: only the port number goes to stdout (callers use command substitution).
ensure_host_port() {
  local preferred="$1" proto="$2" label="$3"
  local chosen="" attempts=0 candidate

  preferred="$(sanitize_port_value "${preferred}")"

  if [[ -n "${preferred}" ]] && ! port_is_busy "${preferred}" "${proto}"; then
    _INSTALL_PORTS_RESERVED="${_INSTALL_PORTS_RESERVED} ${proto}:${preferred}"
    printf '%s' "${preferred}"
    return 0
  fi

  while (( attempts < 64 )); do
    candidate="$(_random_high_port)"
    if ! port_is_busy "${candidate}" "${proto}"; then
      chosen="${candidate}"
      break
    fi
    attempts=$((attempts + 1))
  done
  [[ -n "${chosen}" ]] || die "$(t err_no_free_port "${label}")"

  # Warnings must go to stderr — this function's stdout is captured into port vars.
  if [[ -n "${preferred}" ]]; then
    warn "$(t warn_port_busy "${label}" "${preferred}" "${chosen}")" >&2
  else
    warn "$(t warn_port_invalid "${label}" "${1:-?}" "${chosen}")" >&2
  fi

  _INSTALL_PORTS_RESERVED="${_INSTALL_PORTS_RESERVED} ${proto}:${chosen}"
  printf '%s' "${chosen}"
}
