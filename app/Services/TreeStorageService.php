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
        $publicBytes = (int) collect(Storage::disk('public')->allFiles("trees/{$tree->id}"))
            ->sum(fn (string $path): int => Storage::disk('public')->size($path));
        $backupBytes = $this->backupBytes($tree);
        $bytes = $publicBytes + $backupBytes;
        $tree->updateQuietly(['storage_used_bytes' => $bytes]);
        $limit = (int) ($tree->plan?->storage_limit_bytes ?? 536870912);
        if (
            $limit > 0
            && $bytes / $limit >= 0.8
            && Cache::add("storage-warning:{$tree->id}", true, now()->addDay())
        ) {
            app(FamilyNotificationService::class)->notifyManagers(
                $tree,
                $this->storageWarningText($tree, $bytes, $limit, $publicBytes, $backupBytes),
            );
        }

        return $bytes;
    }

    public function backupBytes(FamilyTree $tree): int
    {
        $directory = "tree-backups/{$tree->id}";

        if (! Storage::disk('local')->exists($directory)) {
            return 0;
        }

        return (int) collect(Storage::disk('local')->allFiles($directory))
            ->sum(fn (string $path): int => Storage::disk('local')->size($path));
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

    private function storageWarningText(FamilyTree $tree, int $used, int $limit, int $publicBytes, int $backupBytes): string
    {
        $percent = $limit > 0 ? round($used / $limit * 100) : 0;
        $advice = $backupBytes > $publicBytes
            ? 'Совет: удалите старые резервные копии, уменьшите максимум копий или делайте автобэкап реже.'
            : 'Совет: удалите лишние фотографии/дубли или перейдите на тариф с большим хранилищем.';

        return "⚠️ <b>Место в семейном архиве почти закончилось</b>\n\n"
            .'Дерево: <b>'.e($tree->name)."</b>\n"
            .'Использовано: '.e($this->formatBytes($used)).' из '.e($this->formatBytes($limit))." ({$percent}%)\n"
            .'Файлы и фото: '.e($this->formatBytes($publicBytes))."\n"
            .'Резервные копии: '.e($this->formatBytes($backupBytes))."\n\n"
            .e($advice);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', ' ').' ГБ';
        }

        return number_format($bytes / 1048576, 1, ',', ' ').' МБ';
    }
}
