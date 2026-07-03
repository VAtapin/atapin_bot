<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\PersonPhoto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TreeStorageService
{
    public function recalculate(FamilyTree $tree): int
    {
        PersonPhoto::query()
            ->withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->where('file_size', 0)
            ->whereNotNull('path')
            ->get()
            ->each(function (PersonPhoto $photo): void {
                if (Storage::disk('public')->exists($photo->path)) {
                    $photo->updateQuietly([
                        'file_size' => Storage::disk('public')->size($photo->path),
                    ]);
                }
            });
        $bytes = (int) collect(Storage::disk('public')->allFiles("trees/{$tree->id}"))
            ->sum(fn (string $path): int => Storage::disk('public')->size($path));
        $tree->updateQuietly(['storage_used_bytes' => $bytes]);
        $limit = (int) ($tree->plan?->storage_limit_bytes ?? 536870912);
        if (
            $limit > 0
            && $bytes / $limit >= 0.8
            && Cache::add("storage-warning:{$tree->id}", true, now()->addDay())
        ) {
            app(FamilyNotificationService::class)->notifyManagers(
                $tree,
                '⚠️ Семейный архив использовал более 80% доступного места.',
            );
        }

        return $bytes;
    }

    public function ensureCanStore(FamilyTree $tree, int $additionalBytes): void
    {
        $used = $this->recalculate($tree);
        $limit = (int) ($tree->plan?->storage_limit_bytes ?? 536870912);

        if ($used + $additionalBytes > $limit) {
            throw ValidationException::withMessages([
                'photo' => 'Недостаточно места в семейном архиве. Освободите место или измените тариф.',
            ]);
        }
    }
}
