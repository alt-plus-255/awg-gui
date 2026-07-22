<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AwgConfig extends Model
{
    protected $fillable = [
        'name',
        'type',
        'vn_policy',
        'vn_zones',
        'iface',
        'listen_port',
        'internal_subnet',
        'server_address',
        'server_private_key',
        'server_public_key',
        'peer_dns',
        'resolver_dns',
        'client_allowed_ips',
        'persistent_keepalive',
        'enabled',
        'resolver_enabled',
        'resolver_reject_quic',
        'community_lists',
        'user_domains',
        'user_subnets',
        'resolver_updated_at',
        'resolver_last_error',
        'connection_id',
        'jc', 'jmin', 'jmax',
        's1', 's2', 's3', 's4',
        'h1', 'h2', 'h3', 'h4',
        'i1', 'i2', 'i3', 'i4', 'i5',
    ];

    protected $hidden = [
        'server_private_key',
    ];

    protected function casts(): array
    {
        return [
            'listen_port' => 'integer',
            'persistent_keepalive' => 'integer',
            'enabled' => 'boolean',
            'vn_zones' => 'array',
            'resolver_enabled' => 'boolean',
            'resolver_reject_quic' => 'boolean',
            'community_lists' => 'array',
            'user_domains' => 'array',
            'user_subnets' => 'array',
            'resolver_updated_at' => 'datetime',
            'connection_id' => 'integer',
        ];
    }

    public function peers(): HasMany
    {
        return $this->hasMany(AwgConfigPeer::class);
    }

    public function resolverConnection(): BelongsTo
    {
        return $this->belongsTo(ResolverConnection::class, 'connection_id');
    }

    public function isVirtualNetwork(): bool
    {
        return $this->type === 'virtual_network';
    }

    public function isResolverEnabled(): bool
    {
        return $this->type === 'server' && (bool) $this->resolver_enabled;
    }

}
