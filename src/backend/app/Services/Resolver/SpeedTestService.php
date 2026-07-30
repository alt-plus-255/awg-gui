<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SpeedTestService
{
    public const LOCK_KEY = 'resolver:speed_test_lock';

    public const LOCK_SEC = 90;

    public const PIDFILE = '/run/sing-box-speed.pid';

    public const LOG_FILE = '/config/sing-box-speed.log';

    public const MIXED_TAG = 'speedtest-in';

    public function __construct(
        private AmneziaWgService $awg,
        private DockerRuntime $docker,
        private ResolverPaths $paths,
        private ConnectionOutboundBuilder $outboundBuilder,
        private SingBoxOutboundParser $parser,
    ) {}

    /**
     * @return array{
     *   ok: bool,
     *   outbound_tag: string,
     *   connection_id: int,
     *   node_key: ?string,
     *   ping_ms: ?int,
     *   download_mbps: ?float,
     *   upload_mbps: ?float,
     *   download_bytes: ?int,
     *   upload_bytes: ?int,
     *   download_ms: ?int,
     *   upload_ms: ?int,
     *   error: ?string
     * }
     */
    public function run(ResolverConnection $conn, ?string $nodeKey = null): array
    {
        if (! $conn->enabled) {
            throw new RuntimeException(__('resolver.speed_test_connection_disabled'));
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SEC);
        if (! $lock->get()) {
            throw new RuntimeException(__('resolver.speed_test_busy'));
        }

        try {
            [$targetTag, $outbounds] = $this->buildOutbounds($conn, $nodeKey);
            $config = $this->buildConfig($outbounds, $targetTag);
            $this->writeConfig($config);
            $this->startProbe();
            $this->waitForSpeedApi();

            $ping = $this->measurePing($targetTag);
            $down = $this->measureDownload();
            $up = $this->measureUpload();

            $ok = ($down['mbps'] !== null && $down['mbps'] > 0)
                || ($up['mbps'] !== null && $up['mbps'] > 0)
                || ($ping['ms'] !== null && $ping['ms'] > 0);

            $errors = array_values(array_filter([
                $ping['error'] ?? null,
                $down['error'] ?? null,
                $up['error'] ?? null,
            ]));

            return [
                'ok' => $ok,
                'outbound_tag' => $targetTag,
                'connection_id' => (int) $conn->id,
                'node_key' => $nodeKey,
                'ping_ms' => $ping['ms'],
                'download_mbps' => $down['mbps'],
                'upload_mbps' => $up['mbps'],
                'download_bytes' => $down['bytes'],
                'upload_bytes' => $up['bytes'],
                'download_ms' => $down['ms'],
                'upload_ms' => $up['ms'],
                'error' => $errors === [] ? null : implode('; ', $errors),
            ];
        } finally {
            try {
                $this->stopProbe();
            } catch (\Throwable) {
                // ignore stop failures
            }
            $lock->release();
        }
    }

    /**
     * @param  list<ResolverConnection>  $connections
     * @return list<array<string, mixed>>
     */
    public function runBatch(iterable $connections): array
    {
        $out = [];
        foreach ($connections as $conn) {
            if (! $conn instanceof ResolverConnection || ! $conn->enabled) {
                continue;
            }
            try {
                $out[] = $this->run($conn, null);
            } catch (\Throwable $e) {
                $out[] = [
                    'ok' => false,
                    'outbound_tag' => $conn->outboundTag(),
                    'connection_id' => (int) $conn->id,
                    'node_key' => null,
                    'ping_ms' => null,
                    'download_mbps' => null,
                    'upload_mbps' => null,
                    'download_bytes' => null,
                    'upload_bytes' => null,
                    'download_ms' => null,
                    'upload_ms' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    public function buildOutbounds(ResolverConnection $conn, ?string $nodeKey): array
    {
        $outbounds = [
            ['type' => 'direct', 'tag' => 'direct'],
        ];

        if ($nodeKey !== null && $nodeKey !== '') {
            $tag = $this->outboundBuilder->resolveNodeTag($conn, $nodeKey);
            if ($tag === null) {
                throw new RuntimeException(__('resolver.speed_test_node_not_found'));
            }
            $ob = $this->outboundForNodeKey($conn, $nodeKey, $tag);
            $outbounds[] = $ob;

            return [$tag, $outbounds];
        }

        $built = $this->outboundBuilder->buildForConnections([$conn]);
        $tag = $conn->outboundTag();
        if (! isset($built['tags_added'][$tag])) {
            throw new RuntimeException(__('resolver.speed_test_no_outbound'));
        }

        return [$tag, $built['outbounds']];
    }

    /**
     * @param  list<array<string, mixed>>  $outbounds
     * @return array<string, mixed>
     */
    public function buildConfig(array $outbounds, string $finalTag): array
    {
        return [
            'log' => [
                'level' => 'warn',
                'timestamp' => true,
            ],
            'dns' => [
                'servers' => [
                    [
                        'type' => 'udp',
                        'tag' => 'bootstrap',
                        'server' => '8.8.8.8',
                        'server_port' => 53,
                    ],
                ],
                'final' => 'bootstrap',
                'strategy' => 'ipv4_only',
            ],
            'inbounds' => [
                [
                    'type' => 'mixed',
                    'tag' => self::MIXED_TAG,
                    'listen' => ResolverService::SPEED_MIXED_LISTEN,
                    'listen_port' => ResolverService::SPEED_MIXED_PORT,
                ],
            ],
            'outbounds' => $outbounds,
            'route' => [
                'rules' => [
                    [
                        'inbound' => [self::MIXED_TAG],
                        'action' => 'route',
                        'outbound' => $finalTag,
                    ],
                ],
                'final' => $finalTag,
                'auto_detect_interface' => false,
                'default_interface' => app(EgressInterfaceResolver::class)->resolve(),
                'default_domain_resolver' => 'bootstrap',
            ],
            'experimental' => [
                'clash_api' => [
                    'external_controller' => ResolverService::CLASH_SPEED_API_ADDR,
                    'default_mode' => 'rule',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outboundForNodeKey(ResolverConnection $conn, string $nodeKey, string $tag): array
    {
        if ($conn->isUrltestMode()) {
            foreach (is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [] as $node) {
                if (! is_array($node) || (string) ($node['key'] ?? '') !== $nodeKey) {
                    continue;
                }
                $ob = $node['outbound'] ?? [];
                if (! is_array($ob) || empty($ob['type'])) {
                    break;
                }
                $ob = $this->parser->normalize($ob);
                unset($ob['tag']);
                $ob['tag'] = $tag;

                return $ob;
            }
            throw new RuntimeException(__('resolver.speed_test_node_not_found'));
        }

        $ob = $conn->outbound ?? [];
        if (! is_array($ob) || empty($ob['type'])) {
            throw new RuntimeException(__('resolver.speed_test_no_outbound'));
        }
        $ob = $this->parser->normalize($ob);
        $ob['tag'] = $tag;

        return $ob;
    }

    /** @param  array<string, mixed>  $config */
    private function writeConfig(array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException(__('resolver.speed_test_serialize_failed'));
        }
        $path = $this->paths->singBoxSpeedConfigPath();
        if (@file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException(__('resolver.speed_test_write_failed'));
        }
    }

    private function startProbe(): void
    {
        $this->stopProbe();
        $script = <<<'SH'
set -e
CONFIG=/config/sing-box-speed.json
PIDFILE=/run/sing-box-speed.pid
BIN=/usr/local/bin/sing-box
LOG=/config/sing-box-speed.log
"$BIN" check -c "$CONFIG"
: >>"$LOG"
setsid "$BIN" run -c "$CONFIG" >>"$LOG" 2>&1 </dev/null &
echo $! > "$PIDFILE"
pid=$(cat "$PIDFILE")
for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if kill -0 "$pid" 2>/dev/null; then
    exit 0
  fi
  sleep 0.2
done
echo "speed probe failed to stay up" >&2
tail -n 40 "$LOG" >&2 || true
exit 1
SH;
        $r = $this->docker->exec(
            $this->awg->containerName(),
            ['bash', '-lc', $script],
            timeout: 30,
        );
        if (! $r->successful()) {
            $err = trim($r->errorOutput() !== '' ? $r->errorOutput() : $r->output());
            throw new RuntimeException($err !== '' ? $err : __('resolver.speed_test_start_failed'));
        }
    }

    private function stopProbe(): void
    {
        $script = <<<'SH'
PIDFILE=/run/sing-box-speed.pid
if [ -f "$PIDFILE" ]; then
  pid=$(cat "$PIDFILE" 2>/dev/null || true)
  if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
    kill "$pid" 2>/dev/null || true
    sleep 0.5
    kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$PIDFILE"
fi
pkill -f '/usr/local/bin/sing-box run -c /config/sing-box-speed.json' 2>/dev/null || true
SH;
        try {
            $this->docker->exec(
                $this->awg->containerName(),
                ['bash', '-lc', $script],
                timeout: 15,
            );
        } catch (\Throwable) {
            // ignore
        }
    }

    private function waitForSpeedApi(): void
    {
        $addr = ResolverService::CLASH_SPEED_API_ADDR;
        for ($i = 0; $i < 40; $i++) {
            try {
                $r = $this->docker->exec(
                    $this->awg->containerName(),
                    ['curl', '-sS', '-m', '2', 'http://'.$addr.'/version'],
                    timeout: 5,
                );
                if ($r->successful() && str_contains($r->output(), 'version')) {
                    return;
                }
            } catch (\Throwable) {
                // retry
            }
            usleep(200_000);
        }
        throw new RuntimeException(__('resolver.speed_test_api_not_ready'));
    }

    /**
     * @return array{ms: ?int, error: ?string}
     */
    private function measurePing(string $tag): array
    {
        $path = '/proxies/'.rawurlencode($tag).'/delay';
        $url = 'http://'.ResolverService::CLASH_SPEED_API_ADDR.$path
            .'?'.http_build_query([
                'url' => ResolverService::DELAY_TEST_URL,
                'timeout' => 6000,
            ]);
        try {
            $r = $this->docker->exec(
                $this->awg->containerName(),
                ['curl', '-sS', '-m', '10', $url],
                timeout: 15,
            );
            $decoded = json_decode($r->output(), true);
            if (is_array($decoded) && isset($decoded['delay'])) {
                return ['ms' => (int) $decoded['delay'], 'error' => null];
            }

            return [
                'ms' => null,
                'error' => is_array($decoded)
                    ? (string) ($decoded['message'] ?? __('resolver.speed_test_ping_failed'))
                    : __('resolver.speed_test_ping_failed'),
            ];
        } catch (\Throwable $e) {
            return ['ms' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{mbps: ?float, bytes: ?int, ms: ?int, error: ?string}
     */
    private function measureDownload(): array
    {
        $proxy = 'socks5h://'.ResolverService::SPEED_MIXED_LISTEN.':'.ResolverService::SPEED_MIXED_PORT;
        $fmt = escapeshellarg('%{speed_download} %{time_total} %{http_code} %{size_download}');
        $cmd = 'curl -sS -o /dev/null -m 40 -x '.escapeshellarg($proxy)
            .' -w '.$fmt.' '.escapeshellarg(ResolverService::SPEED_DOWN_URL);

        return $this->parseCurlSpeed($cmd, 'download');
    }

    /**
     * @return array{mbps: ?float, bytes: ?int, ms: ?int, error: ?string}
     */
    private function measureUpload(): array
    {
        $proxy = 'socks5h://'.ResolverService::SPEED_MIXED_LISTEN.':'.ResolverService::SPEED_MIXED_PORT;
        $bytes = ResolverService::SPEED_TEST_BYTES;
        $fmt = escapeshellarg('%{speed_upload} %{time_total} %{http_code} %{size_upload}');
        $cmd = sprintf(
            'dd if=/dev/zero bs=1000000 count=%d 2>/dev/null | curl -sS -m 40 -x %s -H %s --data-binary @- -w %s %s',
            (int) ceil($bytes / 1_000_000),
            escapeshellarg($proxy),
            escapeshellarg('Content-Type: application/octet-stream'),
            $fmt,
            escapeshellarg(ResolverService::SPEED_UP_URL),
        );

        return $this->parseCurlSpeed($cmd, 'upload');
    }

    /**
     * @return array{mbps: ?float, bytes: ?int, ms: ?int, error: ?string}
     */
    private function parseCurlSpeed(string $shellCmd, string $kind): array
    {
        try {
            $r = $this->docker->exec(
                $this->awg->containerName(),
                ['bash', '-lc', $shellCmd],
                timeout: 50,
            );
            $out = trim($r->output());
            $err = trim($r->errorOutput());
            if (! preg_match('/^([0-9.]+)\s+([0-9.]+)\s+(\d+)\s+(\d+)\s*$/', $out, $m)) {
                return [
                    'mbps' => null,
                    'bytes' => null,
                    'ms' => null,
                    'error' => $err !== '' ? $err : ($out !== '' ? $out : __('resolver.speed_test_'.$kind.'_failed')),
                ];
            }
            $speedBps = (float) $m[1];
            $timeSec = (float) $m[2];
            $http = (int) $m[3];
            $size = (int) $m[4];
            if ($http < 200 || $http >= 300 || $speedBps <= 0) {
                return [
                    'mbps' => null,
                    'bytes' => $size > 0 ? $size : null,
                    'ms' => $timeSec > 0 ? (int) round($timeSec * 1000) : null,
                    'error' => __('resolver.speed_test_http_failed', ['code' => $http]),
                ];
            }

            return [
                'mbps' => round(($speedBps * 8) / 1_000_000, 2),
                'bytes' => $size,
                'ms' => (int) round($timeSec * 1000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'mbps' => null,
                'bytes' => null,
                'ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
