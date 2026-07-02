<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ParentChild extends Model
{
    use BelongsToTree;
    use RecordsChanges;

    protected $fillable = ['tree_id', 'parent_id', 'child_id', 'type', 'notes'];

    protected static function booted(): void
    {
        static::saving(function (ParentChild $link): void {
            if ((int) $link->parent_id === (int) $link->child_id) {
                throw ValidationException::withMessages([
                    'child_id' => 'Человек не может быть родителем самому себе.',
                ]);
            }

            $parentTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($link->parent_id)
                ->value('tree_id');
            $childTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($link->child_id)
                ->value('tree_id');

            if (! $parentTreeId || $parentTreeId !== $childTreeId) {
                throw ValidationException::withMessages([
                    'child_id' => 'Родитель и ребёнок должны находиться в одном дереве.',
                ]);
            }

            $link->tree_id = $parentTreeId;
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
