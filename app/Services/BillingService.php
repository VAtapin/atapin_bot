<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BillingService
{
    public function checkout(FamilyTree $tree, Plan $plan, User $user, ?PaymentMethod $method = null): RedirectResponse
    {
        abort_unless(PlatformSetting::value('billing_enabled', false), 503, 'Онлайн-платежи ещё не настроены.');

        $region = $tree->billingRegion();
        $currency = $tree->billingCurrency();
        $amount = $plan->priceAmountFor($region, $currency);

        abort_if($amount <= 0.0, 422, 'Бесплатный тариф не требует оплаты. Выберите платный тариф, если нужны большие лимиты.');

        $method ??= PaymentMethod::query()->activeFor($region, $currency)->first();

        if (! $method) {
            throw new RuntimeException('Для этого региона и валюты нет активного способа оплаты.');
        }

        if ($method->region !== $region || strtoupper($method->currency) !== $currency || ! $method->is_active) {
            throw new RuntimeException('Выбранный способ оплаты недоступен для этого дерева.');
        }

        $key = (string) Str::uuid();
        $response = match ($method->provider) {
            'stripe' => $this->stripe($tree, $plan, $user, $method, $key, $amount, $currency),
            'paypal' => $this->paypal($tree, $plan, $user, $method, $key, $amount, $currency),
            'yookassa' => $this->yookassa($tree, $plan, $user, $method, $key, $amount, $currency),
            'robokassa' => $this->robokassa($tree, $plan, $user, $method, $key, $amount, $currency),
            'cloudpayments' => $this->cloudpayments($tree, $plan, $user, $method, $key, $amount, $currency),
            'manual' => $this->manual($tree, $plan, $user, $method, $key, $amount, $currency),
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
                'payment_method' => $method->code,
                'payment_provider' => $method->provider,
                'region' => $region,
                'currency' => $currency,
                'value' => $amount,
            ],
            deduplicationKey: "begin_checkout:{$key}",
            queueForBrowser: true,
        );

        return $response;
    }

    private function stripe(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        PaymentMethod $method,
        string $key,
        float $amount,
        string $currency,
    ): RedirectResponse {
        $secret = (string) ($method->credential('secret_key') ?: PlatformSetting::value('billing_secret_key'));
        if ($secret === '') {
            throw new RuntimeException('Не указан секретный ключ Stripe.');
        }
        if ($method->test_mode && ! str_starts_with($secret, 'sk_test_')) {
            throw new RuntimeException('Включён тестовый режим Stripe, но указан не тестовый ключ sk_test_….');
        }
        if (! $method->test_mode && ! str_starts_with($secret, 'sk_live_')) {
            throw new RuntimeException('Для рабочего Stripe нужен боевой ключ sk_live_….');
        }

        $metadata = $this->metadata($tree, $plan, $user, $key);
        $priceReference = $plan->providerPriceReferenceFor($tree->billingRegion(), $currency);
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
            if (blank($priceReference)) {
                throw new RuntimeException('Для смены действующего Stripe-тарифа заполните ID цены у провайдера для выбранного региона.');
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
                    'items' => [['id' => $itemId, 'price' => $priceReference]],
                    'proration_behavior' => 'create_prorations',
                    'metadata' => $metadata,
                ])
                ->throw();

            return redirect('/manage/'.$tree->slug.'/subscriptions')
                ->with('status', 'Тариф изменён в Stripe. Итоговый статус обновится после webhook.');
        }

        $lineItem = [
            'quantity' => 1,
            ...(filled($priceReference)
                ? ['price' => $priceReference]
                : ['price_data' => [
                    'currency' => mb_strtolower($currency),
                    'unit_amount' => $this->minorUnits($amount),
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

        $this->pending($tree, $plan, $user, 'stripe', (string) $response['id'], $key, $amount, $currency);

        return redirect()->away($response['url']);
    }

    private function paypal(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        PaymentMethod $method,
        string $key,
        float $amount,
        string $currency,
    ): RedirectResponse {
        $clientId = (string) $method->credential('client_id');
        $secret = (string) $method->credential('secret_key');
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('Не указаны Client ID и Secret для PayPal.');
        }

        $baseUrl = $method->test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $token = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->post($baseUrl.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('PayPal не вернул access token.');
        }

        $response = Http::withToken($token)->acceptJson()
            ->post($baseUrl.'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $key,
                    'custom_id' => "{$tree->id}:{$plan->id}:{$user->id}:{$key}",
                    'description' => mb_substr("Тариф «{$plan->name}» — {$tree->name}", 0, 127),
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'brand_name' => 'Я и дом мой',
                    'landing_page' => 'LOGIN',
                    'user_action' => 'PAY_NOW',
                    'return_url' => route('billing.return', ['tree' => $tree, 'status' => 'success']),
                    'cancel_url' => route('billing.return', ['tree' => $tree, 'status' => 'cancel']),
                ],
            ])
            ->throw()
            ->json();

        $orderId = (string) ($response['id'] ?? '');
        $approvalUrl = collect($response['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        if ($orderId === '' || ! $approvalUrl) {
            throw new RuntimeException('PayPal не вернул ссылку на оплату.');
        }

        $this->pending($tree, $plan, $user, 'paypal', $orderId, $key, $amount, $currency);

        return redirect()->away($approvalUrl);
    }

    private function yookassa(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        PaymentMethod $method,
        string $key,
        float $amount,
        string $currency,
    ): RedirectResponse {
        $shopId = (string) ($method->credential('shop_id') ?: PlatformSetting::value('billing_shop_id'));
        $secret = (string) ($method->credential('secret_key') ?: PlatformSetting::value('billing_secret_key'));
        if ($shopId === '' || $secret === '') {
            throw new RuntimeException('Не указаны Shop ID и секретный ключ ЮKassa.');
        }

        $response = Http::withBasicAuth($shopId, $secret)->acceptJson()
            ->withHeaders(['Idempotence-Key' => $key])
            ->post('https://api.yookassa.ru/v3/payments', [
                'amount' => [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency' => $currency,
                ],
                'capture' => true,
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => route('billing.return', ['tree' => $tree, 'status' => 'success']),
                ],
                'description' => "Тариф «{$plan->name}» — {$tree->name}",
                'metadata' => $this->metadata($tree, $plan, $user, $key),
            ])->throw()->json();

        $this->pending($tree, $plan, $user, 'yookassa', (string) $response['id'], $key, $amount, $currency);

        return redirect()->away($response['confirmation']['confirmation_url']);
    }

    private function robokassa(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        PaymentMethod $method,
        string $key,
        float $amount,
        string $currency,
    ): RedirectResponse {
        if ($currency !== 'RUB') {
            throw new RuntimeException('Robokassa поддерживается только для тарифов в RUB.');
        }

        $login = (string) $method->credential('merchant_login');
        $password1 = (string) $method->credential('password1');
        if ($login === '' || $password1 === '') {
            throw new RuntimeException('Не указан Merchant Login или пароль #1 Robokassa.');
        }

        $payment = $this->pending($tree, $plan, $user, 'robokassa', 'pending-'.$key, $key, $amount, $currency);
        $invoiceId = (string) $payment->id;
        $sum = number_format($amount, 2, '.', '');
        $signature = md5("{$login}:{$sum}:{$invoiceId}:{$password1}");

        $payment->update(['provider_reference' => $invoiceId]);

        return redirect()->away('https://auth.robokassa.ru/Merchant/Index.aspx?'.http_build_query([
            'MerchantLogin' => $login,
            'OutSum' => $sum,
            'InvId' => $invoiceId,
            'Description' => "Тариф «{$plan->name}» — {$tree->name}",
            'SignatureValue' => $signature,
            'Culture' => 'ru',
            'Encoding' => 'utf-8',
            'IsTest' => $method->test_mode ? 1 : 0,
        ]));
    }

    private function cloudpayments(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        PaymentMethod $method,
        string $key,
        float $amount,
        string $currency,
    ): RedirectResponse {
        $publicId = (string) ($method->credential('public_id') ?: $method->credential('shop_id'));
        if ($publicId === '') {
            throw new RuntimeException('Для CloudPayments укажите Public ID в поле Shop ID / Account ID или credentials.public_id.');
        }

        $payment = $this->pending($tree, $plan, $user, 'cloudpayments', 'pending-'.$key, $key, $amount, $currency);
        $payment->update(['provider_reference' => (string) $payment->id]);

        return redirect()->route('billing.cloudpayments', ['payment' => $payment]);
    }

    private function manual(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        PaymentMethod $method,
        string $key,
        float $amount,
        string $currency,
    ): RedirectResponse {
        $this->pending($tree, $plan, $user, $method->provider, 'manual-'.Str::uuid(), $key, $amount, $currency);

        return redirect('/manage/'.$tree->slug.'/payments')
            ->with('status', $method->instructions ?: 'Заявка на оплату создана. Суперадминистратор подтвердит её после получения платежа.');
    }

    public function cancelAtPeriodEnd(FamilyTree $tree): void
    {
        $subscription = $tree->subscriptions()->latest('id')->firstOrFail();
        abort_unless($subscription->provider === 'stripe' && filled($subscription->provider_reference), 422, 'У подписки нет активной связи со Stripe.');

        $method = PaymentMethod::query()
            ->activeFor($tree->billingRegion(), $tree->billingCurrency())
            ->where('provider', 'stripe')
            ->first();
        $secret = (string) ($method?->credential('secret_key') ?: PlatformSetting::value('billing_secret_key'));
        abort_if($secret === '', 503, 'Не указан секретный ключ Stripe.');

        Http::asForm()
            ->withToken($secret)
            ->post("https://api.stripe.com/v1/subscriptions/{$subscription->provider_reference}", [
                'cancel_at_period_end' => 'true',
            ])
            ->throw();

        $subscription->update(['cancel_at_period_end' => true]);
    }

    public function capturePayPalReturn(FamilyTree $tree, string $orderId): void
    {
        $payment = Payment::query()
            ->where('tree_id', $tree->id)
            ->where('provider', 'paypal')
            ->where('provider_reference', $orderId)
            ->where('status', 'pending')
            ->first();

        if (! $payment) {
            return;
        }

        $method = PaymentMethod::query()
            ->activeFor($tree->billingRegion(), $tree->billingCurrency())
            ->where('provider', 'paypal')
            ->first();
        $clientId = (string) $method?->credential('client_id');
        $secret = (string) $method?->credential('secret_key');
        abort_if($clientId === '' || $secret === '', 503, 'PayPal не настроен.');

        $baseUrl = $method->test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $token = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->post($baseUrl.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json('access_token');

        $capture = Http::withToken((string) $token)
            ->withHeaders(['PayPal-Request-Id' => $payment->idempotency_key ?: (string) Str::uuid()])
            ->post($baseUrl.'/v2/checkout/orders/'.rawurlencode($orderId).'/capture')
            ->throw()
            ->json();

        if (($capture['status'] ?? null) !== 'COMPLETED') {
            return;
        }

        app(PaymentService::class)->record(
            $tree,
            $payment->plan,
            'paypal',
            $orderId,
            'paid',
            $payment->amount,
            $payment->currency,
            $capture,
            $payment->user,
            $payment->idempotency_key,
        );
    }

    private function pending(
        FamilyTree $tree,
        Plan $plan,
        User $user,
        string $provider,
        string $reference,
        string $key,
        float $amount,
        string $currency,
    ): Payment {
        return Payment::query()->create([
            'tree_id' => $tree->id,
            'subscription_id' => $tree->subscriptions()->latest('id')->value('id'),
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_reference' => $reference,
            'idempotency_key' => $key,
            'status' => 'pending',
            'amount' => $amount,
            'currency' => $currency,
            'description' => "Тариф «{$plan->name}» на 1 месяц",
            'period_starts_at' => now(),
            'period_ends_at' => now()->addMonth(),
        ]);
    }

    private function metadata(FamilyTree $tree, Plan $plan, User $user, string $key): array
    {
        return [
            'tree_id' => (string) $tree->id,
            'plan_id' => (string) $plan->id,
            'user_id' => (string) $user->id,
            'idempotency_key' => $key,
        ];
    }

    private function minorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
