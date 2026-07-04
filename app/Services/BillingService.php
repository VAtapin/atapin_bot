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
        $response = Http::asForm()->withToken($secret)->withHeaders(['Idempotency-Key' => $key])
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => route('billing.return', ['tree' => $tree, 'status' => 'success']),
                'cancel_url' => route('billing.return', ['tree' => $tree, 'status' => 'cancel']),
                'customer_email' => $user->email,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => mb_strtolower($plan->currency),
                        'unit_amount' => (int) round((float) $plan->price_monthly * 100),
                        'product_data' => ['name' => "Тариф «{$plan->name}» — {$tree->name}"],
                    ],
                ]],
                'metadata' => [
                    'tree_id' => (string) $tree->id,
                    'plan_id' => (string) $plan->id,
                    'user_id' => (string) $user->id,
                    'idempotency_key' => $key,
                ],
            ])->throw()->json();
        $this->pending($tree, $plan, $user, 'stripe', $response['id'], $key);

        return redirect()->away($response['url']);
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
