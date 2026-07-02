<?php

namespace App\Models;

use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TreeMembership extends Model
{
    use RecordsChanges;

    public const ROLES = [
        'owner' => 'Владелец',
        'moderator' => 'Администратор-модератор',
        'member' => 'Член семьи',
        'guest' => 'Гость',
    ];

    protected $fillable = [
        'tree_id',
        'user_id',
        'person_id',
        'role',
        'status',
        'permissions',
        'approved_by_user_id',
        'approved_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'approved_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TreeMembership $membership): void {
            $tree = FamilyTree::query()->find($membership->tree_id);
            if ($membership->role === 'owner' && (int) $tree?->owner_user_id !== (int) $membership->user_id) {
                throw ValidationException::withMessages([
                    'role' => 'Роль владельца назначается только передачей владения деревом.',
                ]);
            }
            if (
                (int) $tree?->owner_user_id === (int) $membership->user_id
                && ($membership->role !== 'owner' || $membership->status !== 'approved')
            ) {
                throw ValidationException::withMessages([
                    'role' => 'Владельца дерева нельзя понизить или заблокировать.',
                ]);
            }

            if (! $membership->person_id) {
                return;
            }

            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($membership->person_id)
                ->value('tree_id');

            if ((int) $personTreeId !== (int) $membership->tree_id) {
                throw ValidationException::withMessages([
                    'person_id' => 'Карточка человека должна находиться в том же дереве.',
                ]);
            }
        });

        static::creating(function (TreeMembership $membership): void {
            $tree = FamilyTree::query()->with('plan')->find($membership->tree_id);
            $limit = (int) ($tree?->plan?->member_limit ?? 0);

            if ($limit > 0 && static::query()->where('tree_id', $tree->id)->count() >= $limit) {
                throw ValidationException::withMessages([
                    'user_id' => 'Достигнут лимит участников для текущего тарифа.',
                ]);
            }
        });
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function canManageTree(): bool
    {
        return $this->status === 'approved'
            && in_array($this->role, ['owner', 'moderator'], true);
    }

    public function canEditFamily(): bool
    {
        return $this->status === 'approved'
            && in_array($this->role, ['owner', 'moderator', 'member'], true);
    }
}
