<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SubscriptionPingService
{
    public const SESSION_LOCK_KEY = 'ping_probe:session_lock';

    public const SESSION_ACTIVE_KEY = 'ping_probe:session_active';

    public const SESSION_CANCEL_KEY = 'ping_probe:session_cancel';

    public const DEFAULT_TIMEOUT_MS = 6000;

    public const FAST_TCP_TIMEOUT_SEC = 2.0;

    public const PARALLEL_BATCH = 20;

    public const CACHE_TTL_MINUTES = 12;

    public function __construct(
        private PingProbeManager $probe,
        private PingProbeConfigSync $configSync,
        private ConnectionOutboundBuilder $outboundBuilder,
        private ClashApiClient $clash,
        private TcpReachabilityProbe $tcpProbe,
        private ResolverService $resolver,
    ) {}

    /**
     * @param  (callable(array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}): void)|null  $onResult
     * @return list<array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>
     */
    public function pingNodes(ResolverConnection $conn, ?callable $onResult = null, bool $fastOnly = false): array
    {
        $pairs = $this->outboundBuilder->pingableNodes($conn);
        if ($pairs === []) {
            throw new RuntimeException('Нет узлов для проверки пинга');
        }

        return $this->runPingSession(function () use ($pairs, $conn, $onResult, $fastOnly) {
            $keyToTag = [];
            foreach ($pairs as $pair) {
                $keyToTag[$pair['key']] = $pair['tag'];
            }

            return $this->pingByTags($conn, $keyToTag, $onResult, $fastOnly);
        }, $conn);
    }

    /**
     * @return array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}
     */
    public function pingNode(ResolverConnection $conn, string $nodeKey, bool $fastOnly = false): array
    {
        $tag = $this->resolveNodeTag($conn, $nodeKey);
        if ($tag === null) {
            return [
                'key' => $nodeKey,
                'latency_ms' => null,
                'ok' => false,
                'error' => 'Узел не в probe — выберите локацию и примените',
            ];
        }

        $results = $this->runPingSession(function () use ($conn, $nodeKey, $tag, $fastOnly) {
            return $this->pingByTags($conn, [$nodeKey => $tag], null, $fastOnly);
        }, $conn);

        return $results[0] ?? [
            'key' => $nodeKey,
            'latency_ms' => null,
            'ok' => false,
            'error' => 'не проверен',
        ];
    }

    /**
     * @return array{ok: bool, latency_ms: ?int, error: ?string, source?: string}
     */
    public function pingConnection(ResolverConnection $conn, bool $fastOnly = false): array
    {
        $tag = $conn->outboundTag();

        $results = $this->runPingSession(function () use ($conn, $tag, $fastOnly) {
            return $this->pingByTags($conn, ['__conn' => $tag], null, $fastOnly);
        }, $conn);

        $r = $results['__conn'] ?? $results[array_key_first($results)] ?? null;
        if ($r === null) {
            return [
                'ok' => false,
                'latency_ms' => null,
                'error' => 'не проверен',
            ];
        }

        return [
            'ok' => $r['ok'],
            'latency_ms' => $r['latency_ms'],
            'error' => $r['error'],
            'source' => $r['source'] ?? 'proxy',
        ];
    }

    public function resolveNodeTag(ResolverConnection $conn, string $nodeKey): ?string
    {
        return $this->outboundBuilder->resolveNodeTag($conn, $nodeKey);
    }

    /**
     * @return array<string, array{latency_ms: ?int, latency_ok: bool, latency_error: ?string, source: string, tested_at: string}>|null
     */
    public function readCachedLatencies(ResolverConnection $conn): ?array
    {
        $cache = $conn->latency_cache;
        if (! is_array($cache) || ! is_array($cache['nodes'] ?? null)) {
            return null;
        }

        $ttl = self::CACHE_TTL_MINUTES * 60;
        $nodes = [];
        foreach ($cache['nodes'] as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $testedAt = strtotime((string) ($entry['tested_at'] ?? ''));
            if ($testedAt === false || (time() - $testedAt) > $ttl) {
                continue;
            }
            $nodes[(string) $key] = [
                'latency_ms' => isset($entry['latency_ms']) ? (int) $entry['latency_ms'] : null,
                'latency_ok' => (bool) ($entry['latency_ok'] ?? false),
                'latency_error' => $entry['latency_error'] ?? null,
                'source' => (string) ($entry['source'] ?? 'cached'),
                'tested_at' => (string) $entry['tested_at'],
            ];
        }

        return $nodes === [] ? null : $nodes;
    }

    /**
     * @return array{key: string, name: string, latency_ms: ?int, source: string}|null
     */
    public function bestPingFromCache(ResolverConnection $conn): ?array
    {
        $cached = $this->readCachedLatencies($conn);
        if ($cached === null) {
            return null;
        }

        $nodes = is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [];
        $nameByKey = [];
        foreach ($nodes as $node) {
            if (is_array($node) && ! empty($node['key'])) {
                $nameByKey[(string) $node['key']] = (string) ($node['name'] ?? $node['key']);
            }
        }

        $best = null;
        foreach ($cached as $key => $entry) {
            if (! ($entry['latency_ok'] ?? false)) {
                continue;
            }
            $ms = $entry['latency_ms'] ?? null;
            if ($ms === null) {
                continue;
            }
            if ($best === null || $ms < $best['latency_ms']) {
                $best = [
                    'key' => $key,
                    'name' => $nameByKey[$key] ?? $key,
                    'latency_ms' => $ms,
                    'source' => (string) ($entry['source'] ?? 'ping_cache'),
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array{key: string, name?: string, latency_ms?: ?int}  $pick
     */
    public function persistActivePick(ResolverConnection $conn, array $pick, string $source): void
    {
        $key = (string) ($pick['key'] ?? '');
        if ($key === '') {
            return;
        }

        $conn->subscription_active = [
            'key' => $key,
            'name' => (string) ($pick['name'] ?? $key),
            'latency_ms' => isset($pick['latency_ms']) && is_numeric($pick['latency_ms']) ? (int) $pick['latency_ms'] : null,
            'source' => $source,
            'updated_at' => now()->toIso8601String(),
        ];
        $conn->save();
    }

    /**
     * @return array{key: string, name: string, latency_ms: ?int, source: string}|null
     */
    public function readPersistedActivePick(ResolverConnection $conn): ?array
    {
        $data = $conn->subscription_active;
        if (! is_array($data) || empty($data['key'])) {
            return null;
        }

        return [
            'key' => (string) $data['key'],
            'name' => (string) ($data['name'] ?? $data['key']),
            'latency_ms' => isset($data['latency_ms']) && is_numeric($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            'source' => (string) ($data['source'] ?? 'cached'),
        ];
    }

    /**
     * @return array{key: string, name: string, latency_ms: ?int}|null
     */
    public function resolveUrltestActivePick(ResolverConnection $conn): ?array
    {
        if (! $conn->isUrltestMode() || ! $conn->enabled) {
            return null;
        }

        try {
            $tag = $this->resolver->routingOutboundTag($conn);
            $node = $conn->nodeForChildTag($tag);
            if (! is_array($node) || empty($node['key'])) {
                return null;
            }

            $key = (string) $node['key'];
            $latencyMs = null;
            $resp = $this->resolver->clashApiRequest('/proxies/'.rawurlencode($tag), [], 3);
            if ($resp['ok'] && is_array($resp['body']['history'] ?? null) && $resp['body']['history'] !== []) {
                $last = end($resp['body']['history']);
                if (is_numeric($last)) {
                    $latencyMs = (int) $last;
                }
            }

            $cached = $this->readCachedLatencies($conn);
            if ($latencyMs === null && is_array($cached[$key] ?? null)) {
                $latencyMs = $cached[$key]['latency_ms'] ?? null;
            }

            return [
                'key' => $key,
                'name' => (string) ($node['name'] ?? $key),
                'latency_ms' => is_numeric($latencyMs) ? (int) $latencyMs : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function syncActivePickAfterPing(ResolverConnection $conn): void
    {
        $conn = $conn->fresh();
        if (! $conn->isSubscription()) {
            return;
        }

        if ($conn->subscription_mode === ResolverConnection::MODE_SINGLE) {
            $key = (string) ($conn->subscription_selected ?? '');
            if ($key === '') {
                return;
            }

            $name = $key;
            foreach (is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [] as $node) {
                if (is_array($node) && ($node['key'] ?? '') === $key) {
                    $name = (string) ($node['name'] ?? $key);
                    break;
                }
            }

            $cached = $this->readCachedLatencies($conn);
            $lat = is_array($cached[$key] ?? null) ? $cached[$key]['latency_ms'] : null;
            $this->persistActivePick($conn, [
                'key' => $key,
                'name' => $name,
                'latency_ms' => $lat,
            ], 'user');

            return;
        }

        $urltest = $this->resolveUrltestActivePick($conn);
        if ($urltest !== null) {
            $this->persistActivePick($conn, $urltest, 'urltest');

            return;
        }

        $best = $this->bestPingFromCache($conn);
        if ($best !== null) {
            $this->persistActivePick($conn, $best, 'ping');
        }
    }

    /**
     * @return array{key: string, name: string, latency_ms: ?int, source: string}|null
     */
    public function refreshActivePickFromCache(ResolverConnection $conn): ?array
    {
        $best = $this->bestPingFromCache($conn);
        if ($best === null) {
            return null;
        }

        $this->persistActivePick($conn, $best, 'ping');

        return [
            'key' => $best['key'],
            'name' => $best['name'],
            'latency_ms' => $best['latency_ms'],
            'source' => 'ping',
        ];
    }

    /**
     * @return array{ok: true, restarted: true, had_active_session: bool}
     */
    public function cancelActiveSessionAndRestartProbe(): array
    {
        $hadActiveSession = Cache::has(self::SESSION_ACTIVE_KEY);

        Cache::put(self::SESSION_CANCEL_KEY, true, 60);
        $this->probe->stop();
        Cache::forget(self::SESSION_ACTIVE_KEY);
        Cache::lock(self::SESSION_LOCK_KEY)->forceRelease();
        Cache::forget(self::SESSION_CANCEL_KEY);

        $this->configSync->rebuildAndMaybeReload();
        $this->probe->ensureStarted();

        return [
            'ok' => true,
            'restarted' => true,
            'had_active_session' => $hadActiveSession,
        ];
    }

    /**
     * @param  callable(): array<string, array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>|list<array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>  $callback
     * @return list<array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>
     */
    private function runPingSession(callable $callback, ?ResolverConnection $conn = null): array
    {
        $lock = Cache::lock(self::SESSION_LOCK_KEY, 600);
        if (! $lock->get()) {
            throw new PingSessionBusyException;
        }

        Cache::put(self::SESSION_ACTIVE_KEY, true, 600);

        try {
            $this->configSync->rebuildAndMaybeReload();
            $this->probe->ensureStarted();
            $this->probe->touch();

            $result = $callback();
            $list = array_is_list($result) ? $result : array_values($result);

            $this->probe->touch();
            $this->probe->applyPendingReload();

            if ($conn !== null) {
                $this->touchPingCheckedAt($conn);
            }

            return $list;
        } finally {
            Cache::forget(self::SESSION_ACTIVE_KEY);
            Cache::forget(self::SESSION_CANCEL_KEY);
            $lock->release();
        }
    }

    private function assertNotCancelled(): void
    {
        if (Cache::get(self::SESSION_CANCEL_KEY)) {
            throw new RuntimeException('Пинг отменён');
        }
    }

    /**
     * @return callable(): bool
     */
    private function cancelChecker(): callable
    {
        return fn (): bool => (bool) Cache::get(self::SESSION_CANCEL_KEY);
    }

    /**
     * @param  array<string, string>  $keyToTag
     * @param  (callable(array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}): void)|null  $onResult
     * @return list<array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>
     */
    private function pingByTags(ResolverConnection $conn, array $keyToTag, ?callable $onResult, bool $fastOnly): array
    {
        $keyToOutbound = [];
        $nodeByKey = [];
        foreach (is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [] as $node) {
            if (is_array($node) && ! empty($node['key'])) {
                $nodeByKey[(string) $node['key']] = $node;
            }
        }

        foreach ($keyToTag as $key => $tag) {
            if ($key === '__conn') {
                $keyToOutbound[$key] = is_array($conn->outbound) ? $conn->outbound : [];
            } elseif (isset($nodeByKey[$key]) && is_array($nodeByKey[$key]['outbound'] ?? null)) {
                $keyToOutbound[$key] = $nodeByKey[$key]['outbound'];
            } else {
                $keyToOutbound[$key] = [];
            }
        }

        $toDelay = $keyToTag;
        $results = [];

        $emit = function (array $result) use (&$results, $onResult): void {
            $results[$result['key']] = $result;
            if ($onResult !== null) {
                $onResult($result);
            }
        };

        $shouldCancel = $this->cancelChecker();

        $this->assertNotCancelled();

        $this->tcpProbe->checkManyStreaming(
            $keyToOutbound,
            self::FAST_TCP_TIMEOUT_SEC,
            function (string $key, bool $reachable) use (&$toDelay, $emit, $fastOnly): void {
                if ($reachable) {
                    if ($fastOnly) {
                        $emit([
                            'key' => $key,
                            'latency_ms' => null,
                            'ok' => true,
                            'error' => null,
                            'source' => 'tcp',
                        ]);
                        unset($toDelay[$key]);
                    }

                    return;
                }

                $emit([
                    'key' => $key,
                    'latency_ms' => null,
                    'ok' => false,
                    'error' => 'TCP недоступен',
                    'source' => 'tcp',
                ]);
                unset($toDelay[$key]);
            },
            $shouldCancel
        );

        $this->assertNotCancelled();

        if ($fastOnly) {
            $this->persistCache($conn, $results);

            return $this->sortPingResults(array_values($results));
        }

        if ($toDelay !== []) {
            $this->clash->testOutboundDelaysStreaming(
                $toDelay,
                self::DEFAULT_TIMEOUT_MS,
                true,
                function (string $key, array $d) use ($emit): void {
                    $emit([
                        'key' => $key,
                        'latency_ms' => $d['latency_ms'],
                        'ok' => $d['ok'],
                        'error' => $d['error'],
                        'source' => 'proxy',
                    ]);
                },
                $shouldCancel
            );
        }

        $this->assertNotCancelled();

        $this->persistCache($conn, $results);

        return $this->sortPingResults(array_values($results));
    }

    /**
     * @param  list<array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>  $results
     * @return list<array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>
     */
    private function sortPingResults(array $results): array
    {
        usort($results, function (array $a, array $b): int {
            $tier = function (array $n): int {
                if ($n['ok'] ?? false) {
                    return 0;
                }
                if (! ($n['ok'] ?? true)) {
                    return 2;
                }

                return 1;
            };

            $ta = $tier($a);
            $tb = $tier($b);
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }
            if ($ta === 0) {
                return ($a['latency_ms'] ?? PHP_INT_MAX) <=> ($b['latency_ms'] ?? PHP_INT_MAX);
            }

            return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });

        return $results;
    }

    /**
     * @param  array<string, array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}>  $results
     */
    private function persistCache(ResolverConnection $conn, array $results): void
    {
        if ($results === []) {
            return;
        }

        $existing = is_array($conn->latency_cache) ? $conn->latency_cache : [];
        $nodes = is_array($existing['nodes'] ?? null) ? $existing['nodes'] : [];
        $now = now()->toIso8601String();

        foreach ($results as $key => $r) {
            $nodes[$key] = [
                'latency_ms' => $r['latency_ms'],
                'latency_ok' => $r['ok'],
                'latency_error' => $r['error'],
                'source' => $r['source'] ?? 'proxy',
                'tested_at' => $now,
            ];
        }

        $conn->latency_cache = ['nodes' => $nodes, 'updated_at' => $now];
        $conn->save();
    }

    public function touchPingCheckedAt(ResolverConnection $conn): void
    {
        $conn->ping_last_checked_at = now();
        $conn->save();
    }

    /**
     * @return array{
     *     switched: bool,
     *     mode: string,
     *     pick: ?array{key: string, name: string, latency_ms: ?int, source: string},
     *     previous_key: ?string,
     *     reason: ?string
     * }
     */
    public function applyBestPickIfChanged(ResolverConnection $conn): array
    {
        $best = $this->bestPingFromCache($conn);
        if ($best === null) {
            return [
                'switched' => false,
                'mode' => (string) ($conn->subscription_mode ?? ''),
                'pick' => null,
                'previous_key' => null,
                'reason' => 'no_cache',
            ];
        }

        if ($conn->isUrltestMode()) {
            return [
                'switched' => false,
                'mode' => ResolverConnection::MODE_URLTEST,
                'pick' => $best,
                'previous_key' => null,
                'reason' => 'urltest_auto',
            ];
        }

        if ($conn->subscription_mode === ResolverConnection::MODE_SINGLE) {
            $current = (string) ($conn->subscription_selected ?? '');
            if ($current === $best['key']) {
                return [
                    'switched' => false,
                    'mode' => ResolverConnection::MODE_SINGLE,
                    'pick' => $best,
                    'previous_key' => $current,
                    'reason' => 'unchanged',
                ];
            }

            $outbound = null;
            foreach (is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [] as $node) {
                if (is_array($node) && ($node['key'] ?? '') === $best['key']) {
                    $outbound = $node['outbound'] ?? null;
                    break;
                }
            }

            if (! is_array($outbound) || empty($outbound['type'])) {
                return [
                    'switched' => false,
                    'mode' => ResolverConnection::MODE_SINGLE,
                    'pick' => $best,
                    'previous_key' => $current,
                    'reason' => 'node_not_found',
                ];
            }

            $conn->subscription_selected = $best['key'];
            $conn->outbound = $outbound;
            $conn->save();
            $this->persistActivePick($conn, $best, 'ping');

            return [
                'switched' => true,
                'mode' => ResolverConnection::MODE_SINGLE,
                'pick' => $best,
                'previous_key' => $current !== '' ? $current : null,
                'reason' => 'switched',
            ];
        }

        return [
            'switched' => false,
            'mode' => (string) ($conn->subscription_mode ?? ''),
            'pick' => $best,
            'previous_key' => null,
            'reason' => 'unsupported',
        ];
    }
}
