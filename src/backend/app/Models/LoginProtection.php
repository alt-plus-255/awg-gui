<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginProtection extends Model
{
    protected $fillable = [
        'ip',
        'attempts',
        'lockout_count',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'lockout_count' => 'integer',
            'locked_until' => 'datetime',
        ];
    }
}
