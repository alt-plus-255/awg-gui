<?php

namespace App\Services\Resolver;

use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Log;

class ResolverMarkScripts
{
    public function __construct(
        private AmneziaWgService $awg,
        private DockerRuntime $docker,
        private ResolverFileHelper $files,
    ) {}

    public function ensureResolverMarkScripts(): bool
    {
        $dir = $this->awg->configDir();
        $mark = $dir.'/resolver-mark.sh';
        $unmark = $dir.'/resolver-unmark.sh';
        $reload = $dir.'/reload-singbox.sh';
        $changed = false;

        // Remove obsolete TUN helper if present from older installs.
        $legacyTunRoutes = $dir.'/resolver-tun-routes.sh';
        if (is_file($legacyTunRoutes)) {
            @unlink($legacyTunRoutes);
        }

        $tproxyPort = ResolverService::TPROXY_PORT;
        $udpTproxyPort = ResolverService::UDP_TPROXY_PORT;
        $fakeip = ResolverService::FAKEIP_CIDR;
        $tproxyMark = ResolverService::TPROXY_MARK;
        $tproxyTable = ResolverService::TPROXY_TABLE;
        $tproxyOnIp = ResolverService::TPROXY_ON_IP;
        $tunMark = '0x2';
        $tunTable = ResolverService::TUN_TABLE;
        $tunIface = ResolverService::TUN_IFACE;

        $markBody = <<<SH
#!/bin/sh
# Selective FakeIP/list delivery into sing-box (NOT TUN/auto_route):
# - AWG iface owns the client full-tunnel.
# - TCP FakeIP + list CIDRs → NAT REDIRECT → sing-box :{$tproxyPort} (Docker-safe).
# - UDP FakeIP → always TPROXY :{$udpTproxyPort} (Block QUIC = sing-box protocol reject).
# - Do NOT use --on-ip 127.0.0.1 (route_localnet). Prefer awg iface IPv4.
# - DIVERT is FakeIP-scoped UDP only (global DIVERT blackholes container DNS).
# - Arg2 (legacy reject_quic) accepted for PostUp compatibility but ignored for iptables.
# - Everything else stays on \${1} and exits via POSTROUTING MASQUERADE (direct / VDS IP).
IFACE="\${1:?iface}"
REJECT_QUIC="\${2:-0}"
REDIR_PORT={$tproxyPort}
UDP_PORT={$udpTproxyPort}
FAKEIP={$fakeip}
TPROXY_MARK={$tproxyMark}
TPROXY_TABLE={$tproxyTable}
CIDR_FILE=/config/rulesets/proxy_cidrs_all.lst
NAT_CHAIN="RSNAT_\${IFACE}"
MANGLE_CHAIN="RS_\${IFACE}"

TPROXY_ON_IP=\$(ip -4 -o addr show dev "\$IFACE" 2>/dev/null | awk '{print \$4}' | cut -d/ -f1 | head -n1)
[ -n "\$TPROXY_ON_IP" ] || TPROXY_ON_IP={$tproxyOnIp}

sysctl -w "net.ipv4.conf.\$IFACE.rp_filter=0" >/dev/null 2>&1 || true
sysctl -w net.ipv4.conf.all.rp_filter=0 >/dev/null 2>&1 || true
sysctl -w "net.ipv4.conf.\$IFACE.route_localnet=1" >/dev/null 2>&1 || true

# Drop legacy TUN mark/table if still present.
while ip rule show 2>/dev/null | grep -q "fwmark {$tunMark} lookup {$tunTable}"; do
  ip rule del fwmark {$tunMark} table {$tunTable} 2>/dev/null || break
done
ip route flush table {$tunTable} 2>/dev/null || true
ip link delete {$tunIface} 2>/dev/null || true

# Clear previous NAT / mangle / UDP REJECT / unified-TPROXY leftovers (idempotent; also drop duplicates).
iptables -t nat -D PREROUTING -i "\$IFACE" -j "\$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -F "\$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -X "\$NAT_CHAIN" 2>/dev/null || true
iptables -D FORWARD -i "\$IFACE" -d "\$FAKEIP" -p udp -j REJECT --reject-with icmp-port-unreachable 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -j "\$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -F "\$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -X "\$MANGLE_CHAIN" 2>/dev/null || true
# Remove all FakeIP UDP TPROXY/DIVERT lines for this iface (may have been duplicated by older PostUp).
while iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$UDP_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null; do :; done
while iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$UDP_PORT" --on-ip 0.0.0.0 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null; do :; done
while iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$UDP_PORT" --on-ip 127.0.0.1 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null; do :; done
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j TPROXY --on-port "\$REDIR_PORT" --on-ip 0.0.0.0 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$REDIR_PORT" --on-ip 0.0.0.0 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j TPROXY --on-port "\$REDIR_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$REDIR_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
while iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -m socket -j DIVERT 2>/dev/null; do :; done
while iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -m socket -j DIVERT 2>/dev/null; do :; done
iptables -t mangle -D PREROUTING -p udp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket --transparent -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket --transparent -j DIVERT 2>/dev/null || true
# Drop previous TCPMSS clamps for this iface (re-add below).
while iptables -t mangle -D FORWARD -o "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done
while iptables -t mangle -D FORWARD -i "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done

# TCP FakeIP/list → NAT REDIRECT (works in Docker; TCP TPROXY does not).
iptables -t nat -N "\$NAT_CHAIN" 2>/dev/null || iptables -t nat -F "\$NAT_CHAIN"

redir_add() {
  _cidr="\$1"
  iptables -t nat -A "\$NAT_CHAIN" -d "\$_cidr" -p tcp -j REDIRECT --to-ports "\$REDIR_PORT"
}

redir_add "\$FAKEIP"
if [ -f "\$CIDR_FILE" ]; then
  while IFS= read -r cidr || [ -n "\$cidr" ]; do
    cidr=\$(echo "\$cidr" | tr -d '\\r' | sed 's/#.*//;s/^[[:space:]]*//;s/[[:space:]]*\$//')
    [ -z "\$cidr" ] && continue
    redir_add "\$cidr"
  done < "\$CIDR_FILE"
fi

iptables -t nat -C PREROUTING -i "\$IFACE" -j "\$NAT_CHAIN" 2>/dev/null \\
  || iptables -t nat -A PREROUTING -i "\$IFACE" -j "\$NAT_CHAIN"

# MSS clamp for nested AWG+proxy (MTU 1420) — iface-scoped, idempotent.
iptables -t mangle -C FORWARD -o "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null \\
  || iptables -t mangle -A FORWARD -o "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu
iptables -t mangle -C FORWARD -i "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null \\
  || iptables -t mangle -A FORWARD -i "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu

# UDP FakeIP → TPROXY :1603 (always; Block QUIC is sing-box-only).
iptables -t mangle -N DIVERT 2>/dev/null || true
iptables -t mangle -C DIVERT -j MARK --set-mark "\$TPROXY_MARK" 2>/dev/null \\
  || iptables -t mangle -A DIVERT -j MARK --set-mark "\$TPROXY_MARK"
iptables -t mangle -C DIVERT -j ACCEPT 2>/dev/null \\
  || iptables -t mangle -A DIVERT -j ACCEPT
iptables -t mangle -C PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -m socket -j DIVERT 2>/dev/null \\
  || iptables -t mangle -I PREROUTING 1 -i "\$IFACE" -d "\$FAKEIP" -p udp -m socket -j DIVERT

while ip rule show 2>/dev/null | grep -q "fwmark \$TPROXY_MARK lookup \$TPROXY_TABLE"; do
  ip rule del fwmark "\$TPROXY_MARK" table "\$TPROXY_TABLE" 2>/dev/null || break
done
ip rule add fwmark "\$TPROXY_MARK" lookup "\$TPROXY_TABLE" 2>/dev/null || true
ip route replace local default dev lo table "\$TPROXY_TABLE" 2>/dev/null || true
ip route replace local "\$FAKEIP" dev lo table "\$TPROXY_TABLE" 2>/dev/null || true

iptables -t mangle -C PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY \\
  --on-port "\$UDP_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null \\
  || iptables -t mangle -A PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY \\
  --on-port "\$UDP_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK"

echo "[sing-box] redirect-tcp: \${IFACE} → :\${REDIR_PORT}; udp-fakeip TPROXY :\${UDP_PORT} on-ip=\${TPROXY_ON_IP}"
SH;
        $changed = $this->files->writeExecutable($mark, $markBody) || $changed;

        $unmarkBody = <<<SH
#!/bin/sh
IFACE="\${1:?iface}"
NAT_CHAIN="RSNAT_\${IFACE}"
MANGLE_CHAIN="RS_\${IFACE}"
FAKEIP={$fakeip}
TPROXY_PORT={$tproxyPort}
UDP_PORT={$udpTproxyPort}
TPROXY_MARK={$tproxyMark}
TPROXY_ON_IP=\$(ip -4 -o addr show dev "\$IFACE" 2>/dev/null | awk '{print \$4}' | cut -d/ -f1 | head -n1)
[ -n "\$TPROXY_ON_IP" ] || TPROXY_ON_IP={$tproxyOnIp}

iptables -t nat -D PREROUTING -i "\$IFACE" -j "\$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -F "\$NAT_CHAIN" 2>/dev/null || true
iptables -t nat -X "\$NAT_CHAIN" 2>/dev/null || true

iptables -D FORWARD -i "\$IFACE" -d "\$FAKEIP" -p udp -j REJECT --reject-with icmp-port-unreachable 2>/dev/null || true

iptables -t mangle -D PREROUTING -i "\$IFACE" -j "\$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -F "\$MANGLE_CHAIN" 2>/dev/null || true
iptables -t mangle -X "\$MANGLE_CHAIN" 2>/dev/null || true

iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$UDP_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$UDP_PORT" --on-ip 0.0.0.0 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$UDP_PORT" --on-ip 127.0.0.1 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip "\$TPROXY_ON_IP" --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip 0.0.0.0 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip 0.0.0.0 --tproxy-mark "\$TPROXY_MARK/\$TPROXY_MARK" 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket --transparent -j DIVERT 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket --transparent -j DIVERT 2>/dev/null || true

iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -j MARK --set-mark 0x2 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true
iptables -t nat -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j REDIRECT --to-ports "\$TPROXY_PORT" 2>/dev/null || true
while iptables -t mangle -D FORWARD -o "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done
while iptables -t mangle -D FORWARD -i "\$IFACE" -p tcp -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null; do :; done
SH;
        $changed = $this->files->writeExecutable($unmark, $unmarkBody) || $changed;

        // Prefer volume copy so list-CIDR TPROXY works without rebuilding the AWG image.
        $reloadBody = <<<'SH'
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
  # Prefer POSIX -gt: `(( size > … ))` breaks when script is invoked as `sh reload-singbox.sh` (ash/dash).
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
SH;
        $changed = $this->files->writeExecutable($reload, $reloadBody) || $changed;

        return $changed;
    }

    public function ensurePingProbeScript(): void
    {
        $path = $this->awg->configDir().'/reload-singbox-ping.sh';
        if (is_executable($path)) {
            return;
        }

        foreach ($this->pingProbeScriptSources() as $body) {
            if ($body !== '') {
                $this->files->writeExecutable($path, $body);

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function pingProbeScriptSources(): array
    {
        $sources = [];

        $imageScript = '/usr/local/bin/reload-singbox-ping.sh';
        if (is_readable($imageScript)) {
            $sources[] = (string) file_get_contents($imageScript);
        }

        $repoScript = base_path('../awg/reload-singbox-ping.sh');
        if (is_readable($repoScript)) {
            $sources[] = (string) file_get_contents($repoScript);
        }

        try {
            $container = $this->awg->containerName();
            $result = $this->docker->exec(
                $container,
                ['cat', '/usr/local/bin/reload-singbox-ping.sh'],
                timeout: 5,
            );
            if ($result->successful()) {
                $sources[] = trim($result->output());
            }
        } catch (\Throwable) {
            // fall through to embedded script
        }

        $sources[] = <<<'SH'
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

  # setsid: survive parent exit (docker exec from panel kills the session otherwise).
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
SH;

        return $sources;
    }

    /**
     * Re-apply REDIRECT/UDP chains on live AWG ifaces after proxy_cidrs changes.
     * Single docker exec for all ifaces (avoids O(N) round-trips).
     * UDP FakeIP TPROXY is always installed; Block QUIC is sing-box-only.
     *
     * @param  array<string, bool>  $ifaceRejectQuic  iface => resolver_reject_quic (flag ignored for iptables)
     */
    public function refreshResolverMarksOnIfaces(array $ifaceRejectQuic): void
    {
        $parts = [];
        foreach ($ifaceRejectQuic as $iface => $_rejectQuic) {
            $iface = trim((string) $iface);
            if ($iface === '') {
                continue;
            }
            $parts[] = 'sh /config/resolver-unmark.sh '.escapeshellarg($iface).' 2>/dev/null || true';
            // Arg2 kept for PostUp compatibility; mark script ignores it for iptables.
            $parts[] = 'sh /config/resolver-mark.sh '.escapeshellarg($iface).' 0 2>/dev/null || true';
        }
        if ($parts === []) {
            return;
        }

        $container = $this->awg->containerName();
        try {
            $this->docker->exec(
                $container,
                ['sh', '-c', implode('; ', $parts)],
                timeout: 60,
            );
        } catch (\Throwable $e) {
            Log::warning('resolver mark refresh: '.$e->getMessage());
        }
    }

}
