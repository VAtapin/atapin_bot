<?php

namespace App\Models\Concerns;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTree
{
    protected static function bootBelongsToTree(): void
    {
        static::addGlobalScope('family_tree', function (Builder $query): void {
            if ($treeId = app(CurrentTree::class)->id()) {
                $query->where($query->qualifyColumn('tree_id'), $treeId);

                return;
            }

            $query->whereRaw('1 = 0');
        });

        static::creating(function ($model): void {
            $treeId = app(CurrentTree::class)->id();

            if ($treeId) {
                if ($model->tree_id && (int) $model->tree_id !== $treeId) {
                    throw new LogicException('Семейная запись не относится к выбранному дереву.');
                }

                $model->tree_id = $treeId;
            }

            if (! $model->tree_id || (! $treeId && ! app()->runningInConsole())) {
                throw new LogicException('Семейную запись нельзя создать без выбранного дерева.');
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tree_id')) {
                throw new LogicException('Перенос семейной записи между деревьями запрещён.');
            }
        });
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }
}
