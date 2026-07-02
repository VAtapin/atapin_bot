<?php

namespace App\Models\Concerns;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTree
{
    protected static function bootBelongsToTree(): void
    {
        static::addGlobalScope('family_tree', function (Builder $query): void {
            if ($treeId = app(CurrentTree::class)->id()) {
                $query->where($query->qualifyColumn('tree_id'), $treeId);
            }
        });

        static::creating(function ($model): void {
            $treeId = app(CurrentTree::class)->id()
                ?: app(CurrentTree::class)->resolveDefault()?->id;
            if (! $model->tree_id && $treeId) {
                $model->tree_id = $treeId;
            }
        });
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }
}
