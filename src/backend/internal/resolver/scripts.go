package resolver

import (
	"context"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type Scripts struct {
	ConfigDir string
	Docker    Docker
	Container string
	Files     FileHelper
}

func (s *Scripts) EnsureResolverMarkScripts() (bool, error) {
	dir := s.ConfigDir
	_ = os.MkdirAll(dir, 0o755)
	legacy := filepath.Join(dir, "resolver-tun-routes.sh")
	_ = os.Remove(legacy)

	changed := false
	ch, err := s.Files.WriteExecutable(filepath.Join(dir, "resolver-mark.sh"), markScriptBody())
	if err != nil {
		return false, err
	}
	changed = changed || ch
	ch, err = s.Files.WriteExecutable(filepath.Join(dir, "resolver-unmark.sh"), unmarkScriptBody())
	if err != nil {
		return false, err
	}
	changed = changed || ch
	ch, err = s.Files.WriteExecutable(filepath.Join(dir, "reload-singbox.sh"), reloadScriptBody())
	if err != nil {
		return false, err
	}
	return changed || ch, nil
}

func (s *Scripts) EnsurePingProbeScript() {
	path := filepath.Join(s.ConfigDir, "reload-singbox-ping.sh")
	st, err := os.Stat(path)
	if err == nil && st.Mode()&0o111 != 0 {
		return
	}
	_, _ = s.Files.WriteExecutable(path, pingProbeScriptBody())
}

func (s *Scripts) RefreshMarks(ctx context.Context, ifaces []string) {
	if len(ifaces) == 0 {
		return
	}
	var parts []string
	for _, iface := range ifaces {
		iface = strings.TrimSpace(iface)
		if iface == "" {
			continue
		}
		parts = append(parts,
			fmt.Sprintf("sh /config/resolver-unmark.sh %s 2>/dev/null || true", shellQuote(iface)),
			fmt.Sprintf("sh /config/resolver-mark.sh %s 0 2>/dev/null || true", shellQuote(iface)),
		)
	}
	if len(parts) == 0 {
		return
	}
	if _, err := s.Docker.Exec(ctx, s.Container, []string{"sh", "-c", strings.Join(parts, "; ")}, 60*time.Second); err != nil {
		log.Printf("resolver mark refresh: %v", err)
	}
}

func shellQuote(s string) string {
	return "'" + strings.ReplaceAll(s, "'", `'"'"'`) + "'"
}

func markScriptBody() string {
	return `#!/bin/sh
# Selective FakeIP/list delivery into sing-box (NOT TUN/auto_route)
IFACE="${1:?iface}"
REJECT_QUIC="${2:-0}"
REDIR_PORT=1602
UDP_PORT=1603
FAKEIP=198.18.0.0/15
TPROXY_MARK=0x1
TPROXY_TABLE=100
CIDR_FILE=/config/rulesets/proxy_cidrs_all.lst
NAT_CHAIN="RSNAT_${IFACE}"
MANGLE_CHAIN="RS_${IFACE}"

TPROXY_ON_IP=$(ip -4 -o addr show dev "$IFACE" 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -n1)
[ -n "$TPROXY_ON_IP" ] || TPROXY_ON_IP=0.0.0.0

sysctl -w "net.ipv4.conf.$IFACE.rp_filter=0" >/dev/null 2>&1 || true
sysctl -w net.ipv4.conf.all.rp_filter=0 >/dev/null 2>&1 || true
sysctl -w "net.ipv4.conf.$IFACE.route_localnet=1" >/dev/null 2>&1 || true

while ip rule show 2>/dev/null | grep -q "fwmark 0x2 lookup 101"; do
  ip rule del fwmark 0x2 table 101 2>/dev/null || break
done
ip route flush table 101 2>/dev/null || true
ip link delete sbox0 2>/dev/null || true

iptables -t nat -D PREROUTING -i "$IFACE" -j "$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -F "$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -X "$NAT_CHAIN" 2>/dev/null || true
iptables -D FORWARD -i "$IFACE" -d "$FAKEIP" -p udp -j REJECT --reject-with icmp-port-unreachable 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -j "$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -F "$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -X "$MANGLE_CHAIN" 2>/dev/null || true
while iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$UDP_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null; do :; done
while iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$UDP_PORT" --on-ip 0.0.0.0 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null; do :; done
while iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$UDP_PORT" --on-ip 127.0.0.1 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null; do :; done
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -j TPROXY --on-port "$REDIR_PORT" --on-ip 0.0.0.0 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$REDIR_PORT" --on-ip 0.0.0.0 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -j TPROXY --on-port "$REDIR_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$REDIR_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
while iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -m socket -j DIVERT 2>/dev/null; do :; done
while iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -m socket -j DIVERT 2>/dev/null; do :; done
iptables -t mangle -D PREROUTING -p udp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket --transparent -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket --transparent -j DIVERT 2>/dev/null || true
while iptables -t mangle -D FORWARD -o "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done
while iptables -t mangle -D FORWARD -i "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done

iptables -t nat -N "$NAT_CHAIN" 2>/dev/null || iptables -t nat -F "$NAT_CHAIN"

redir_add() {
  _cidr="$1"
  iptables -t nat -A "$NAT_CHAIN" -d "$_cidr" -p tcp -j REDIRECT --to-ports "$REDIR_PORT"
}

redir_add "$FAKEIP"
if [ -f "$CIDR_FILE" ]; then
  while IFS= read -r cidr || [ -n "$cidr" ]; do
    cidr=$(echo "$cidr" | tr -d '\r' | sed 's/#.*//;s/^[[:space:]]*//;s/[[:space:]]*$//')
    [ -z "$cidr" ] && continue
    redir_add "$cidr"
  done < "$CIDR_FILE"
fi

iptables -t nat -C PREROUTING -i "$IFACE" -j "$NAT_CHAIN" 2>/dev/null \
  || iptables -t nat -A PREROUTING -i "$IFACE" -j "$NAT_CHAIN"

iptables -t mangle -C FORWARD -o "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null \
  || iptables -t mangle -A FORWARD -o "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu
iptables -t mangle -C FORWARD -i "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null \
  || iptables -t mangle -A FORWARD -i "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu

iptables -t mangle -N DIVERT 2>/dev/null || true
iptables -t mangle -C DIVERT -j MARK --set-mark "$TPROXY_MARK" 2>/dev/null \
  || iptables -t mangle -A DIVERT -j MARK --set-mark "$TPROXY_MARK"
iptables -t mangle -C DIVERT -j ACCEPT 2>/dev/null \
  || iptables -t mangle -A DIVERT -j ACCEPT
iptables -t mangle -C PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -m socket -j DIVERT 2>/dev/null \
  || iptables -t mangle -I PREROUTING 1 -i "$IFACE" -d "$FAKEIP" -p udp -m socket -j DIVERT

while ip rule show 2>/dev/null | grep -q "fwmark $TPROXY_MARK lookup $TPROXY_TABLE"; do
  ip rule del fwmark "$TPROXY_MARK" table "$TPROXY_TABLE" 2>/dev/null || break
done
ip rule add fwmark "$TPROXY_MARK" lookup "$TPROXY_TABLE" 2>/dev/null || true
ip route replace local default dev lo table "$TPROXY_TABLE" 2>/dev/null || true
ip route replace local "$FAKEIP" dev lo table "$TPROXY_TABLE" 2>/dev/null || true

iptables -t mangle -C PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY \
  --on-port "$UDP_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null \
  || iptables -t mangle -A PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY \
  --on-port "$UDP_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK"

echo "[sing-box] redirect-tcp: ${IFACE} → :${REDIR_PORT}; udp-fakeip TPROXY :${UDP_PORT} on-ip=${TPROXY_ON_IP}"
`
}

func unmarkScriptBody() string {
	return `#!/bin/sh
IFACE="${1:?iface}"
NAT_CHAIN="RSNAT_${IFACE}"
MANGLE_CHAIN="RS_${IFACE}"
FAKEIP=198.18.0.0/15
TPROXY_PORT=1602
UDP_PORT=1603
TPROXY_MARK=0x1
TPROXY_ON_IP=$(ip -4 -o addr show dev "$IFACE" 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | head -n1)
[ -n "$TPROXY_ON_IP" ] || TPROXY_ON_IP=0.0.0.0

iptables -t nat -D PREROUTING -i "$IFACE" -j "$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -F "$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -X "$NAT_CHAIN" 2>/dev/null || true
iptables -D FORWARD -i "$IFACE" -d "$FAKEIP" -p udp -j REJECT --reject-with icmp-port-unreachable 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -j "$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -F "$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -X "$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$UDP_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$UDP_PORT" --on-ip 0.0.0.0 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$UDP_PORT" --on-ip 127.0.0.1 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -j TPROXY --on-port "$TPROXY_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$TPROXY_PORT" --on-ip "$TPROXY_ON_IP" --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -j TPROXY --on-port "$TPROXY_PORT" --on-ip 0.0.0.0 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$TPROXY_PORT" --on-ip 0.0.0.0 --tproxy-mark "$TPROXY_MARK/$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket --transparent -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket --transparent -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -j MARK --set-mark 0x2 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -j TPROXY --on-port "$TPROXY_PORT" --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p udp -j TPROXY --on-port "$TPROXY_PORT" --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true
iptables -t nat -D PREROUTING -i "$IFACE" -d "$FAKEIP" -p tcp -j REDIRECT --to-ports "$TPROXY_PORT" 2>/dev/null || true
while iptables -t mangle -D FORWARD -o "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done
while iptables -t mangle -D FORWARD -i "$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done
`
}

func reloadScriptBody() string {
	return `#!/usr/bin/env bash
set -euo pipefail
CONFIG=/config/sing-box.json
PIDFILE=/run/sing-box.pid
BIN=/usr/local/bin/sing-box
CACHE_FILE=/config/sing-box-cache.db
LOG_FILE=/config/sing-box.log
CACHE_MAX_BYTES=$((32 * 1024 * 1024))
LOG_MAX_BYTES=$((10 * 1024 * 1024))

prune_cache_if_huge() {
  if [[ ! -f "${CACHE_FILE}" ]]; then return 0; fi
  local size
  size="$(wc -c < "${CACHE_FILE}" | tr -d '[:space:]')"
  if [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -gt "${CACHE_MAX_BYTES}" ]; then
    echo "[sing-box] pruning oversized cache ${CACHE_FILE} (${size} bytes > ${CACHE_MAX_BYTES})"
    rm -f "${CACHE_FILE}"
  fi
}

rotate_log_if_huge() {
  local file="${1:-}"
  [[ -n "${file}" && -f "${file}" ]] || return 0
  local size
  size="$(wc -c < "${file}" | tr -d '[:space:]')"
  if [[ "${size}" =~ ^[0-9]+$ ]] && [ "${size}" -gt "${LOG_MAX_BYTES}" ]; then
    echo "[sing-box] rotating oversized log ${file} (${size} bytes > ${LOG_MAX_BYTES})"
    rm -f "${file}.1"
    mv -f "${file}" "${file}.1"
  fi
}

cleanup_legacy_tun() {
  local TUN_IFACE=sbox0 TUN_MARK=0x2 TUN_TABLE=101
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
  rotate_log_if_huge "${LOG_FILE}"
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
`
}

func pingProbeScriptBody() string {
	return `#!/usr/bin/env bash
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
  setsid "${BIN}" run -c "${CONFIG}" >>/config/sing-box-ping.log 2>&1 </dev/null &
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
  tail -n 40 /config/sing-box-ping.log >&2 || true
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
      kill -HUP "${pid}" 2>/dev/null || { stop_ping; start_ping; return $?; }
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
  *) echo "usage: $0 {start|stop|reload}" >&2; exit 1 ;;
esac
`
}
