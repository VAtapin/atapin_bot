<?php

namespace App\Console\Commands;

use App\Services\TreeDeletionService;
use Illuminate\Console\Command;

class PurgeDeletedTrees extends Command
{
    protected $signature = 'trees:purge-deleted';

    protected $description = 'Remove family data after the 30 day deletion recovery period';

    public function handle(TreeDeletionService $service): int
    {
        $this->info('Удалено деревьев: '.$service->purgeExpired());

        return self::SUCCESS;
    }
}
