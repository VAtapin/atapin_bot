<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ParentChild extends Model
{
    protected $fillable = ['parent_id', 'child_id', 'type', 'notes'];

    protected static function booted(): void
    {
        static::saving(function (ParentChild $link): void {
            if ((int) $link->parent_id === (int) $link->child_id) {
                throw ValidationException::withMessages([
                    'child_id' => 'Человек не может быть родителем самому себе.',
                ]);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'parent_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'child_id');
    }
}
