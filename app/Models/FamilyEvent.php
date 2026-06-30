<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyEvent extends Model
{
    protected $fillable = [
        'person_id',
        'type',
        'title',
        'description',
        'event_date',
        'event_time',
        'place',
        'is_annual',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'is_annual' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
