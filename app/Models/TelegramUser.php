<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUser extends Model
{
    protected $fillable = [
        'person_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'status',
        'is_bot_admin',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_bot_admin' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name)
            ?: ($this->username ? '@'.$this->username : (string) $this->telegram_user_id);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
