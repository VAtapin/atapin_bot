<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PendingAnalyticsEventsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $events = DB::transaction(function () use ($request) {
            $events = AnalyticsEvent::query()
                ->where('user_id', $request->user()->id)
                ->where('external_pending', true)
                ->whereNull('external_dispatched_at')
                ->where('occurred_at', '>=', now()->subDays(30))
                ->lockForUpdate()
                ->limit(30)
                ->get();
            AnalyticsEvent::query()->whereKey($events->modelKeys())->update([
                'external_pending' => false,
                'external_dispatched_at' => now(),
            ]);

            return $events;
        });

        return response()->json([
            'events' => $events->map(fn (AnalyticsEvent $event): array => [
                'name' => $event->event_name,
                'parameters' => $event->parameters ?? [],
                'event_id' => $event->event_uuid,
            ]),
        ]);
    }
}
