<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BillingService
{
    public function checkout(FamilyTree $tree, Plan $plan, User $user): RedirectResponse
    {
        abort_unless(PlatformSetting::value('billing_enabled', false), 503, 'Онлайн-платежи ещё не настроены.');
        $provider = (string) PlatformSetting::value('billing_provider', 'manual');
        $key = (string) Str::uuid();

        $response = match ($provider) {
            'stripe' => $this->stripe($tree, $plan, $user, $key),
            'yookassa' => $this->yookassa($tree, $plan, $user, $key),
            'manual' => $this->manual($tree, $plan, $user, $key),
            default => throw new RuntimeException('Неизвестный платёжный провайдер.'),
        };
        app(AnalyticsService::class)->record(
            'begin_checkout',
            user: $user,
            tree: $tree,
            parameters: [
                'tree_id' => $tree->id,
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'currency' => $plan->currency,
                'value' => (float) $plan->price_monthly,
            ],
            deduplicationKey: "begin_checkout:{$key}",
            queueForBrowser: true,
        );

        return $response;
    }

    private function stripe(FamilyTree $tree, Plan $plan, User $user, string $key): RedirectResponse
    {
        $secret = (string) PlatformSetting::value('billing_secret_key');
        if ($secret === '') {
            throw new RuntimeException('Не указан секретный ключ Stripe.');
        }
        $testMode = (bool) PlatformSetting::value('billing_test_mode', true);
        if ($testMode && ! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('Включён тестовый режим, но указан не тестовый ключ Stripe (нужен sk_test_…).');
        }
        if (! $testMode && ! str_starts_with($secret, 'sk_live_')) {
            throw new RuntimeException('Для рабочего режима нужен боевой ключ Stripe sk_live_….');
        }
        $metadata = [
            'tree_id' => (string) $tree->id,
            'plan_id' => (string) $plan->id,
            'user_id' => (string) $user->id,
            'idempotency_key' => $key,
        ];
        $activeSubscription = $tree->subscriptions()
            ->where('provider', 'stripe')
            ->whereIn('status', ['active', 'trial', 'past_due'])
            ->latest('id')
            ->first();
        if ($activeSubscription?->provider_reference) {
            if ((int) $activeSubscription->plan_id === (int) $plan->id) {
                return redirect('/manage/'.$tree->slug.'/subscriptions')
                    ->with('status', 'Этот тариф уже подключён и продлевается автоматически.');
            }
            if (blank($plan->provider_price_reference)) {
                throw new RuntimeException('Для смены действующего тарифа заполните Stripe Price ID в настройках нового тарифа.');
            }
            $stripeSubscription = Http::withToken($secret)
                ->get("https://api.stripe.com/v1/subscriptions/{$activeSubscription->provider_reference}")
                ->throw()
                ->json();
            $itemId = $stripeSubscription['items']['data'][0]['id'] ?? null;
            if (! $itemId) {
                throw new RuntimeException('Stripe не вернул позицию действующей подписки.');
            }
            Http::asForm()->withToken($secret)->withHeaders(['Idempotency-Key' => $key])
                ->post("https://api.stripe.com/v1/subscriptions/{$activeSubscription->provider_reference}", [
                    'items' => [['id' => $itemId, 'price' => $plan->provider_price_reference]],
                    'proration_behavior' => 'create_prorations',
                    'metadata' => $metadata,
                ])
                ->throw();

            return redirect('/manage/'.$tree->slug.'/subscriptions')
                ->with('status', 'Тариф изменён в Stripe. Итоговый статус обновится после webhook.');
        }
        $lineItem = [
            'quantity' => 1,
            ...(filled($plan->provider_price_reference)
                ? ['price' => $plan->provider_price_reference]
                : ['price_data' => [
                    'currency' => mb_strtolower($plan->currency),
                    'unit_amount' => (int) round((float) $plan->price_monthly * 100),
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => "Тариф «{$plan->name}» — {$tree->name}"],
                ]]),
        ];
        $response = Http::asForm()->withToken($secret)->withHeaders(['Idempotency-Key' => $key])
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'subscription',
                'success_url' => route('billing.return', ['tree' => $tree, 'status' => 'success']),
                'cancel_url' => route('billing.return', ['tree' => $tree, 'status' => 'cancel']),
                'customer_email' => $user->email,
                'client_reference_id' => (string) $tree->id,
                'line_items' => [$lineItem],
                'metadata' => $metadata,
                'subscription_data' => ['metadata' => $metadata],
            ])->throw()->json();
        $this->pending($tree, $plan, $user, 'stripe', $response['id'], $key);

        return redirect()->away($response['url']);
    }

    public function cancelAtPeriodEnd(FamilyTree $tree): void
    {
        $subscription = $tree->subscriptions()->latest('id')->firstOrFail();
        abort_unless($subscription->provider === 'stripe' && filled($subscription->provider_reference), 422, 'У подписки нет активной связи со Stripe.');
        $secret = (string) PlatformSetting::value('billing_secret_key');
        abort_if($secret === '', 503, 'Не указан секретный ключ Stripe.');

        Http::asForm()
            ->withToken($secret)
            ->post("https://api.stripe.com/v1/subscriptions/{$subscription->provider_reference}", [
                'cancel_at_period_end' => 'true',
            ])
            ->throw();

        $subscription->update(['cancel_at_period_end' => true]);
    }

    private function yookassa(FamilyTree $tree, Plan $plan, User $user, string $key): RedirectResponse
    {
        $shopId = (string) PlatformSetting::value('billing_shop_id');
        $secret = (string) PlatformSetting::value('billing_secret_key');
        if ($shopId === '' || $secret === '') {
            throw new RuntimeException('Не указаны Shop ID и секретный ключ ЮKassa.');
        }
        $response = Http::withBasicAuth($shopId, $secret)->acceptJson()
            ->withHeaders(['Idempotence-Key' => $key])
            ->post('https://api.yookassa.ru/v3/payments', [
                'amount' => [
                    'value' => number_format((float) $plan->price_monthly, 2, '.', ''),
                    'currency' => strtoupper($plan->currency),
                ],
                'capture' => true,
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => route('billing.return', ['tree' => $tree, 'status' => 'success']),
                ],
                'description' => "Тариф «{$plan->name}» — {$tree->name}",
                'metadata' => [
                    'tree_id' => $tree->id,
                    'plan_id' => $plan->id,
                    'user_id' => $user->id,
                    'idempotency_key' => $key,
                ],
            ])->throw()->json();
        $this->pending($tree, $plan, $user, 'yookassa', $response['id'], $key);

        return redirect()->away($response['confirmation']['confirmation_url']);
    }

    private function manual(FamilyTree $tree, Plan $plan, User $user, string $key): RedirectResponse
    {
        $this->pending($tree, $plan, $user, 'manual', 'manual-'.Str::uuid(), $key);

        return redirect('/manage/'.$tree->slug.'/payments')
            ->with('status', 'Заявка на оплату создана. Суперадминистратор подтвердит её после получения платежа.');
    }

    private function pending(FamilyTree $tree, Plan $plan, User $user, string $provider, string $reference, string $key): void
    {
        Payment::query()->create([
            'tree_id' => $tree->id,
            'subscription_id' => $tree->subscriptions()->latest('id')->value('id'),
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_reference' => $reference,
            'idempotency_key' => $key,
            'status' => 'pending',
            'amount' => $plan->price_monthly,
            'currency' => $plan->currency,
            'description' => "Тариф «{$plan->name}» на 1 месяц",
            'period_starts_at' => now(),
            'period_ends_at' => now()->addMonth(),
        ]);
    }
}
