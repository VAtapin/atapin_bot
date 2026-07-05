<?php

namespace App\Console\Commands;

use App\Models\FamilyTree;
use App\Services\TreeArchiveService;
use Illuminate\Console\Command;

class BackupFamilyTrees extends Command
{
    protected $signature = 'trees:backup {tree? : ID or slug of one tree}';

    protected $description = 'Create database and media backups for family trees';

    public function handle(TreeArchiveService $archive): int
    {
        $forced = filled($this->argument('tree'));
        $query = FamilyTree::query()->where('status', 'active');
        if ($tree = $this->argument('tree')) {
            $query->where(fn ($query) => $query->whereKey($tree)->orWhere('slug', $tree));
        }

        $query->each(function (FamilyTree $tree) use ($archive, $forced): void {
            if (! $forced && ! $this->shouldCreateAutomaticBackup($tree)) {
                $this->line("{$tree->name}: skipped");

                return;
            }

            $this->pruneBeforeCreate($tree);
            $backup = $archive->create($tree, type: 'automatic');
            $this->line("{$tree->name}: {$backup->status}");
        });

        return self::SUCCESS;
    }

    private function shouldCreateAutomaticBackup(FamilyTree $tree): bool
    {
        if (! (bool) data_get($tree->settings, 'backups.auto_enabled', true)) {
            return false;
        }

        $frequencyDays = max(1, (int) data_get($tree->settings, 'backups.frequency_days', 1));
        $lastBackup = $tree->backups()
            ->where('type', 'automatic')
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();
        $lastBackupAt = $lastBackup?->completed_at ?? $lastBackup?->created_at;

        return ! $lastBackupAt || $lastBackupAt->lte(now()->subDays($frequencyDays));
    }

    private function pruneBeforeCreate(FamilyTree $tree): void
    {
        $maxCopies = (int) data_get($tree->settings, 'backups.max_copies', 7);

        if ($maxCopies <= 0) {
            return;
        }

        $tree->backups()
            ->where('status', 'completed')
            ->latest('id')
            ->get()
            ->skip(max(0, $maxCopies - 1))
            ->each->delete();
    }
}
