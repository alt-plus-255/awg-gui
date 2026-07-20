<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;

/**
 * Canonical snapshot of resolver-connection fields that affect sing-box outbounds.
 */
class ResolverConnectionSingBoxFingerprint
{
    public function __construct(private SingBoxOutboundParser $parser) {}

    public function hash(ResolverConnection $conn): string
    {
        $payload = $this->payload($conn);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param  list<array<string, mixed>>|null  $a
     * @param  list<array<string, mixed>>|null  $b
     */
    public function nodesEqual(?array $a, ?array $b): bool
    {
        return $this->nodesPayload($a ?? []) === $this->nodesPayload($b ?? []);
    }

    /** @return array<string, mixed> */
    private function payload(ResolverConnection $conn): array
    {
        if (! $conn->enabled) {
            return [
                'enabled' => false,
                'id' => $conn->id,
            ];
        }

        $kind = $conn->kind ?? ResolverConnection::KIND_PROXY;
        if ($kind === ResolverConnection::KIND_SUBSCRIPTION) {
            return [
                'enabled' => true,
                'kind' => ResolverConnection::KIND_SUBSCRIPTION,
                'mode' => $conn->subscription_mode,
                'selected' => $conn->subscription_mode === ResolverConnection::MODE_SINGLE
                    ? $conn->subscription_selected
                    : null,
                'ping_interval' => $conn->pingCheckIntervalMin(),
                'nodes' => $this->nodesPayload(is_array($conn->subscription_nodes) ? $conn->subscription_nodes : []),
            ];
        }

        $outbound = is_array($conn->outbound) ? $conn->outbound : [];
        if (($outbound['type'] ?? '') === 'urltest') {
            $outbound = [];
        }

        return [
            'enabled' => true,
            'kind' => ResolverConnection::KIND_PROXY,
            'outbound' => $this->parser->normalize($outbound),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{key: string, outbound: array<string, mixed>}>
     */
    private function nodesPayload(array $nodes): array
    {
        $items = [];
        $limit = ConnectionOutboundBuilder::MAX_NODES_PER_SUBSCRIPTION;

        foreach (array_slice($nodes, 0, $limit) as $node) {
            if (! is_array($node)) {
                continue;
            }
            $outbound = $node['outbound'] ?? [];
            if (! is_array($outbound) || empty($outbound['type'])) {
                continue;
            }

            $items[] = [
                'key' => (string) ($node['key'] ?? ''),
                'outbound' => $this->parser->normalize($outbound),
            ];
        }

        return $items;
    }
}
