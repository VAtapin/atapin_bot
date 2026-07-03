<?php

namespace App\Observers;

use App\Services\TreeCacheService;

class FamilyCacheObserver
{
    public function saved(object $model): void
    {
        app(TreeCacheService::class)->bump((int) ($model->tree_id ?? 0));
    }

    public function deleted(object $model): void
    {
        app(TreeCacheService::class)->bump((int) ($model->tree_id ?? 0));
    }

    public function restored(object $model): void
    {
        app(TreeCacheService::class)->bump((int) ($model->tree_id ?? 0));
    }
}
