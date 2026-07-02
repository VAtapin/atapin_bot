<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\TreeBackup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TreeArchiveService
{
    private const TABLES = [
        'people',
        'parent_children',
        'partnerships',
        'family_events',
        'photo_albums',
        'person_photos',
        'settings',
    ];

    public function create(FamilyTree $tree, ?User $actor = null, string $type = 'manual'): TreeBackup
    {
        $backup = TreeBackup::query()->create([
            'tree_id' => $tree->id,
            'created_by_user_id' => $actor?->id,
            'type' => $type,
            'status' => 'processing',
        ]);

        try {
            $directory = "tree-backups/{$tree->id}/{$backup->id}";
            $snapshot = [
                'format' => 'idommoy-tree-backup',
                'version' => 1,
                'created_at' => now()->toIso8601String(),
                'tree' => $tree->withoutRelations()->toArray(),
                'tables' => [],
                'files' => [],
            ];

            foreach (self::TABLES as $table) {
                $snapshot['tables'][$table] = DB::table($table)
                    ->where('tree_id', $tree->id)
                    ->get()
                    ->map(fn ($row): array => (array) $row)
                    ->all();
            }

            foreach ($snapshot['tables']['person_photos'] as $photo) {
                $path = $photo['path'] ?? null;
                if (! $path || ! Storage::disk('public')->exists($path)) {
                    continue;
                }

                $backupPath = $directory.'/files/'.$path;
                Storage::disk('local')->put($backupPath, Storage::disk('public')->get($path));
                $snapshot['files'][] = ['original' => $path, 'backup' => $backupPath];
            }

            $manifestPath = $directory.'/manifest.json';
            Storage::disk('local')->put(
                $manifestPath,
                json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
            $size = collect(Storage::disk('local')->allFiles($directory))
                ->sum(fn (string $path): int => Storage::disk('local')->size($path));

            $backup->update([
                'status' => 'completed',
                'path' => $manifestPath,
                'size' => $size,
                'statistics' => collect($snapshot['tables'])
                    ->map(fn (array $records): int => count($records))
                    ->all(),
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $backup->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        }

        return $backup->fresh();
    }

    public function restore(TreeBackup $backup): void
    {
        abort_unless($backup->status === 'completed' && $backup->path, 422, 'Резервная копия не готова.');
        $snapshot = json_decode(
            Storage::disk('local')->get($backup->path),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        abort_unless(($snapshot['format'] ?? null) === 'idommoy-tree-backup', 422, 'Неизвестный формат копии.');

        DB::transaction(function () use ($backup, $snapshot): void {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::table($table)->where('tree_id', $backup->tree_id)->delete();
            }
            foreach (self::TABLES as $table) {
                foreach ($snapshot['tables'][$table] ?? [] as $record) {
                    DB::table($table)->insert($record);
                }
            }
        });

        foreach ($snapshot['files'] ?? [] as $file) {
            if (Storage::disk('local')->exists($file['backup'])) {
                Storage::disk('public')->put(
                    $file['original'],
                    Storage::disk('local')->get($file['backup']),
                );
            }
        }
    }

    public function export(FamilyTree $tree): array
    {
        $data = [
            'format' => 'idommoy-family-export',
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'tree' => $tree->withoutRelations()->toArray(),
            'tables' => [],
        ];

        foreach (self::TABLES as $table) {
            $data['tables'][$table] = DB::table($table)
                ->where('tree_id', $tree->id)
                ->get()
                ->map(fn ($row): array => (array) $row)
                ->all();
        }

        return $data;
    }
}
