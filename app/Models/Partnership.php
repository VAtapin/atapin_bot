<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Partnership extends Model
{
    use BelongsToTree;
    use RecordsChanges;

    protected $fillable = [
        'tree_id',
        'partner_one_id',
        'partner_two_id',
        'status',
        'started_at',
        'ended_at',
        'place',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'date',
            'ended_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Partnership $partnership): void {
            if ((int) $partnership->partner_one_id === (int) $partnership->partner_two_id) {
                throw ValidationException::withMessages([
                    'partner_two_id' => 'Выберите другого человека.',
                ]);
            }

            if ($partnership->partner_one_id > $partnership->partner_two_id) {
                [
                    $partnership->partner_one_id,
                    $partnership->partner_two_id,
                ] = [
                    $partnership->partner_two_id,
                    $partnership->partner_one_id,
                ];
            }

            $firstTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($partnership->partner_one_id)
                ->value('tree_id');
            $secondTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($partnership->partner_two_id)
                ->value('tree_id');

            if (! $firstTreeId || $firstTreeId !== $secondTreeId) {
                throw ValidationException::withMessages([
                    'partner_two_id' => 'Оба партнёра должны находиться в одном дереве.',
                ]);
            }

            $partnership->tree_id = $firstTreeId;
        });
    }

    public function partnerOne(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'partner_one_id');
    }

    public function partnerTwo(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'partner_two_id');
    }
}
