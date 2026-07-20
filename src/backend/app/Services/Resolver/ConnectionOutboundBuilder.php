<?php

namespace App\Services\Resolver;

use App\Models\ResolverConnection;

class ConnectionOutboundBuilder
{
    public const MAX_NODES_PER_SUBSCRIPTION = 80;

    public function __construct(private SingBoxOutboundParser $parser) {}

    /**
     * @return array{outbounds: list<array<string, mixed>>, tags_added: array<string, true>, truncated_subscriptions: array<int, bool>}
     */
    public function buildForConnections(iterable $connections): array
    {
        $outbounds = [
            [
                'type' => 'direct',
                'tag' => 'direct',
            ],
        ];
        $tagsAdded = ['direct' => true];
        $truncated = [];

        foreach ($connections as $conn) {
            if (! $conn instanceof ResolverConnection || ! $conn->enabled) {
                continue;
            }

            $tag = $conn->outboundTag();
            if (isset($tagsAdded[$tag])) {
                continue;
            }

            if ($conn->isUrltestMode()) {
                $nodes = is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [];
                $total = count($nodes);
                if ($total > self::MAX_NODES_PER_SUBSCRIPTION) {
                    $truncated[$conn->id] = true;
                    $nodes = array_slice($nodes, 0, self::MAX_NODES_PER_SUBSCRIPTION);
                }

                $childTags = [];
                $i = 1;
                foreach ($nodes as $node) {
                    if (! is_array($node)) {
                        continue;
                    }
                    $childTag = $conn->childOutboundTag($i);
                    if (isset($tagsAdded[$childTag])) {
                        $i++;

                        continue;
                    }
                    $ob = $node['outbound'] ?? [];
                    if (! is_array($ob) || empty($ob['type'])) {
                        continue;
                    }
                    $ob = $this->parser->normalize($ob);
                    unset($ob['tag']);
                    $ob['tag'] = $childTag;
                    $outbounds[] = $ob;
                    $tagsAdded[$childTag] = true;
                    $childTags[] = $childTag;
                    $i++;
                }

                if ($childTags !== []) {
                    $outbounds[] = [
                        'type' => 'urltest',
                        'tag' => $tag,
                        'outbounds' => $childTags,
                        'url' => ResolverService::DELAY_TEST_URL,
                        'interval' => $conn->urltestIntervalDuration(),
                    ];
                    $tagsAdded[$tag] = true;
                }

                continue;
            }

            $ob = $conn->outbound ?? [];
            if (! is_array($ob) || empty($ob['type'])) {
                continue;
            }
            if (($ob['type'] ?? '') === 'urltest') {
                continue;
            }
            $ob = $this->parser->normalize($ob);
            $ob['tag'] = $tag;
            $outbounds[] = $ob;
            $tagsAdded[$tag] = true;
        }

        return [
            'outbounds' => $outbounds,
            'tags_added' => $tagsAdded,
            'truncated_subscriptions' => $truncated,
        ];
    }

    public function resolveNodeTag(ResolverConnection $conn, string $nodeKey): ?string
    {
        if ($conn->isUrltestMode()) {
            $i = 1;
            foreach (is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [] as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (($node['key'] ?? '') === $nodeKey) {
                    if ($i > self::MAX_NODES_PER_SUBSCRIPTION) {
                        return null;
                    }

                    return $conn->childOutboundTag($i);
                }
                $i++;
            }

            return null;
        }

        if ($conn->isSubscription() && $conn->subscription_mode === ResolverConnection::MODE_SINGLE) {
            $selected = (string) ($conn->subscription_selected ?? '');
            if ($selected !== '' && $selected === $nodeKey) {
                return $conn->outboundTag();
            }

            return null;
        }

        return $conn->outboundTag();
    }

    /**
     * @return list<array{key: string, tag: string}>
     */
    public function pingableNodes(ResolverConnection $conn): array
    {
        if (! $conn->isSubscription()) {
            return [];
        }

        if ($conn->isUrltestMode()) {
            $out = [];
            $i = 1;
            foreach (is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [] as $node) {
                if ($i > self::MAX_NODES_PER_SUBSCRIPTION) {
                    break;
                }
                if (! is_array($node) || empty($node['key'])) {
                    $i++;

                    continue;
                }
                $out[] = [
                    'key' => (string) $node['key'],
                    'tag' => $conn->childOutboundTag($i),
                ];
                $i++;
            }

            return $out;
        }

        if ($conn->subscription_mode === ResolverConnection::MODE_SINGLE) {
            $selected = (string) ($conn->subscription_selected ?? '');
            if ($selected === '') {
                return [];
            }

            return [[
                'key' => $selected,
                'tag' => $conn->outboundTag(),
            ]];
        }

        return [];
    }
}
