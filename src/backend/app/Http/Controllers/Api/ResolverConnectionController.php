<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResolverConnection;
use App\Services\Resolver\PingProbeConfigSync;
use App\Services\Resolver\PingProbeManager;
use App\Services\Resolver\PingSessionBusyException;
use App\Services\Resolver\ResolverConnectionSingBoxFingerprint;
use App\Services\Resolver\ResolverService;
use App\Services\Resolver\SingBoxOutboundParser;
use App\Services\Resolver\SubscriptionFetcher;
use App\Services\Resolver\SubscriptionPingService;
use App\Services\Resolver\TspuProbe;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ResolverConnectionController extends Controller
{
    public function __construct(
        private SingBoxOutboundParser $parser,
        private SubscriptionFetcher $subscriptionFetcher,
        private ResolverService $resolver,
        private TspuProbe $tspu,
        private SubscriptionPingService $pingService,
        private PingProbeConfigSync $pingProbeSync,
        private PingProbeManager $pingProbe,
        private ResolverConnectionSingBoxFingerprint $singBoxFingerprint,
    ) {}

    public function warmupPingProbe()
    {
        try {
            $this->pingProbeSync->rebuildAndMaybeReload();
            if ($this->pingProbe->isRunning()) {
                // Probe уже запущен другим процессом/запросом.
                // Не пытемся запускать повторно — только продлеваем таймер бездействия.
                $this->pingProbe->touch();

                return response()->json(['ok' => true, 'already_running' => true]);
            }

            $this->pingProbe->ensureStarted();
        } catch (\Throwable $e) {
            // На случай гонки: probe мог стартовать между isRunning() и ensureStarted().
            if ($this->pingProbe->isRunning()) {
                try {
                    $this->pingProbe->touch();
                    return response()->json(['ok' => true, 'already_running' => true]);
                } catch (\Throwable) {
                    // fallthrough to original error
                }
            }

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function restartPingProbe()
    {
        try {
            return response()->json($this->pingService->cancelActiveSessionAndRestartProbe());
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function pingSession()
    {
        return response()->json($this->pingService->sessionStatus());
    }

    public function index()
    {
        $items = ResolverConnection::query()
            ->withCount('configs')
            ->orderBy('id')
            ->get()
            ->map(fn (ResolverConnection $c) => $this->serialize($c));

        return response()->json(['connections' => $items]);
    }

    public function parseSubscription(Request $request)
    {
        $data = $request->validate([
            'url' => ['nullable', 'string', 'max:8000'],
            'body' => ['nullable', 'string', 'max:500000'],
        ]);

        if (trim((string) ($data['url'] ?? '')) === '' && trim((string) ($data['body'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'url' => [__('resolver.subscription_url_or_content_required')],
            ]);
        }

        $nodes = $this->fetchSubscriptionNodes(
            trim((string) ($data['url'] ?? '')),
            isset($data['body']) ? (string) $data['body'] : null,
        );

        return response()->json([
            'nodes' => $this->publicNodes($nodes),
            'count' => count($nodes),
        ]);
    }

    public function pingSubscription(Request $request)
    {
        throw ValidationException::withMessages([
            'connection' => [__('resolver.save_connection_before_ping')],
        ]);
    }

    public function pingSubscriptionStream(Request $request)
    {
        throw ValidationException::withMessages([
            'connection' => [__('resolver.save_connection_before_ping')],
        ]);
    }

    public function pingSubscriptionNode(Request $request)
    {
        throw ValidationException::withMessages([
            'connection' => [__('resolver.save_connection_before_ping')],
        ]);
    }

    public function pingConnectionSubscription(ResolverConnection $connection, Request $request)
    {
        @set_time_limit(600);

        if (! $connection->isSubscription()) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.ping_subscriptions_only')],
            ]);
        }

        $nodes = is_array($connection->subscription_nodes) ? $connection->subscription_nodes : [];
        if ($nodes === []) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.no_cached_nodes')],
            ]);
        }

        $fastOnly = $request->boolean('fast');

        return response()->json($this->pingNodesResponse($connection, $fastOnly));
    }

    public function pingConnectionSubscriptionStream(ResolverConnection $connection, Request $request)
    {
        @set_time_limit(600);

        if (! $connection->isSubscription()) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.ping_subscriptions_only')],
            ]);
        }

        $nodes = is_array($connection->subscription_nodes) ? $connection->subscription_nodes : [];
        if ($nodes === []) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.no_cached_nodes')],
            ]);
        }

        $fastOnly = $request->boolean('fast');

        return $this->streamPingNodesResponse($connection, $fastOnly, $request->boolean('auto_apply'));
    }

    public function syncBestPick(ResolverConnection $connection)
    {
        if (! $connection->isSubscription()) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.subscriptions_only')],
            ]);
        }

        $switch = $this->pingService->applyBestPickIfChanged($connection->fresh());
        if ($switch['switched'] ?? false) {
            $this->reloadIfNeeded();
            $this->syncPingProbe();
        }
        $this->pingService->syncActivePickAfterPing($connection->fresh());

        $fresh = $connection->fresh()->loadCount('configs');

        return response()->json([
            'result' => $switch,
            'connection' => $this->serialize($fresh),
        ]);
    }

    public function pingConnectionSubscriptionNode(ResolverConnection $connection, Request $request)
    {
        @set_time_limit(120);

        if (! $connection->isSubscription()) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.ping_subscriptions_only')],
            ]);
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'fast' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->pingService->pingNode($connection, $data['key'], $request->boolean('fast'));
        } catch (PingSessionBusyException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'ping_busy',
            ], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'result' => $this->formatPingResult($result),
        ]);
    }

    public function test(ResolverConnection $connection)
    {
        if (! $connection->enabled) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.connection_disabled_enable_first')],
            ]);
        }

        $probeOutbound = $connection->tspuProbeOutbound();

        if (! $this->resolver->waitForClashApi()) {
            try {
                $this->resolver->apply();
            } catch (\Throwable) {
                // status below
            }
            if (! $this->resolver->waitForClashApi()) {
                $tspu = $this->tspu->probe($probeOutbound, false);
                $connection->last_tested_at = now();
                $connection->last_test_ok = false;
                $connection->last_latency_ms = null;
                $connection->last_tspu_status = $tspu['status'];
                $connection->last_tspu_likely = $tspu['tspu_likely'];
                $connection->last_tspu_detail = $tspu['detail'];
                $connection->last_tspu_meta = $tspu;
                $connection->last_test_error = $tspu['tspu_likely']
                    ? __('resolver.tspu_prefix', ['detail' => $tspu['detail']])
                    : __('resolver.singbox_clash_unavailable');
                $connection->save();

                return response()->json([
                    'ok' => false,
                    'latency_ms' => null,
                    'error' => $connection->last_test_error,
                    'tspu' => $tspu,
                    'connection' => $this->serialize($connection->fresh()->loadCount('configs')),
                ], 422);
            }
        }

        try {
            $result = $this->pingService->pingConnection($connection);
        } catch (PingSessionBusyException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'ping_busy',
            ], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $tspu = $this->tspu->probe($probeOutbound, (bool) $result['ok']);

        $connection->last_tested_at = now();
        $connection->last_test_ok = $result['ok'];
        $connection->last_latency_ms = $result['latency_ms'];
        $connection->last_tspu_status = $tspu['status'];
        $connection->last_tspu_likely = $tspu['tspu_likely'];
        $connection->last_tspu_detail = $tspu['detail'];
        $connection->last_tspu_meta = $tspu;

        $error = $result['ok'] ? null : ($result['error'] ?? __('api.error'));
        if (! $result['ok'] && $tspu['tspu_likely']) {
            $error = __('resolver.tspu_prefix', ['detail' => $tspu['detail'] ?: __('resolver.tspu_dpi_likely')]);
        } elseif (! $result['ok'] && $tspu['status'] !== 'ok' && $tspu['status'] !== 'skipped') {
            $error = ($error ? $error.' · ' : '').$tspu['detail'];
        }
        $connection->last_test_error = $error;
        $connection->save();

        $traffic = $this->resolver->trafficByOutboundTag();

        return response()->json([
            'ok' => $result['ok'],
            'latency_ms' => $result['latency_ms'],
            'error' => $error,
            'tspu' => $tspu,
            'connection' => $this->serialize(
                $connection->fresh()->loadCount('configs'),
                $traffic[$connection->outboundTag()] ?? null
            ),
        ], $result['ok'] ? 200 : 422);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $kind = $data['kind'] ?? ResolverConnection::KIND_PROXY;

        if ($kind === ResolverConnection::KIND_SUBSCRIPTION) {
            $conn = $this->createSubscriptionConnection($data);
        } else {
            $outbound = $this->parser->fromRequest(
                $data['config_type'],
                $data['share_url'] ?? null,
                $data['outbound_json'] ?? null,
            );

            $conn = ResolverConnection::query()->create([
                'name' => $data['name'],
                'comment' => $data['comment'] ?? null,
                'kind' => ResolverConnection::KIND_PROXY,
                'config_type' => $data['config_type'],
                'share_url' => $data['config_type'] === 'url' ? ($data['share_url'] ?? null) : null,
                'outbound' => $outbound,
                'enabled' => $data['enabled'] ?? true,
                'ping_check_interval_min' => (int) ($data['ping_check_interval_min'] ?? 5),
            ]);
        }

        $this->reloadIfNeeded();
        $this->syncPingProbe();

        return response()->json(['connection' => $this->serialize($conn->fresh()->loadCount('configs'))], 201);
    }

    public function update(Request $request, ResolverConnection $connection)
    {
        $data = $this->validatePayload($request, updating: true, connection: $connection);
        $singBoxBefore = $this->singBoxFingerprint->hash($connection);

        if (isset($data['name'])) {
            $connection->name = $data['name'];
        }
        if (array_key_exists('comment', $data)) {
            $connection->comment = $data['comment'];
        }
        if (isset($data['enabled'])) {
            $connection->enabled = $data['enabled'];
        }
        if (array_key_exists('ping_check_interval_min', $data)) {
            $connection->ping_check_interval_min = (int) $data['ping_check_interval_min'];
        }

        $kind = $data['kind'] ?? $connection->kind ?? ResolverConnection::KIND_PROXY;

        if ($kind === ResolverConnection::KIND_SUBSCRIPTION) {
            $this->applySubscriptionFields($connection, $data);
        } else {
            $connection->kind = ResolverConnection::KIND_PROXY;
            $connection->subscription_url = null;
            $connection->subscription_mode = null;
            $connection->subscription_selected = null;
            $connection->subscription_nodes = null;
            $connection->subscription_fetched_at = null;

            $configType = $data['config_type'] ?? $connection->config_type;
            $needsParse = isset($data['config_type'])
                || array_key_exists('share_url', $data)
                || array_key_exists('outbound_json', $data);

            if ($needsParse) {
                $shareUrl = $data['share_url'] ?? $connection->share_url;
                $outboundJson = $data['outbound_json'] ?? json_encode($connection->outbound, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $outbound = $this->parser->fromRequest($configType, $shareUrl, is_string($outboundJson) ? $outboundJson : json_encode($outboundJson));
                $connection->config_type = $configType;
                $connection->share_url = $configType === 'url' ? $shareUrl : null;
                $connection->outbound = $outbound;
            }
        }

        $connection->save();

        if ($connection->isSubscription()) {
            $this->pingService->syncActivePickAfterPing($connection->fresh());
        }

        $singBoxReloaded = false;
        if ($this->singBoxFingerprint->hash($connection) !== $singBoxBefore) {
            $this->reloadIfNeeded();
            $this->syncPingProbe();
            $singBoxReloaded = true;
        }

        return response()->json([
            'connection' => $this->serialize($connection->fresh()->loadCount('configs')),
            'singbox_reloaded' => $singBoxReloaded,
        ]);
    }

    public function destroy(ResolverConnection $connection)
    {
        $refs = $connection->configs()->count();
        if ($refs > 0) {
            throw ValidationException::withMessages([
                'connection' => [__('resolver.cannot_delete_in_use', ['refs' => $refs])],
            ]);
        }

        $connection->delete();
        $this->reloadIfNeeded();
        $this->syncPingProbe();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $updating = false, ?ResolverConnection $connection = null): array
    {
        $nameRule = $updating ? ['sometimes', 'string', 'max:128'] : ['required', 'string', 'max:128'];
        $kind = $request->input('kind');
        if ($kind === null && $updating && $connection) {
            $kind = $connection->kind ?? ResolverConnection::KIND_PROXY;
        }
        if ($kind === null) {
            $kind = ResolverConnection::KIND_PROXY;
        }

        $rules = [
            'name' => $nameRule,
            'comment' => ['nullable', 'string', 'max:2000'],
            'kind' => [$updating ? 'sometimes' : 'required', Rule::in([ResolverConnection::KIND_PROXY, ResolverConnection::KIND_SUBSCRIPTION])],
            'enabled' => ['sometimes', 'boolean'],
            'refresh_subscription' => ['sometimes', 'boolean'],
            'ping_check_interval_min' => ['sometimes', 'integer', 'min:0', 'max:1440'],
        ];

        if ($kind === ResolverConnection::KIND_SUBSCRIPTION) {
            $rules['subscription_url'] = [$updating ? 'sometimes' : 'required', 'string', 'max:8000'];
            $rules['subscription_body'] = ['nullable', 'string', 'max:500000'];
            $rules['subscription_mode'] = [$updating ? 'sometimes' : 'required', Rule::in([ResolverConnection::MODE_SINGLE, ResolverConnection::MODE_URLTEST])];
            $rules['subscription_selected'] = ['nullable', 'string', 'max:64'];
        } else {
            $rules['config_type'] = [$updating ? 'sometimes' : 'required', Rule::in(['url', 'json'])];
            $rules['share_url'] = ['nullable', 'string', 'max:8000'];
            $rules['outbound_json'] = ['nullable', 'string', 'max:50000'];
        }

        return $request->validate($rules);
    }

    /** @param  array<string, mixed>  $data */
    private function createSubscriptionConnection(array $data): ResolverConnection
    {
        $nodes = $this->fetchSubscriptionNodes(
            $data['subscription_url'],
            $data['subscription_body'] ?? null,
        );
        $mode = $data['subscription_mode'];
        $selected = $data['subscription_selected'] ?? null;
        $outbound = $this->outboundForSubscription($nodes, $mode, $selected);

        return ResolverConnection::query()->create([
            'name' => $data['name'],
            'comment' => $data['comment'] ?? null,
            'kind' => ResolverConnection::KIND_SUBSCRIPTION,
            'config_type' => 'url',
            'share_url' => null,
            'subscription_url' => $data['subscription_url'],
            'subscription_mode' => $mode,
            'subscription_selected' => $mode === ResolverConnection::MODE_SINGLE ? $selected : null,
            'subscription_nodes' => $nodes,
            'subscription_fetched_at' => now(),
            'outbound' => $outbound,
            'enabled' => $data['enabled'] ?? true,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function applySubscriptionFields(ResolverConnection $connection, array $data): void
    {
        $connection->kind = ResolverConnection::KIND_SUBSCRIPTION;
        $connection->config_type = 'url';
        $connection->share_url = null;

        $url = $data['subscription_url'] ?? $connection->subscription_url;
        if ($url === null || $url === '') {
            throw ValidationException::withMessages([
                'subscription_url' => [__('resolver.subscription_url_required')],
            ]);
        }

        $mode = $data['subscription_mode'] ?? $connection->subscription_mode;
        if (! in_array($mode, [ResolverConnection::MODE_SINGLE, ResolverConnection::MODE_URLTEST], true)) {
            throw ValidationException::withMessages([
                'subscription_mode' => [__('resolver.subscription_mode_required')],
            ]);
        }

        $urlChanged = isset($data['subscription_url']) && $data['subscription_url'] !== $connection->subscription_url;
        $forceRefresh = ! empty($data['refresh_subscription']);
        $bodyProvided = array_key_exists('subscription_body', $data) && trim((string) $data['subscription_body']) !== '';

        if ($urlChanged || $forceRefresh || $bodyProvided || empty($connection->subscription_nodes)) {
            $nodes = $this->fetchSubscriptionNodes(
                $url,
                $bodyProvided ? (string) $data['subscription_body'] : null,
            );
            $connection->subscription_url = $url;
            if (! $this->singBoxFingerprint->nodesEqual($connection->subscription_nodes, $nodes)) {
                $connection->subscription_nodes = $nodes;
            }
            $connection->subscription_fetched_at = now();
        } else {
            $nodes = $connection->subscription_nodes ?? [];
        }

        $selected = array_key_exists('subscription_selected', $data)
            ? $data['subscription_selected']
            : $connection->subscription_selected;

        $connection->subscription_mode = $mode;
        $connection->subscription_selected = $mode === ResolverConnection::MODE_SINGLE ? $selected : null;
        $connection->outbound = $this->outboundForSubscription($nodes, $mode, $selected);
    }

    /** @return list<array<string, mixed>> */
    private function fetchSubscriptionNodes(string $url, ?string $body = null): array
    {
        try {
            return $this->subscriptionFetcher->fetchMerged($url, $body);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'subscription_url' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private function outboundForSubscription(array $nodes, string $mode, ?string $selected): array
    {
        if ($mode === ResolverConnection::MODE_URLTEST) {
            return ['type' => 'urltest'];
        }

        if ($selected === null || $selected === '') {
            throw ValidationException::withMessages([
                'subscription_selected' => [__('resolver.select_subscription_location')],
            ]);
        }

        foreach ($nodes as $node) {
            if (($node['key'] ?? null) === $selected) {
                return is_array($node['outbound'] ?? null) ? $node['outbound'] : [];
            }
        }

        throw ValidationException::withMessages([
            'subscription_selected' => [__('resolver.subscription_location_not_found')],
        ]);
    }

    /**
     * @param  array{key: string, latency_ms: ?int, ok: bool, error: ?string, source?: string}  $r
     * @return array{key: string, latency_ms: ?int, latency_ok: bool, latency_error: ?string, latency_source: ?string}
     */
    private function formatPingResult(array $r): array
    {
        return [
            'key' => (string) $r['key'],
            'latency_ms' => $r['latency_ms'],
            'latency_ok' => (bool) $r['ok'],
            'latency_error' => $r['error'],
            'latency_source' => $r['source'] ?? 'proxy',
        ];
    }

    private function streamPingNodesResponse(ResolverConnection $connection, bool $fastOnly = false, bool $autoApply = false): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $nodes = is_array($connection->subscription_nodes) ? $connection->subscription_nodes : [];
        $total = count($nodes);
        $pingLimit = 80;
        $truncated = $total > $pingLimit;

        return response()->stream(function () use ($connection, $total, $pingLimit, $truncated, $fastOnly, $autoApply) {
            @ignore_user_abort(true);

            echo json_encode([
                'type' => 'start',
                'count' => min($total, $pingLimit),
                'total' => $total,
                'truncated' => $truncated,
            ], JSON_UNESCAPED_UNICODE)."\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            try {
                $this->pingService->pingNodes($connection, function (array $result) {
                    echo json_encode([
                        'type' => 'result',
                        'key' => $result['key'],
                        'latency_ms' => $result['latency_ms'],
                        'latency_ok' => $result['ok'],
                        'latency_error' => $result['error'],
                        'latency_source' => $result['source'] ?? 'proxy',
                    ], JSON_UNESCAPED_UNICODE)."\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }, $fastOnly);

                if ($autoApply) {
                    $switch = $this->pingService->applyBestPickIfChanged($connection->fresh());
                    if ($switch['switched'] ?? false) {
                        $this->reloadIfNeeded();
                        $this->syncPingProbe();
                    }
                    $this->pingService->syncActivePickAfterPing($connection->fresh());
                    echo json_encode([
                        'type' => 'switch',
                        'switched' => (bool) ($switch['switched'] ?? false),
                        'pick_name' => $switch['pick']['name'] ?? null,
                        'pick_key' => $switch['pick']['key'] ?? null,
                        'pick_latency_ms' => $switch['pick']['latency_ms'] ?? null,
                        'reason' => $switch['reason'] ?? null,
                    ], JSON_UNESCAPED_UNICODE)."\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                } else {
                    $this->pingService->syncActivePickAfterPing($connection->fresh());
                }
            } catch (\Throwable $e) {
                echo json_encode([
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            echo json_encode([
                'type' => 'done',
                'tested' => min($total, $pingLimit),
                'truncated' => $truncated,
            ], JSON_UNESCAPED_UNICODE)."\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, count: int, tested: int, truncated: bool}
     */
    private function pingNodesResponse(ResolverConnection $connection, bool $fastOnly = false): array
    {
        $nodes = is_array($connection->subscription_nodes) ? $connection->subscription_nodes : [];
        $total = count($nodes);
        $pingLimit = 80;

        $latencies = $this->pingService->pingNodes($connection, null, $fastOnly);
        $this->pingService->syncActivePickAfterPing($connection->fresh());
        $latByKey = collect($latencies)->keyBy('key');
        $public = $this->publicNodes($nodes, $latByKey);
        $public = $this->sortNodesByLatency($public);

        return [
            'nodes' => $public,
            'count' => $total,
            'tested' => min($total, $pingLimit),
            'truncated' => $total > $pingLimit,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function publicNodesList(array $nodes, ?ResolverConnection $conn = null, ?string $activeKey = null, ?string $activeSource = null): array
    {
        $cached = $conn ? $this->pingService->readCachedLatencies($conn) : null;

        $mapped = array_map(function (array $node) use ($cached, $activeKey, $activeSource) {
            $key = (string) ($node['key'] ?? '');
            $lat = $cached[$key] ?? null;
            $isActive = $activeKey !== null && $activeKey === $key;

            return [
                'key' => $key,
                'name' => $node['name'] ?? '',
                'type' => $node['type'] ?? '',
                'server' => $node['server'] ?? '',
                'port' => (int) ($node['port'] ?? 0),
                'latency_ms' => $lat['latency_ms'] ?? null,
                'latency_ok' => (bool) ($lat['latency_ok'] ?? false),
                'latency_error' => $lat['latency_error'] ?? null,
                'latency_source' => $lat['source'] ?? null,
                'latency_tested_at' => $lat['tested_at'] ?? null,
                'is_active' => $isActive,
                'is_best_pick' => $isActive,
                'active_source' => $isActive ? $activeSource : null,
            ];
        }, $nodes);

        return $this->sortNodesByLatency($mapped);
    }

    /**
     * @return array{
     *     subscription_pick_name: ?string,
     *     subscription_pick_key: ?string,
     *     subscription_pick_latency_ms: ?int,
     *     subscription_pick_source: ?string
     * }
     */
    private function subscriptionPickInfo(ResolverConnection $c): array
    {
        $empty = [
            'subscription_pick_name' => null,
            'subscription_pick_key' => null,
            'subscription_pick_latency_ms' => null,
            'subscription_pick_source' => null,
        ];

        if (! $c->isSubscription()) {
            return $empty;
        }

        if ($c->subscription_mode === ResolverConnection::MODE_SINGLE) {
            $key = (string) ($c->subscription_selected ?? '');
            if ($key !== '') {
                $pick = $this->singleModePick($c, $key);
                if ($pick !== null) {
                    return $this->pickToResponse($pick, 'user');
                }
            }

            $persisted = $this->pingService->readPersistedActivePick($c);

            return $persisted !== null
                ? $this->pickToResponse($persisted, $persisted['source'])
                : $empty;
        }

        $persisted = $this->pingService->readPersistedActivePick($c);
        if ($persisted !== null) {
            return $this->pickToResponse($persisted, $persisted['source']);
        }

        $live = $this->pingService->resolveUrltestActivePick($c);
        if ($live !== null) {
            $this->pingService->persistActivePick($c, $live, 'urltest');

            return $this->pickToResponse($live, 'urltest');
        }

        $best = $this->pingService->bestPingFromCache($c);
        if ($best !== null) {
            return $this->pickToResponse($best, 'cached');
        }

        return $empty;
    }

    /**
     * @return array{key: string, name: string, latency_ms: ?int}|null
     */
    private function singleModePick(ResolverConnection $c, string $key): ?array
    {
        $cached = $this->pingService->readCachedLatencies($c);
        foreach (is_array($c->subscription_nodes) ? $c->subscription_nodes : [] as $node) {
            if (! is_array($node) || ($node['key'] ?? '') !== $key) {
                continue;
            }

            $lat = is_array($cached[$key] ?? null) ? $cached[$key]['latency_ms'] : null;

            return [
                'key' => $key,
                'name' => (string) ($node['name'] ?? $key),
                'latency_ms' => is_numeric($lat) ? (int) $lat : null,
            ];
        }

        return null;
    }

    /**
     * @param  array{key: string, name: string, latency_ms: ?int}  $pick
     * @return array{
     *     subscription_pick_name: string,
     *     subscription_pick_key: string,
     *     subscription_pick_latency_ms: ?int,
     *     subscription_pick_source: string
     * }
     */
    private function pickToResponse(array $pick, string $source): array
    {
        return [
            'subscription_pick_name' => $pick['name'],
            'subscription_pick_key' => $pick['key'],
            'subscription_pick_latency_ms' => $pick['latency_ms'] ?? null,
            'subscription_pick_source' => $source,
        ];
    }

    /**
     * @param  array{subscription_pick_key?: ?string}  $pick
     */
    private function activeNodeKeyForList(ResolverConnection $c, array $pick): ?string
    {
        if ($c->subscription_mode === ResolverConnection::MODE_SINGLE) {
            $selected = (string) ($c->subscription_selected ?? '');

            return $selected !== '' ? $selected : ($pick['subscription_pick_key'] ?? null);
        }

        return $pick['subscription_pick_key'] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function sortNodesByLatency(array $nodes): array
    {
        usort($nodes, function (array $a, array $b): int {
            $tier = function (array $n): int {
                if ($n['latency_ok'] ?? false) {
                    return 0;
                }
                if (! ($n['latency_ok'] ?? true) && ($n['latency_error'] ?? null)) {
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

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>|null  $latByKey
     * @return list<array<string, mixed>>
     */
    private function publicNodes(array $nodes, $latByKey = null): array
    {
        return array_map(function (array $node) use ($latByKey) {
            $lat = $latByKey?->get($node['key'], []);

            return [
                'key' => $node['key'],
                'name' => $node['name'],
                'type' => $node['type'],
                'server' => $node['server'],
                'port' => $node['port'],
                'latency_ms' => $lat['latency_ms'] ?? null,
                'latency_ok' => (bool) ($lat['ok'] ?? false),
                'latency_error' => $lat['error'] ?? null,
                'latency_source' => $lat['source'] ?? null,
            ];
        }, $nodes);
    }

    private function reloadIfNeeded(): void
    {
        try {
            $this->resolver->apply(refreshSubscriptions: false);
        } catch (\Throwable) {
            // status will show error
        }
    }

    private function syncPingProbe(): void
    {
        try {
            $this->pingProbeSync->rebuildAndMaybeReload();
        } catch (\Throwable) {
            // probe config will rebuild on next ping
        }
    }

    /**
     * @param  array{rx?: int, tx?: int, active?: bool}|null  $traffic
     * @return array<string, mixed>
     */
    private function serialize(ResolverConnection $c, ?array $traffic = null): array
    {
        $rx = (int) ($traffic['rx'] ?? 0);
        $tx = (int) ($traffic['tx'] ?? 0);

        $online = $c->last_tested_at !== null ? (bool) $c->last_test_ok : null;

        $kind = $c->kind ?? ResolverConnection::KIND_PROXY;
        $nodes = is_array($c->subscription_nodes) ? $c->subscription_nodes : [];
        $selectedName = null;
        if ($kind === ResolverConnection::KIND_SUBSCRIPTION && $c->subscription_mode === ResolverConnection::MODE_SINGLE) {
            foreach ($nodes as $node) {
                if (($node['key'] ?? null) === $c->subscription_selected) {
                    $selectedName = $node['name'] ?? null;
                    break;
                }
            }
        }

        $pick = $this->subscriptionPickInfo($c);
        $activeKey = $this->activeNodeKeyForList($c, $pick);

        return [
            'id' => $c->id,
            'name' => $c->name,
            'comment' => $c->comment,
            'kind' => $kind,
            'config_type' => $c->config_type,
            'share_url' => $c->share_url,
            'subscription_url' => $c->subscription_url,
            'subscription_mode' => $c->subscription_mode,
            'subscription_selected' => $c->subscription_selected,
            'subscription_selected_name' => $selectedName,
            'subscription_nodes' => $this->publicNodesList($nodes, $c, $activeKey, $pick['subscription_pick_source'] ?? null),
            'subscription_nodes_count' => count($nodes),
            'subscription_fetched_at' => optional($c->subscription_fetched_at)?->toIso8601String(),
            ...$pick,
            'ping_check_interval_min' => $c->pingCheckIntervalMin(),
            'ping_last_checked_at' => optional($c->ping_last_checked_at)?->toIso8601String(),
            'outbound' => $c->outbound,
            'outbound_type' => $c->outbound['type'] ?? null,
            'tag' => $c->outboundTag(),
            'enabled' => (bool) $c->enabled,
            'configs_count' => $c->configs_count ?? $c->configs()->count(),
            'rx' => $rx,
            'tx' => $tx,
            'online' => $online,
            'latency_ms' => $c->last_latency_ms,
            'last_tested_at' => optional($c->last_tested_at)?->toIso8601String(),
            'last_test_ok' => $c->last_test_ok,
            'last_test_error' => $c->last_test_error,
            'tspu' => [
                'status' => $c->last_tspu_status,
                'likely' => $c->last_tspu_likely,
                'detail' => $c->last_tspu_detail,
                'block_step' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['block_step'] ?? null) : null,
                'control_ok' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['control_ok'] ?? null) : null,
                'tcp_ok' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['tcp_ok'] ?? null) : null,
                'tls_response' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['tls_response'] ?? null) : null,
                'proxy_ok' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['proxy_ok'] ?? null) : null,
                'server' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['server'] ?? null) : null,
                'ip' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['ip'] ?? null) : null,
                'port' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['port'] ?? null) : null,
                'sni' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['sni'] ?? null) : null,
                'chain' => is_array($c->last_tspu_meta) ? ($c->last_tspu_meta['chain'] ?? []) : [],
            ],
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}
