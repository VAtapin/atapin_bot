<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    protected $fillable = [
        'telegram_update_id',
        'chat_id',
        'telegram_user_id',
        'update_type',
        'payload',
        'processed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
