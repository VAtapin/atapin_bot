<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class PaymentService
{
    public function record(
        FamilyTree $tree,
        Plan $plan,
        string $provider,
        string $reference,
        string $status,
        string|float $amount,
        string $currency,
        array $payload,
        ?User $user = null,
        ?string $idempotencyKey = null,
        ?string $subscriptionReference = null,
        ?string $customerReference = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): Payment {
        $wasActive = Payment::query()
            ->where('tree_id', $tree->id)
            ->where('status', 'paid')
            ->exists();
        $payment = DB::transaction(function () use (
            $tree,
            $plan,
            $provider,
            $reference,
            $status,
            $amount,
            $currency,
            $payload,
            $user,
            $idempotencyKey,
            $subscriptionReference,
            $customerReference,
            $periodStart,
            $periodEnd,
        ): Payment {
            $subscription = Subscription::query()->firstOrCreate(
                ['tree_id' => $tree->id],
                [
                    'plan_id' => $plan->id,
                    'status' => 'trial',
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            );
            $payment = $idempotencyKey
                ? Payment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('status', 'pending')
                    ->first()
                : null;
            $payment ??= Payment::query()->firstOrNew([
                'provider' => $provider,
                'provider_reference' => $reference,
            ]);
            $payment->fill([
                    'tree_id' => $tree->id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                    'user_id' => $user?->id,
                    'idempotency_key' => $payment?->exists ? $idempotencyKey : null,
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'description' => "Тариф «{$plan->name}» на 1 месяц",
                    'provider_reference' => $reference,
                    'period_starts_at' => $periodStart ? Carbon::parse($periodStart) : now(),
                    'period_ends_at' => $periodEnd ? Carbon::parse($periodEnd) : now()->addMonth(),
                    'payload' => $payload,
                    'paid_at' => $status === 'paid' ? now() : null,
                    'failed_at' => $status === 'failed' ? now() : null,
                    'refunded_at' => $status === 'refunded' ? now() : null,
                ])->save();
            $payment->refresh();

            if ($status === 'paid') {
                $periodEndsAt = $periodEnd ? Carbon::parse($periodEnd) : now()->addMonth();
                $subscription->update([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'provider' => $provider,
                    'provider_reference' => $subscriptionReference ?: $reference,
                    'provider_customer_reference' => $customerReference ?: $subscription->provider_customer_reference,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'starts_at' => $subscription->starts_at ?: now(),
                    'ends_at' => $periodEndsAt,
                    'next_billing_at' => $periodEndsAt,
                    'grace_ends_at' => null,
                    'cancelled_at' => null,
                ]);
                $tree->update(['plan_id' => $plan->id, 'status' => 'active']);
            } elseif ($status === 'failed' && $subscription->status === 'active') {
                $subscription->update([
                    'status' => 'past_due',
                    'grace_ends_at' => now()->addDays(7),
                ]);
            } elseif ($status === 'refunded') {
                $subscription->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);
            }

            return $payment->fresh();
        });

        $parameters = [
            'tree_id' => $tree->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'currency' => strtoupper($currency),
            'value' => (float) $amount,
            'payment_id' => $payment->id,
        ];
        $analytics = app(AnalyticsService::class);
        if ($status === 'paid') {
            $analytics->record(
                'purchase',
                user: $user,
                tree: $tree,
                parameters: $parameters,
                deduplicationKey: "purchase:{$provider}:{$reference}",
                queueForBrowser: true,
            );
            if ($wasActive) {
                $analytics->record(
                    'subscription_renewed',
                    user: $user,
                    tree: $tree,
                    parameters: $parameters,
                    deduplicationKey: 'subscription_renewed:'.$payment->id,
                    queueForBrowser: true,
                );
            }
        } elseif ($status === 'failed') {
            $analytics->record(
                'payment_failed',
                user: $user,
                tree: $tree,
                parameters: $parameters,
                deduplicationKey: "payment_failed:{$provider}:{$reference}",
                queueForBrowser: true,
            );
        }

        return $payment;
    }

    public function syncSubscription(
        FamilyTree $tree,
        Plan $plan,
        string $provider,
        string $subscriptionReference,
        string $status,
        string|float $amount,
        string $currency,
        ?string $customerReference = null,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        bool $cancelAtPeriodEnd = false,
    ): Subscription {
        return DB::transaction(function () use (
            $tree,
            $plan,
            $provider,
            $subscriptionReference,
            $status,
            $amount,
            $currency,
            $customerReference,
            $periodStart,
            $periodEnd,
            $cancelAtPeriodEnd,
        ): Subscription {
            $subscription = Subscription::query()->firstOrNew(['tree_id' => $tree->id]);
            $subscription->fill([
                'plan_id' => $plan->id,
                'status' => $status,
                'provider' => $provider,
                'provider_reference' => $subscriptionReference,
                'provider_customer_reference' => $customerReference,
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'starts_at' => $periodStart ? Carbon::parse($periodStart) : ($subscription->starts_at ?: now()),
                'ends_at' => $periodEnd ? Carbon::parse($periodEnd) : $subscription->ends_at,
                'next_billing_at' => $status === 'active' && $periodEnd ? Carbon::parse($periodEnd) : null,
                'grace_ends_at' => $status === 'past_due' ? now()->addDays(7) : null,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
            ]);
            $subscription->save();
            if ($status === 'active') {
                $tree->update(['plan_id' => $plan->id, 'status' => 'active']);
            }

            return $subscription->fresh();
        });
    }
}
