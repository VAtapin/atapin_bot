<?php

namespace App\Console\Commands;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;

class RecordPlatformHeartbeat extends Command
{
    protected $signature = 'platform:heartbeat';

    protected $description = 'Record scheduler heartbeat and ask the queue worker to confirm it is alive';

    public function handle(): int
    {
        SystemHeartbeat::query()->updateOrCreate(
            ['key' => 'scheduler'],
            [
                'status' => 'ok',
                'last_seen_at' => now(),
                'meta' => ['timezone' => config('app.timezone')],
                'last_error' => null,
            ],
        );
        RecordQueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
