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

    public function ensureResolverMarkScripts(): void
    {
        $dir = $this->awg->configDir();
        $mark = $dir.'/resolver-mark.sh';
        $unmark = $dir.'/resolver-unmark.sh';
        $routes = $dir.'/resolver-tun-routes.sh';
        $reload = $dir.'/reload-singbox.sh';

        $routesBody = <<<'SH'
#!/bin/sh
# Install policy routes for FakeIP + proxy_cidrs_all.lst → sing-box TUN.
TUN_IFACE=sbox0
TUN_MARK=0x2
TUN_TABLE=101
FAKEIP=198.18.0.0/15
CIDR_FILE=/config/rulesets/proxy_cidrs_all.lst

while ip rule show 2>/dev/null | grep -q "fwmark ${TUN_MARK} lookup ${TUN_TABLE}"; do
  ip rule del fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || break
done
ip rule add fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || true

if ! ip link show "${TUN_IFACE}" >/dev/null 2>&1; then
  echo "[sing-box] warn: ${TUN_IFACE} not present yet" >&2
  exit 0
fi

ip link set "${TUN_IFACE}" up 2>/dev/null || true
ip route replace "${FAKEIP}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null \
  || ip route add "${FAKEIP}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null || true
ip route replace "${FAKEIP}" dev "${TUN_IFACE}" 2>/dev/null \
  || ip route add "${FAKEIP}" dev "${TUN_IFACE}" 2>/dev/null || true

if [ -f "${CIDR_FILE}" ]; then
  while IFS= read -r cidr || [ -n "$cidr" ]; do
    cidr=$(echo "$cidr" | tr -d '\r' | sed 's/#.*//;s/^[[:space:]]*//;s/[[:space:]]*$//')
    [ -z "$cidr" ] && continue
    ip route replace "${cidr}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null \
      || ip route add "${cidr}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null || true
  done < "${CIDR_FILE}"
fi

echo "[sing-box] tun routing: mark ${TUN_MARK} → ${TUN_IFACE} (${FAKEIP} + list CIDRs)"
SH;
        $this->files->writeExecutable($routes, $routesBody);

        $markBody = <<<'SH'
#!/bin/sh
# Mark FakeIP + list CIDRs toward sing-box TUN. Full-tunnel non-list traffic uses MASQUERADE.
IFACE="${1:?iface}"
MARK=0x2
FAKEIP=198.18.0.0/15
CIDR_FILE=/config/rulesets/proxy_cidrs_all.lst
CHAIN="RS_${IFACE}"

iptables -t mangle -N "$CHAIN" 2>/dev/null || iptables -t mangle -F "$CHAIN"
iptables -t mangle -A "$CHAIN" -d "$FAKEIP" -j MARK --set-mark "$MARK"

if [ -f "$CIDR_FILE" ]; then
  while IFS= read -r cidr || [ -n "$cidr" ]; do
    cidr=$(echo "$cidr" | tr -d '\r' | sed 's/#.*//;s/^[[:space:]]*//;s/[[:space:]]*$//')
    [ -z "$cidr" ] && continue
    iptables -t mangle -A "$CHAIN" -d "$cidr" -j MARK --set-mark "$MARK"
  done < "$CIDR_FILE"
fi

iptables -t mangle -C PREROUTING -i "$IFACE" -j "$CHAIN" 2>/dev/null \
  || iptables -t mangle -A PREROUTING -i "$IFACE" -j "$CHAIN"

# Ensure TUN routes exist even if reload-singbox from the image is outdated.
[ -x /config/resolver-tun-routes.sh ] && /config/resolver-tun-routes.sh >/dev/null 2>&1 || true
SH;
        $this->files->writeExecutable($mark, $markBody);

        $unmarkBody = <<<'SH'
#!/bin/sh
IFACE="${1:?iface}"
CHAIN="RS_${IFACE}"

iptables -t mangle -D PREROUTING -i "$IFACE" -j "$CHAIN" 2>/dev/null || true
iptables -t mangle -F "$CHAIN" 2>/dev/null || true
iptables -t mangle -X "$CHAIN" 2>/dev/null || true

# Legacy FakeIP-only rules (pre-chain)
MARK=0x2
FAKEIP=198.18.0.0/15
iptables -t mangle -D PREROUTING -i "$IFACE" -d "$FAKEIP" -j MARK --set-mark "$MARK" 2>/dev/null || true
SH;
        $this->files->writeExecutable($unmark, $unmarkBody);

        // Prefer volume copy so list-CIDR routes work without rebuilding the AWG image.
        $reloadBody = <<<'SH'
#!/usr/bin/env bash
# Reload or start/stop sing-box based on /config/sing-box.json
set -euo pipefail

CONFIG=/config/sing-box.json
PIDFILE=/run/sing-box.pid
BIN=/usr/local/bin/sing-box

ensure_tun_routing() {
  if [[ -x /config/resolver-tun-routes.sh ]]; then
    /config/resolver-tun-routes.sh || true
    return 0
  fi
  TUN_IFACE=sbox0
  TUN_MARK=0x2
  TUN_TABLE=101
  FAKEIP=198.18.0.0/15
  while ip rule show | grep -q "fwmark ${TUN_MARK} lookup ${TUN_TABLE}"; do
    ip rule del fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || break
  done
  ip rule add fwmark "${TUN_MARK}" table "${TUN_TABLE}" 2>/dev/null || true
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if ip link show "${TUN_IFACE}" >/dev/null 2>&1; then
      break
    fi
    sleep 0.2
  done
  if ip link show "${TUN_IFACE}" >/dev/null 2>&1; then
    ip link set "${TUN_IFACE}" up 2>/dev/null || true
    ip route replace "${FAKEIP}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null \
      || ip route add "${FAKEIP}" dev "${TUN_IFACE}" table "${TUN_TABLE}" 2>/dev/null || true
    ip route replace "${FAKEIP}" dev "${TUN_IFACE}" 2>/dev/null \
      || ip route add "${FAKEIP}" dev "${TUN_IFACE}" 2>/dev/null || true
    echo "[sing-box] tun routing: mark ${TUN_MARK} → ${TUN_IFACE} (${FAKEIP})"
  else
    echo "[sing-box] warn: ${TUN_IFACE} not present yet" >&2
  fi
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
    return 0
  fi

  if ! "${BIN}" check -c "${CONFIG}"; then
    echo "[sing-box] config check failed" >&2
    return 1
  fi

  stop_singbox
  "${BIN}" run -c "${CONFIG}" &
  echo $! > "${PIDFILE}"
  echo "[sing-box] started pid=$(cat "${PIDFILE}")"
  # Wait briefly for TUN before installing routes (incl. list CIDRs).
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    if ip link show sbox0 >/dev/null 2>&1; then
      break
    fi
    sleep 0.2
  done
  ensure_tun_routing
}

start_singbox
SH;
        $this->files->writeExecutable($reload, $reloadBody);
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
     * Re-apply MARK chains on live AWG ifaces after proxy_cidrs_all.lst changes.
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
