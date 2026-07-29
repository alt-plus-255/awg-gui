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
        $fakeip = ResolverService::FAKEIP_CIDR;
        $tproxyMark = ResolverService::TPROXY_MARK;
        $tproxyTable = ResolverService::TPROXY_TABLE;
        $tproxyOnIp = ResolverService::TPROXY_ON_IP;
        $tunMark = '0x2';
        $tunTable = ResolverService::TUN_TABLE;
        $tunIface = ResolverService::TUN_IFACE;

        $markBody = <<<SH
#!/bin/sh
# Selective TPROXY only (NOT TUN/auto_route):
# - AWG iface owns the client full-tunnel.
# - Only FakeIP ({$fakeip}) + list CIDRs jump into sing-box :{$tproxyPort}.
# - Everything else stays on \${1} and exits via POSTROUTING MASQUERADE (direct / VDS IP).
# - fwmark/table {$tproxyTable} is ONLY for locally delivering marked TPROXY packets to lo.
IFACE="\${1:?iface}"
TPROXY_PORT={$tproxyPort}
TPROXY_MARK={$tproxyMark}
TPROXY_TABLE={$tproxyTable}
FAKEIP={$fakeip}
CIDR_FILE=/config/rulesets/proxy_cidrs_all.lst
CHAIN="RS_\${IFACE}"
DIVERT=DIVERT

# Policy routing for TPROXY (idempotent).
while ip rule show 2>/dev/null | grep -q "fwmark \${TPROXY_MARK} lookup \${TPROXY_TABLE}"; do
  ip rule del fwmark "\${TPROXY_MARK}" table "\${TPROXY_TABLE}" 2>/dev/null || break
done
ip rule add fwmark "\${TPROXY_MARK}" table "\${TPROXY_TABLE}" 2>/dev/null || true
ip route replace local 0.0.0.0/0 dev lo table "\${TPROXY_TABLE}" 2>/dev/null \\
  || ip route add local 0.0.0.0/0 dev lo table "\${TPROXY_TABLE}" 2>/dev/null || true

# Required for TPROXY to 127.0.0.1 inside the container netns.
sysctl -w net.ipv4.conf.all.route_localnet=1 >/dev/null 2>&1 || true
sysctl -w net.ipv4.conf.lo.route_localnet=1 >/dev/null 2>&1 || true

# Drop legacy TUN mark/table if still present.
while ip rule show 2>/dev/null | grep -q "fwmark {$tunMark} lookup {$tunTable}"; do
  ip rule del fwmark {$tunMark} table {$tunTable} 2>/dev/null || break
done
ip route flush table {$tunTable} 2>/dev/null || true
ip link delete {$tunIface} 2>/dev/null || true

# DIVERT only for transparent/TPROXY sockets (not normal :53/:9090 listeners).
# Always re-insert at the top of PREROUTING so established TPROXY packets skip RS_*.
iptables -t mangle -N "\$DIVERT" 2>/dev/null || true
iptables -t mangle -F "\$DIVERT" 2>/dev/null || true
iptables -t mangle -A "\$DIVERT" -j MARK --set-mark "\$TPROXY_MARK"
iptables -t mangle -A "\$DIVERT" -j ACCEPT
iptables -t mangle -D PREROUTING -p tcp -m socket -j "\$DIVERT" 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket -j "\$DIVERT" 2>/dev/null || true
iptables -t mangle -D PREROUTING -p tcp -m socket --transparent -j "\$DIVERT" 2>/dev/null || true
iptables -t mangle -D PREROUTING -p udp -m socket --transparent -j "\$DIVERT" 2>/dev/null || true
iptables -t mangle -I PREROUTING 1 -p tcp -m socket --transparent -j "\$DIVERT"
iptables -t mangle -I PREROUTING 2 -p udp -m socket --transparent -j "\$DIVERT"

iptables -t mangle -N "\$CHAIN" 2>/dev/null || iptables -t mangle -F "\$CHAIN"

tproxy_add() {
  _cidr="\$1"
  iptables -t mangle -A "\$CHAIN" -d "\$_cidr" -p tcp -j TPROXY \\
    --on-port "\$TPROXY_PORT" --on-ip {$tproxyOnIp} --tproxy-mark "\${TPROXY_MARK}/\${TPROXY_MARK}"
  iptables -t mangle -A "\$CHAIN" -d "\$_cidr" -p udp -j TPROXY \\
    --on-port "\$TPROXY_PORT" --on-ip {$tproxyOnIp} --tproxy-mark "\${TPROXY_MARK}/\${TPROXY_MARK}"
}

tproxy_add "\$FAKEIP"

if [ -f "\$CIDR_FILE" ]; then
  while IFS= read -r cidr || [ -n "\$cidr" ]; do
    cidr=\$(echo "\$cidr" | tr -d '\\r' | sed 's/#.*//;s/^[[:space:]]*//;s/[[:space:]]*\$//')
    [ -z "\$cidr" ] && continue
    tproxy_add "\$cidr"
  done < "\$CIDR_FILE"
fi

iptables -t mangle -C PREROUTING -i "\$IFACE" -j "\$CHAIN" 2>/dev/null \\
  || iptables -t mangle -A PREROUTING -i "\$IFACE" -j "\$CHAIN"

echo "[sing-box] tproxy: \${IFACE} → {$tproxyOnIp}:\${TPROXY_PORT} (\${FAKEIP} + list CIDRs)"
SH;
        $changed = $this->files->writeExecutable($mark, $markBody) || $changed;

        $unmarkBody = <<<SH
#!/bin/sh
IFACE="\${1:?iface}"
CHAIN="RS_\${IFACE}"
TPROXY_MARK={$tproxyMark}
FAKEIP={$fakeip}
TPROXY_PORT={$tproxyPort}

iptables -t mangle -D PREROUTING -i "\$IFACE" -j "\$CHAIN" 2>/dev/null || true
iptables -t mangle -F "\$CHAIN" 2>/dev/null || true
iptables -t mangle -X "\$CHAIN" 2>/dev/null || true

# Legacy FakeIP MARK→TUN rules (pre-TPROXY restore)
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -j MARK --set-mark 0x2 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p tcp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip {$tproxyOnIp} --tproxy-mark 0x1/0x1 2>/dev/null || true
iptables -t mangle -D PREROUTING -i "\$IFACE" -d "\$FAKEIP" -p udp -j TPROXY --on-port "\$TPROXY_PORT" --on-ip {$tproxyOnIp} --tproxy-mark 0x1/0x1 2>/dev/null || true
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
SH;

        return $sources;
    }

    /**
     * Re-apply TPROXY chains on live AWG ifaces after proxy_cidrs_all.lst changes.
     * Single docker exec for all ifaces (avoids O(N) round-trips).
     *
     * @param  list<string>  $ifaces
     */
    public function refreshResolverMarksOnIfaces(array $ifaces): void
    {
        $clean = [];
        foreach ($ifaces as $iface) {
            $iface = trim((string) $iface);
            if ($iface !== '') {
                $clean[] = $iface;
            }
        }
        if ($clean === []) {
            return;
        }

        $container = $this->awg->containerName();
        $quoted = implode(' ', array_map('escapeshellarg', $clean));
        try {
            $this->docker->exec(
                $container,
                ['sh', '-c',
                    'for iface in '.$quoted.'; do '
                    .'sh /config/resolver-unmark.sh "$iface" 2>/dev/null || true; '
                    .'sh /config/resolver-mark.sh "$iface" 2>/dev/null || true; '
                    .'done',
                ],
                timeout: 60,
            );
        } catch (\Throwable $e) {
            Log::warning('resolver mark refresh: '.$e->getMessage());
        }
    }

}
