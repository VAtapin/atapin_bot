<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DataIssue extends Model
{
    protected $fillable = [
        'tree_id',
        'reported_by_user_id',
        'person_id',
        'status',
        'subject',
        'description',
        'resolution',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (DataIssue $issue): void {
            if (! $issue->person_id) {
                return;
            }

            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($issue->person_id)
                ->value('tree_id');

            if ((int) $personTreeId !== (int) $issue->tree_id) {
                throw ValidationException::withMessages([
                    'person_id' => 'Сообщение и человек должны относиться к одному дереву.',
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

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}
