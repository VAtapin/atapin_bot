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
        $query = FamilyTree::query()->where('status', 'active');
        if ($tree = $this->argument('tree')) {
            $query->where(fn ($query) => $query->whereKey($tree)->orWhere('slug', $tree));
        }

        $query->each(function (FamilyTree $tree) use ($archive): void {
            $backup = $archive->create($tree, type: 'automatic');
            $this->line("{$tree->name}: {$backup->status}");
            $retentionDays = (int) ($tree->plan?->backup_retention_days ?? 30);
            $tree->backups()
                ->where('created_at', '<', now()->subDays($retentionDays))
                ->get()
                ->each->delete();
        });

        return self::SUCCESS;
    }
}
