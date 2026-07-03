<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUpdate extends Model
{
    protected $fillable = [
        'bot_scope',
        'telegram_update_id',
        'tree_id',
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

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }
}
