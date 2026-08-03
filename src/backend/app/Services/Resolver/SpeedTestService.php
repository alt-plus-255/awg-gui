<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class SpeedTestService
{
    public const LOCK_KEY = 'resolver:speed_test_lock';

    public const LOCK_SEC = 180;

    /** Clash delay probe timeout (ms) — quick reachability check before speed. */
    public const PING_TIMEOUT_MS = 3000;

    public const JOB_KEY = 'resolver:speed_test:job';

    public const RESULTS_KEY = 'resolver:speed_test:results';

    public const RESULTS_TTL_SEC = 60 * 60 * 24 * 30;

    public const JOB_TTL_SEC = 60 * 60 * 6;

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
     * @return array{ok: bool, async: bool, job: array<string, mixed>}
     */
    public function enqueueConnection(ResolverConnection $conn, ?string $nodeKey = null): array
    {
        if (! $conn->enabled) {
            throw new RuntimeException(__('resolver.speed_test_connection_disabled'));
        }

        return $this->enqueue(fn () => $this->newJob([
            'kind' => 'connection',
            'connection_id' => (int) $conn->id,
            'node_key' => $nodeKey,
            'connection_ids' => [(int) $conn->id],
        ]));
    }

    /**
     * @param  list<ResolverConnection>|iterable<ResolverConnection>  $connections
     * @return array{ok: bool, async: bool, job: array<string, mixed>}
     */
    public function enqueueBatch(iterable $connections): array
    {
        $ids = [];
        foreach ($connections as $conn) {
            if ($conn instanceof ResolverConnection && $conn->enabled) {
                $ids[] = (int) $conn->id;
            }
        }
        if ($ids === []) {
            throw new RuntimeException(__('resolver.speed_test_no_enabled'));
        }

        return $this->enqueue(fn () => $this->newJob([
            'kind' => 'batch',
            'connection_id' => null,
            'node_key' => null,
            'connection_ids' => $ids,
        ]));
    }

    /**
     * @param  callable(): array<string, mixed>  $factory
     * @return array{ok: bool, async: bool, job: array<string, mixed>}
     */
    private function enqueue(callable $factory): array
    {
        $gate = Cache::lock('resolver:speed_test:enqueue', 15);
        if (! $gate->get()) {
            throw new RuntimeException(__('resolver.speed_test_busy'));
        }
        try {
            $this->assertNoActiveJob();
            $job = $factory();
            $this->putJob($job);
            $this->spawnJob((string) $job['id']);

            return ['ok' => true, 'async' => true, 'job' => $job];
        } finally {
            $gate->release();
        }
    }

    /**
     * @return array{running: bool, job: ?array<string, mixed>, results: array{updated_at: ?string, by_key: array<string, array<string, mixed>>}}
     */
    public function status(): array
    {
        $job = $this->getJob();
        $running = $job !== null && in_array($job['status'] ?? '', ['queued', 'running'], true);

        return [
            'running' => $running,
            'job' => $job,
            'results' => $this->getStoredResults(),
        ];
    }

    public function processQueuedJob(string $jobId): void
    {
        $job = $this->getJob();
        if ($job === null || (string) ($job['id'] ?? '') !== $jobId) {
            return;
        }
        if (($job['status'] ?? '') !== 'queued') {
            return;
        }

        $job['status'] = 'running';
        $job['started_at'] = now()->toIso8601String();
        $this->putJob($job);

        try {
            if (($job['kind'] ?? '') === 'batch') {
                $ids = is_array($job['connection_ids'] ?? null) ? $job['connection_ids'] : [];
                foreach ($ids as $id) {
                    $conn = ResolverConnection::query()->find((int) $id);
                    if (! $conn instanceof ResolverConnection || ! $conn->enabled) {
                        continue;
                    }
                    $job['current_connection_id'] = (int) $conn->id;
                    $this->putJob($job);
                    $result = $this->run($conn, null);
                    $this->storeResult($result);
                }
            } else {
                $conn = ResolverConnection::query()->find((int) ($job['connection_id'] ?? 0));
                if (! $conn instanceof ResolverConnection) {
                    throw new RuntimeException(__('resolver.speed_test_connection_disabled'));
                }
                $nodeKey = isset($job['node_key']) && is_string($job['node_key']) && $job['node_key'] !== ''
                    ? $job['node_key']
                    : null;
                $job['current_connection_id'] = (int) $conn->id;
                $this->putJob($job);
                $result = $this->run($conn, $nodeKey);
                $this->storeResult($result);
            }

            $job = $this->getJob() ?? $job;
            $job['status'] = 'done';
            $job['finished_at'] = now()->toIso8601String();
            $job['current_connection_id'] = null;
            $job['error'] = null;
            $this->putJob($job);
        } catch (\Throwable $e) {
            $job = $this->getJob() ?? $job;
            $job['status'] = 'failed';
            $job['finished_at'] = now()->toIso8601String();
            $job['current_connection_id'] = null;
            $job['error'] = $e->getMessage();
            $this->putJob($job);
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function storeResult(array $result): void
    {
        $key = $this->resultKey(
            (int) ($result['connection_id'] ?? 0),
            isset($result['node_key']) && is_string($result['node_key']) && $result['node_key'] !== ''
                ? $result['node_key']
                : null,
        );
        $stored = $this->getStoredResults();
        $stored['by_key'][$key] = array_merge($result, [
            'measured_at' => now()->toIso8601String(),
        ]);
        $stored['updated_at'] = now()->toIso8601String();
        Cache::put(self::RESULTS_KEY, $stored, self::RESULTS_TTL_SEC);
    }

    public function resultKey(int $connectionId, ?string $nodeKey = null): string
    {
        return $nodeKey !== null && $nodeKey !== ''
            ? $connectionId.'::'.$nodeKey
            : (string) $connectionId;
    }

    /**
     * @return array{updated_at: ?string, by_key: array<string, array<string, mixed>>}
     */
    public function getStoredResults(): array
    {
        $raw = Cache::get(self::RESULTS_KEY);
        if (! is_array($raw)) {
            return ['updated_at' => null, 'by_key' => []];
        }
        $byKey = is_array($raw['by_key'] ?? null) ? $raw['by_key'] : [];

        return [
            'updated_at' => isset($raw['updated_at']) && is_string($raw['updated_at']) ? $raw['updated_at'] : null,
            'by_key' => $byKey,
        ];
    }

    /** @return ?array<string, mixed> */
    public function getJob(): ?array
    {
        $job = Cache::get(self::JOB_KEY);

        return is_array($job) ? $job : null;
    }

    private function assertNoActiveJob(): void
    {
        $job = $this->getJob();
        if ($job !== null && in_array($job['status'] ?? '', ['queued', 'running'], true)) {
            throw new RuntimeException(__('resolver.speed_test_busy'));
        }
        $lock = Cache::lock(self::LOCK_KEY, 1);
        if (! $lock->get()) {
            throw new RuntimeException(__('resolver.speed_test_busy'));
        }
        $lock->release();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function newJob(array $extra): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'status' => 'queued',
            'kind' => 'connection',
            'connection_id' => null,
            'node_key' => null,
            'connection_ids' => [],
            'current_connection_id' => null,
            'queued_at' => now()->toIso8601String(),
            'started_at' => null,
            'finished_at' => null,
            'error' => null,
        ], $extra);
    }

    /** @param  array<string, mixed>  $job */
    private function putJob(array $job): void
    {
        Cache::put(self::JOB_KEY, $job, self::JOB_TTL_SEC);
    }

    private function spawnJob(string $jobId): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $artisan = base_path('artisan');
        $log = storage_path('logs/speed-test-job.log');
        $this->rotateLogIfHuge($log);
        $cmd = sprintf(
            'nohup %s %s resolver:speed-test-job %s >> %s 2>&1 < /dev/null &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($jobId),
            escapeshellarg($log),
        );
        exec($cmd);
    }

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
            $reachable = ($ping['ms'] !== null && $ping['ms'] > 0);
            if (! $reachable) {
                return [
                    'ok' => false,
                    'outbound_tag' => $targetTag,
                    'connection_id' => (int) $conn->id,
                    'node_key' => $nodeKey,
                    'ping_ms' => $ping['ms'],
                    'download_mbps' => null,
                    'upload_mbps' => null,
                    'download_bytes' => null,
                    'upload_bytes' => null,
                    'download_ms' => null,
                    'upload_ms' => null,
                    'error' => $ping['error'] ?: __('resolver.speed_test_unreachable'),
                ];
            }

            $down = $this->measureDownload();
            $up = $this->measureUpload();

            $ok = ($down['mbps'] !== null && $down['mbps'] > 0)
                || ($up['mbps'] !== null && $up['mbps'] > 0);

            $errors = array_values(array_filter([
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
LOG_MAX_BYTES=$((10 * 1024 * 1024))
"$BIN" check -c "$CONFIG"
if [ -f "$LOG" ]; then
  size=$(wc -c < "$LOG" | tr -d '[:space:]')
  if [ -n "$size" ] && [ "$size" -gt "$LOG_MAX_BYTES" ] 2>/dev/null; then
    rm -f "$LOG.1"
    mv -f "$LOG" "$LOG.1"
  fi
fi
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
                'timeout' => self::PING_TIMEOUT_MS,
            ]);
        $curlMax = max(5, (int) ceil(self::PING_TIMEOUT_MS / 1000) + 2);
        try {
            $r = $this->docker->exec(
                $this->awg->containerName(),
                ['curl', '-sS', '-m', (string) $curlMax, $url],
                timeout: $curlMax + 5,
            );
            $decoded = json_decode($r->output(), true);
            if (is_array($decoded) && isset($decoded['delay']) && (int) $decoded['delay'] > 0) {
                return ['ms' => (int) $decoded['delay'], 'error' => null];
            }

            return [
                'ms' => null,
                'error' => is_array($decoded)
                    ? (string) ($decoded['message'] ?? __('resolver.speed_test_unreachable'))
                    : __('resolver.speed_test_unreachable'),
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
        // -o /dev/null is required: otherwise Cloudflare's response body mixes into -w metrics.
        $cmd = sprintf(
            'dd if=/dev/zero bs=1000000 count=%d 2>/dev/null | curl -sS -o /dev/null -m 45 -x %s -H %s --data-binary @- -w %s %s',
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
                timeout: 55,
            );
            $out = trim($r->output());
            $err = trim($r->errorOutput());
            // Prefer a trailing metrics line (in case anything else leaked to stdout).
            if (! preg_match('/([0-9.]+)\s+([0-9.]+)\s+(\d+)\s+(\d+)\s*$/', $out, $m)) {
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

    private function rotateLogIfHuge(string $path, int $maxBytes = 10 * 1024 * 1024): void
    {
        if (! is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if ($size === false || $size <= $maxBytes) {
            return;
        }
        @unlink($path.'.1');
        @rename($path, $path.'.1');
        @file_put_contents($path, '');
    }
}
