<?php

namespace App\Services;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Cache;

class TreeCacheService
{
    public function version(int $treeId): int
    {
        return (int) Cache::get("family-tree-version:{$treeId}", 1);
    }

    public function bump(?int $treeId): void
    {
        if (! $treeId) {
            return;
        }
        Cache::add("family-tree-version:{$treeId}", 1, now()->addYear());
        Cache::increment("family-tree-version:{$treeId}");
    }

    public function remember(int $treeId, string $segment, callable $callback, int $seconds = 300): mixed
    {
        $version = $this->version($treeId);

        return Cache::remember(
            "family-tree-data:{$treeId}:{$version}:{$segment}",
            $seconds,
            function () use ($callback): mixed {
                $value = $callback();

                // Persistent cache must not contain framework objects: after a
                // deployment PHP can restore them as __PHP_Incomplete_Class.
                return $value instanceof Arrayable ? $value->toArray() : $value;
            },
        );
    }
}
