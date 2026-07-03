<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TreeInvitation extends Model
{
    protected $fillable = [
        'tree_id',
        'created_by_user_id',
        'person_id',
        'token_hash',
        'token_ciphertext',
        'label',
        'role',
        'max_uses',
        'uses_count',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $hidden = ['token_hash', 'token_ciphertext'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'token_ciphertext' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TreeInvitation $invitation): void {
            if (! $invitation->person_id) {
                return;
            }

            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($invitation->person_id)
                ->value('tree_id');

            if ((int) $personTreeId !== (int) $invitation->tree_id) {
                throw ValidationException::withMessages([
                    'person_id' => 'Приглашение нельзя привязать к человеку из другого дерева.',
                ]);
            }
        });
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function getInvitationUrlAttribute(): ?string
    {
        return $this->token_ciphertext
            ? route('tree.invitation', $this->token_ciphertext)
            : null;
    }

    public function isUsable(): bool
    {
        return ! $this->revoked_at
            && (! $this->expires_at || $this->expires_at->isFuture())
            && $this->uses_count < $this->max_uses;
    }
}
