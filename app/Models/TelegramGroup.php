<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramGroup extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'tree_id',
        'title',
        'timezone',
        'birthday_notification_hour',
        'notify_birthdays',
        'is_active',
        'birthday_last_sent_on',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'notify_birthdays' => 'boolean',
            'is_active' => 'boolean',
            'birthday_last_sent_on' => 'date',
            'last_seen_at' => 'datetime',
        ];
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }
}
