<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AwgConfigPeer extends Model
{
    protected $fillable = [
        'awg_config_id',
        'vpn_client_id',
        'enabled',
        'private_key',
        'public_key',
        'preshared_key',
        'address',
        'extra_allowed_ips',
        'excluded_client_ids',
        'exclusions_mutual',
        'keepalive',
        'runtime_endpoint',
        'latest_handshake',
        'transfer_rx',
        'transfer_tx',
        'traffic_rx_total',
        'traffic_tx_total',
        'traffic_rx_baseline',
        'traffic_tx_baseline',
        'traffic_reset_at',
        'online',
        'stats_synced_at',
    ];

    protected $hidden = [
        'private_key',
        'preshared_key',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'keepalive' => 'integer',
            'extra_allowed_ips' => 'array',
            'excluded_client_ids' => 'array',
            'exclusions_mutual' => 'boolean',
            'latest_handshake' => 'integer',
            'transfer_rx' => 'integer',
            'transfer_tx' => 'integer',
            'traffic_rx_total' => 'integer',
            'traffic_tx_total' => 'integer',
            'traffic_rx_baseline' => 'integer',
            'traffic_tx_baseline' => 'integer',
            'traffic_reset_at' => 'datetime',
            'online' => 'boolean',
            'stats_synced_at' => 'datetime',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(AwgConfig::class, 'awg_config_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(VpnClient::class, 'vpn_client_id');
    }
}
