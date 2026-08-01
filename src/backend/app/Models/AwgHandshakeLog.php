<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AwgHandshakeLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'awg_config_id',
        'awg_config_peer_id',
        'vpn_client_id',
        'public_key',
        'endpoint',
        'handshake_at',
        'byte_size',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'awg_config_id' => 'integer',
            'awg_config_peer_id' => 'integer',
            'vpn_client_id' => 'integer',
            'handshake_at' => 'integer',
            'byte_size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(AwgConfig::class, 'awg_config_id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(AwgConfigPeer::class, 'awg_config_peer_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(VpnClient::class, 'vpn_client_id');
    }
}
