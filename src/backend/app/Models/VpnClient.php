<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnClient extends Model
{
    protected $table = 'vpn_clients';

    protected $fillable = [
        'name',
        'comment',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(AwgConfigPeer::class);
    }
}
