<?php

namespace App\Services\Resolver;

use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Support\Facades\Process;

class ClashApiClient
{
    public function __construct(private AmneziaWgService $awg) {}

    /**
     * @return array{ok: bool, status: int, body: ?array, raw: string, error: ?string}
     */
    public function clashApiRequest(string $path, array $query = [], int $timeoutSec = 15, bool $probe = false): array
    {
        return $this->request($path, $query, $timeoutSec, $probe);
    }

    public function waitForClashApi(int $attempts = 25, int $sleepMs = 200): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            $resp = $this->clashApiRequest('/version', [], 3, false);
            if ($resp['ok']) {
                return true;
            }
            usleep($sleepMs * 1000);
        }

        return false;
    }

    public function waitForProbeApi(int $attempts = 25, int $sleepMs = 200): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            $resp = $this->clashApiRequest('/version', [], 3, true);
            if ($resp['ok']) {
                return true;
            }
            usleep($sleepMs * 1000);
        }

        return false;
    }

    /**
     * Aggregate RX/TX (download/upload) from Clash /connections by outbound tag.
     *
     * @return array<string, array{rx: int, tx: int, active: bool}>
     */
    public function trafficByOutboundTag(): array
    {
        $resp = $this->clashApiRequest('/connections', [], 8, false);
        $out = [];
        if (! $resp['ok'] || ! is_array($resp['body'])) {
            return $out;
        }

        foreach ($resp['body']['connections'] ?? [] as $conn) {
            if (! is_array($conn)) {
                continue;
            }
            $download = (int) ($conn['download'] ?? 0);
            $upload = (int) ($conn['upload'] ?? 0);
            $chains = $conn['chains'] ?? [];
            if (! is_array($chains)) {
                continue;
            }
            foreach ($chains as $tag) {
                if (! is_string($tag) || ! str_starts_with($tag, 'conn_')) {
                    continue;
                }
                $rollup = $tag;
                if (preg_match('/^(conn_\d+)_\d+$/', $tag, $m)) {
                    $rollup = $m[1];
                }
                if (! isset($out[$rollup])) {
                    $out[$rollup] = ['rx' => 0, 'tx' => 0, 'active' => false];
                }
                $out[$rollup]['rx'] += $download;
                $out[$rollup]['tx'] += $upload;
                $out[$rollup]['active'] = true;
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, latency_ms: ?int, error: ?string}
     */
    public function testOutboundDelay(string $tag, int $timeoutMs = 5000, bool $probe = false): array
    {
        $path = '/proxies/'.rawurlencode($tag).'/delay';
        $resp = $this->clashApiRequest($path, [
            'url' => ResolverService::DELAY_TEST_URL,
            'timeout' => $timeoutMs,
        ], (int) ceil($timeoutMs / 1000) + 5, $probe);

        return $this->parseDelayApiResponse($resp);
    }

    /**
     * @param  array<string, string>  $keyToTag
     * @return array<string, array{ok: bool, latency_ms: ?int, error: ?string}>
     */
    public function testOutboundDelaysParallel(array $keyToTag, int $timeoutMs = 6000, bool $probe = false): array
    {
        return $this->testOutboundDelaysStreaming($keyToTag, $timeoutMs, $probe);
    }

    /**
     * Run N delay checks in parallel; invoke $onResult($key, $result) as each completes.
     *
     * @param  array<string, string>  $keyToTag
     * @param  (callable(string, array{ok: bool, latency_ms: ?int, error: ?string}): void)|null  $onResult
     * @return array<string, array{ok: bool, latency_ms: ?int, error: ?string}>
     */
    public function testOutboundDelaysStreaming(
        array $keyToTag,
        int $timeoutMs = 6000,
        bool $probe = false,
        ?callable $onResult = null,
        ?callable $shouldCancel = null,
    ): array {
        if ($keyToTag === []) {
            return [];
        }

        $container = $this->awg->containerName();
        $apiAddr = $probe ? ResolverService::CLASH_PROBE_API_ADDR : ResolverService::CLASH_API_ADDR;
        $curlTimeout = (int) ceil($timeoutMs / 1000) + 5;
        $procTimeout = $curlTimeout + 5;
        $out = [];
        $running = [];

        foreach ($keyToTag as $key => $tag) {
            $path = '/proxies/'.rawurlencode($tag).'/delay';
            $url = 'http://'.$apiAddr.$path.'?'.http_build_query([
                'url' => ResolverService::DELAY_TEST_URL,
                'timeout' => $timeoutMs,
            ]);
            $running[$key] = Process::timeout($procTimeout)->start([
                'docker', 'exec', $container,
                'curl', '-sS', '-m', (string) $curlTimeout,
                '-w', '___HTTP_STATUS___%{http_code}',
                $url,
            ]);
        }

        while ($running !== []) {
            if ($shouldCancel !== null && $shouldCancel()) {
                foreach ($running as $proc) {
                    if ($proc->running()) {
                        $proc->signal(SIGTERM);
                    }
                }

                return $out;
            }

            foreach ($running as $key => $proc) {
                if ($proc->running()) {
                    continue;
                }

                if (! $proc->successful()) {
                    $parsed = [
                        'ok' => false,
                        'latency_ms' => null,
                        'error' => trim($proc->errorOutput() ?: '') ?: 'ошибка проверки',
                    ];
                } else {
                    $parsed = $this->parseDelayCurlOutput((string) $proc->output());
                }

                $out[$key] = $parsed;
                if ($onResult !== null) {
                    $onResult($key, $parsed);
                }
                unset($running[$key]);
            }

            if ($running !== []) {
                usleep(40_000);
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, status: int, body: ?array, raw: string, error: ?string}
     */
    private function request(string $path, array $query, int $timeoutSec, bool $probe): array
    {
        $qs = $query === [] ? '' : '?'.http_build_query($query);
        $addr = $probe ? ResolverService::CLASH_PROBE_API_ADDR : ResolverService::CLASH_API_ADDR;
        $url = 'http://'.$addr.$path.$qs;

        try {
            $r = Process::timeout($timeoutSec + 5)->run([
                'docker', 'exec', $this->awg->containerName(),
                'curl', '-sS', '-m', (string) $timeoutSec,
                '-w', '___HTTP_STATUS___%{http_code}',
                $url,
            ]);
            $out = $r->output();
            if ($out === '' || $out === false) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => null,
                    'raw' => trim($r->errorOutput()),
                    'error' => $probe
                        ? 'Clash API probe недоступен'
                        : 'Clash API недоступен (sing-box не запущен?)',
                ];
            }
            $marker = '___HTTP_STATUS___';
            $pos = strrpos($out, $marker);
            if ($pos === false) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => null,
                    'raw' => trim($out),
                    'error' => 'Некорректный ответ Clash API',
                ];
            }
            $rawBody = substr($out, 0, $pos);
            $status = (int) substr($out, $pos + strlen($marker));
            $decoded = json_decode($rawBody, true);

            if ($status === 0) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => null,
                    'raw' => $rawBody,
                    'error' => 'Clash API недоступен (sing-box ещё не готов)',
                ];
            }

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'body' => is_array($decoded) ? $decoded : null,
                'raw' => $rawBody,
                'error' => $status >= 200 && $status < 300
                    ? null
                    : ((is_array($decoded) ? ($decoded['message'] ?? null) : null) ?: (trim($rawBody) !== '' ? trim($rawBody) : "HTTP {$status}")),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'raw' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array{ok: bool, status: int, body: ?array, raw: string, error: ?string}  $resp
     * @return array{ok: bool, latency_ms: ?int, error: ?string}
     */
    private function parseDelayApiResponse(array $resp): array
    {
        if ($resp['ok'] && isset($resp['body']['delay'])) {
            $delay = (int) $resp['body']['delay'];

            return [
                'ok' => $delay > 0,
                'latency_ms' => $delay > 0 ? $delay : null,
                'error' => $delay > 0 ? null : 'Нулевая задержка',
            ];
        }

        $err = $resp['error'] ?? 'Проверка не удалась';
        if (is_string($resp['raw']) && $resp['raw'] !== '' && str_contains($resp['raw'], '{')) {
            $j = json_decode($resp['raw'], true);
            if (is_array($j) && ! empty($j['message'])) {
                $err = (string) $j['message'];
            }
        }

        return [
            'ok' => false,
            'latency_ms' => null,
            'error' => $this->localizeDelayError($err),
        ];
    }

    private function localizeDelayError(string $err): string
    {
        $lower = strtolower($err);
        if ($lower === 'timeout' || str_contains($lower, 'timeout')) {
            return 'Таймаут';
        }
        if (str_contains($lower, 'an error occurred in the delay test')) {
            return 'Узел недоступен';
        }
        if (str_contains($lower, 'context deadline exceeded')) {
            return 'Таймаут соединения';
        }

        return $err;
    }

    /**
     * @return array{ok: bool, latency_ms: ?int, error: ?string}
     */
    private function parseDelayCurlOutput(string $out): array
    {
        $marker = '___HTTP_STATUS___';
        $pos = strrpos($out, $marker);
        if ($pos === false) {
            return [
                'ok' => false,
                'latency_ms' => null,
                'error' => 'Некорректный ответ Clash API',
            ];
        }

        $rawBody = substr($out, 0, $pos);
        $status = (int) substr($out, $pos + strlen($marker));
        $decoded = json_decode($rawBody, true);

        return $this->parseDelayApiResponse([
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $rawBody,
            'error' => $status >= 200 && $status < 300
                ? null
                : ((is_array($decoded) ? ($decoded['message'] ?? null) : null) ?: (trim($rawBody) !== '' ? trim($rawBody) : "HTTP {$status}")),
        ]);
    }
}
