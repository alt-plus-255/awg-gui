<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResolverConnection extends Model
{
    public const KIND_PROXY = 'proxy';

    public const KIND_SUBSCRIPTION = 'subscription';

    public const MODE_SINGLE = 'single';

    public const MODE_URLTEST = 'urltest';

    protected $fillable = [
        'name',
        'comment',
        'kind',
        'config_type',
        'share_url',
        'subscription_url',
        'subscription_body',
        'subscription_mode',
        'subscription_selected',
        'subscription_nodes',
        'subscription_fetched_at',
        'outbound',
        'enabled',
        'last_latency_ms',
        'last_tested_at',
        'last_test_ok',
        'last_test_error',
        'last_tspu_status',
        'last_tspu_likely',
        'last_tspu_detail',
        'last_tspu_meta',
        'latency_cache',
        'subscription_active',
        'ping_check_interval_min',
        'ping_last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'outbound' => 'array',
            'subscription_nodes' => 'array',
            'enabled' => 'boolean',
            'last_latency_ms' => 'integer',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'last_tspu_likely' => 'boolean',
            'last_tspu_meta' => 'array',
            'subscription_fetched_at' => 'datetime',
            'latency_cache' => 'array',
            'subscription_active' => 'array',
            'ping_check_interval_min' => 'integer',
            'ping_last_checked_at' => 'datetime',
        ];
    }

    public function configs(): HasMany
    {
        return $this->hasMany(AwgConfig::class, 'connection_id');
    }

    public function outboundTag(): string
    {
        return 'conn_'.$this->id;
    }

    public function childOutboundTag(int $index): string
    {
        return $this->outboundTag().'_'.$index;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function nodeForChildTag(string $tag): ?array
    {
        $prefix = $this->outboundTag().'_';
        if (! str_starts_with($tag, $prefix)) {
            return null;
        }

        $index = (int) substr($tag, strlen($prefix));
        if ($index < 1) {
            return null;
        }

        $i = 0;
        foreach ($this->validSubscriptionNodes() as $node) {
            $i++;
            if ($i === $index) {
                return $node;
            }
        }

        return null;
    }

    public function isSubscription(): bool
    {
        return ($this->kind ?? self::KIND_PROXY) === self::KIND_SUBSCRIPTION;
    }

    public function isUrltestMode(): bool
    {
        return $this->isSubscription() && $this->subscription_mode === self::MODE_URLTEST;
    }

    /**
     * Subscription nodes that have a usable sing-box outbound.
     *
     * @return list<array<string, mixed>>
     */
    public function validSubscriptionNodes(): array
    {
        $out = [];
        foreach (is_array($this->subscription_nodes) ? $this->subscription_nodes : [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $ob = $node['outbound'] ?? [];
            if (! is_array($ob) || empty($ob['type'])) {
                continue;
            }
            $out[] = $node;
        }

        return $out;
    }

    public function childTagForNodeKey(string $nodeKey): ?string
    {
        $i = 0;
        foreach ($this->validSubscriptionNodes() as $node) {
            $i++;
            if ((string) ($node['key'] ?? '') === $nodeKey) {
                return $this->childOutboundTag($i);
            }
        }

        return null;
    }

    public function pingCheckIntervalMin(): int
    {
        $v = (int) ($this->ping_check_interval_min ?? 5);

        return max(0, min(1440, $v));
    }

    /** sing-box urltest interval, e.g. "5m" */
    public function urltestIntervalDuration(): string
    {
        $min = $this->pingCheckIntervalMin();

        return ($min > 0 ? $min : 5).'m';
    }

    public function isPingCheckDue(): bool
    {
        $interval = $this->pingCheckIntervalMin();
        if ($interval <= 0 || ! $this->enabled) {
            return false;
        }

        if ($this->ping_last_checked_at === null) {
            return true;
        }

        return $this->ping_last_checked_at->lte(now()->subMinutes($interval));
    }

    /** @return array<string, mixed> */
    public function tspuProbeOutbound(): array
    {
        if (! $this->isSubscription()) {
            return is_array($this->outbound) ? $this->outbound : [];
        }
        if ($this->subscription_mode === self::MODE_SINGLE) {
            return is_array($this->outbound) ? $this->outbound : [];
        }
        $nodes = $this->subscription_nodes ?? [];
        if (! is_array($nodes) || $nodes === []) {
            return [];
        }
        $first = $nodes[0];

        return is_array($first['outbound'] ?? null) ? $first['outbound'] : [];
    }
}
