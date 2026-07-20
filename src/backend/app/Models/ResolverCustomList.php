<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResolverCustomList extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'domains',
        'cidrs',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'cidrs' => 'array',
        ];
    }

    public function isRemote(): bool
    {
        return is_string($this->source_url) && trim($this->source_url) !== '';
    }

    public static function makeSlug(int $id): string
    {
        return 'custom_'.$id;
    }
}
