<?php

namespace App\Models;

use App\Support\CurrentTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUser extends Model
{
    protected $fillable = [
        'person_id',
        'user_id',
        'current_tree_id',
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'photo_url',
        'status',
        'pending_command',
        'mini_app_action',
        'is_bot_admin',
        'last_seen_at',
        'last_web_login_at',
    ];

    protected function casts(): array
    {
        return [
            'mini_app_action' => 'array',
            'is_bot_admin' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_web_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TelegramUser $user): void {
            $user->current_tree_id ??= app(CurrentTree::class)->id();
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentTree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'current_tree_id');
    }

    public function membership(?int $treeId = null): ?TreeMembership
    {
        return $this->user?->memberships()
            ->where('tree_id', $treeId ?: $this->current_tree_id)
            ->first();
    }

    public function getDisplayNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name)
            ?: ($this->username ? '@'.$this->username : (string) $this->telegram_user_id);
    }

    public function isApproved(): bool
    {
        $treeId = app(CurrentTree::class)->id() ?: $this->current_tree_id;
        $membership = $treeId ? $this->membership($treeId) : null;

        return $membership
            ? $membership->status === 'approved'
            : $this->status === 'approved';
    }
}
