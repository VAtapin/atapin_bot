<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class FamilyEvent extends Model
{
    use BelongsToTree;
    use RecordsChanges;

    protected $fillable = [
        'tree_id',
        'person_id',
        'type',
        'title',
        'description',
        'event_date',
        'event_time',
        'place',
        'is_annual',
        'is_published',
        'reminder_minutes',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'is_annual' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (FamilyEvent $event): void {
            if (! $event->person_id) {
                return;
            }

            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($event->person_id)
                ->value('tree_id');

            if (! $personTreeId || ($event->tree_id && (int) $event->tree_id !== (int) $personTreeId)) {
                throw ValidationException::withMessages([
                    'person_id' => 'Событие и человек должны находиться в одном дереве.',
                ]);
            }

            $event->tree_id = $personTreeId;
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
