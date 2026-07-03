<?php

namespace App\Jobs;

use App\Models\SystemHeartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        SystemHeartbeat::query()->updateOrCreate(
            ['key' => 'queue'],
            [
                'status' => 'ok',
                'last_seen_at' => now(),
                'meta' => ['connection' => config('queue.default')],
                'last_error' => null,
            ],
        );
    }
}
