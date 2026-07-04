<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Services\AnalyticsService;

class SubscriptionAnalyticsObserver
{
    public function created(Subscription $subscription): void
    {
        if ($subscription->status === 'active') {
            $this->record($subscription, 'subscription_started');
        }
    }

    public function updated(Subscription $subscription): void
    {
        if ($subscription->wasChanged('status') && $subscription->status === 'active') {
            $this->record($subscription, 'subscription_started');
        }
        if ($subscription->wasChanged('status') && $subscription->status === 'cancelled') {
            $this->record($subscription, 'subscription_cancelled');
        }
    }

    private function record(Subscription $subscription, string $event): void
    {
        app(AnalyticsService::class)->record(
            $event,
            user: $subscription->tree?->owner,
            tree: $subscription->tree,
            parameters: [
                'tree_id' => $subscription->tree_id,
                'plan_id' => $subscription->plan_id,
                'currency' => $subscription->currency,
                'value' => (float) $subscription->amount,
                'subscription_id' => $subscription->id,
            ],
            deduplicationKey: "{$event}:subscription:{$subscription->id}",
            queueForBrowser: true,
        );
    }
}
