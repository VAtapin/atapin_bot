<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    protected $fillable = ['key', 'status', 'last_seen_at', 'meta', 'last_error'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
