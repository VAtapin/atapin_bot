<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use App\Support\CurrentTree;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TelegramGroup extends Model
{
    use RecordsChanges;

    protected static function booted(): void
    {
        static::creating(function (TelegramGroup $group): void {
            $treeId = app(CurrentTree::class)->id();
            if ($treeId && $group->tree_id && (int) $group->tree_id !== $treeId) {
                throw new LogicException('Группа Telegram не относится к выбранному дереву.');
            }
            $group->tree_id ??= $treeId;

            if (! $group->tree_id) {
                throw new LogicException('Группу Telegram нельзя создать без выбранного дерева.');
            }
        });

        static::updating(function (TelegramGroup $group): void {
            if ($group->isDirty('tree_id')) {
                throw new LogicException('Перенос группы Telegram между деревьями запрещён.');
            }
        });
    }

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
