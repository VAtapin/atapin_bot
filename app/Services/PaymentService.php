<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
    ): Payment {
        $wasActive = Subscription::query()
            ->where('tree_id', $tree->id)
            ->where('status', 'active')
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
            $payment = Payment::query()->updateOrCreate(
                ['provider' => $provider, 'provider_reference' => $reference],
                [
                    'tree_id' => $tree->id,
                    'subscription_id' => $subscription->id,
                    'plan_id' => $plan->id,
                    'user_id' => $user?->id,
                    'idempotency_key' => $idempotencyKey,
                    'status' => $status,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'description' => "Тариф «{$plan->name}» на 1 месяц",
                    'period_starts_at' => now(),
                    'period_ends_at' => now()->addMonth(),
                    'payload' => $payload,
                    'paid_at' => $status === 'paid' ? now() : null,
                    'failed_at' => $status === 'failed' ? now() : null,
                    'refunded_at' => $status === 'refunded' ? now() : null,
                ],
            );

            if ($status === 'paid') {
                $periodEndsAt = now()->addMonth();
                $subscription->update([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'provider' => $provider,
                    'provider_reference' => $reference,
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
}
