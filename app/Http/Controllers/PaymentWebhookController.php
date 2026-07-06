<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentWebhookLog;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentService $payments): JsonResponse|Response
    {
        $payload = $request->json()->all() ?: $request->all();
        $eventId = (string) (
            $payload['id']
            ?? $payload['event_id']
            ?? $payload['InvId']
            ?? $payload['InvoiceId']
            ?? $payload['TransactionId']
            ?? $payload['invoiceId']
            ?? $payload['reference']
            ?? ''
        );
        if ($eventId === '') {
            $eventId = 'payload-'.hash('sha256', $provider.'|'.$request->getContent());
        }
        $log = PaymentWebhookLog::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => $eventId],
            [
                'status' => 'received',
                'signature' => mb_substr((string) (
                    $request->header('Stripe-Signature')
                    ?: $request->header('X-Idommoy-Signature')
                    ?: $request->input('SignatureValue')
                    ?: $request->input('signature')
                ), 0, 255),
                'payload' => $payload,
            ],
        );
        if ($log->processed_at) {
            return $this->providerResponse($provider, [
                'reference' => $eventId,
                'duplicate' => true,
            ]);
        }

        try {
            $data = match ($provider) {
                'stripe' => $this->stripe($request, $payload),
                'paypal' => $this->paypal($request, $payload),
                'yookassa' => $this->yookassa($payload),
                'robokassa' => $this->robokassa($request),
                'cloudpayments' => $this->cloudpayments($request),
                default => $this->generic($request),
            };

            if (($data['kind'] ?? null) === 'ignored') {
                $log->update(['status' => 'ignored', 'processed_at' => now()]);

                return $this->providerResponse($provider, [
                    'reference' => $eventId,
                    'ignored' => true,
                ]);
            }

            $tree = FamilyTree::query()->findOrFail($data['tree_id']);
            $plan = Plan::query()->findOrFail($data['plan_id']);
            if (($data['kind'] ?? 'payment') === 'subscription') {
                $payments->syncSubscription(
                    $tree,
                    $plan,
                    $provider,
                    $data['subscription_reference'],
                    $data['subscription_status'],
                    $data['amount'],
                    $data['currency'],
                    $data['customer_reference'] ?? null,
                    $data['period_start'] ?? null,
                    $data['period_end'] ?? null,
                    (bool) ($data['cancel_at_period_end'] ?? false),
                );
                $log->update(['status' => 'processed', 'processed_at' => now()]);

                return $this->providerResponse($provider, [
                    ...$data,
                    'subscription' => true,
                ]);
            }

            $payment = $payments->record(
                $tree,
                $plan,
                $provider,
                $data['reference'],
                $data['status'],
                $data['amount'],
                $data['currency'],
                $payload,
                isset($data['user_id']) ? User::query()->find($data['user_id']) : null,
                $data['idempotency_key'] ?? null,
                $data['subscription_reference'] ?? null,
                $data['customer_reference'] ?? null,
                $data['period_start'] ?? null,
                $data['period_end'] ?? null,
            );
            $log->update(['status' => 'processed', 'processed_at' => now()]);

            return $this->providerResponse($provider, [
                ...$data,
                'payment_id' => $payment->id,
            ]);
        } catch (Throwable $exception) {
            $log->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            report($exception);
            throw $exception;
        }
    }

    private function stripe(Request $request, array $payload): array
    {
        $this->verifyStripeSignature($request);

        $type = (string) ($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? [];
        if (! in_array($type, [
            'checkout.session.completed',
            'customer.subscription.created',
            'invoice.paid',
            'invoice.payment_failed',
            'invoice.payment_action_required',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'charge.refunded',
        ], true)) {
            return ['kind' => 'ignored'];
        }
        if ($type === 'charge.refunded' && ! ($object['refunded'] ?? false)) {
            return ['kind' => 'ignored'];
        }

        $subscriptionId = $object['subscription']
            ?? $object['parent']['subscription_details']['subscription']
            ?? (str_starts_with($type, 'customer.subscription.') ? ($object['id'] ?? null) : null)
            ?? ($type === 'checkout.session.completed' ? ($object['subscription'] ?? null) : null);
        if ($type === 'charge.refunded' && ! $subscriptionId && filled($object['invoice'] ?? null)) {
            $invoice = $this->stripeObject('invoices/'.rawurlencode((string) $object['invoice']));
            $subscriptionId = $invoice['subscription']
                ?? $invoice['parent']['subscription_details']['subscription']
                ?? null;
        }

        $subscription = $subscriptionId
            ? $this->stripeObject('subscriptions/'.rawurlencode((string) $subscriptionId))
            : [];
        $metadata = array_merge($subscription['metadata'] ?? [], $object['metadata'] ?? []);
        $periodStart = $object['lines']['data'][0]['period']['start']
            ?? $subscription['items']['data'][0]['current_period_start']
            ?? $subscription['current_period_start']
            ?? null;
        $periodEnd = $object['lines']['data'][0]['period']['end']
            ?? $subscription['items']['data'][0]['current_period_end']
            ?? $subscription['current_period_end']
            ?? null;
        $amount = ((int) (
            $object['amount_total']
            ?? $object['amount_paid']
            ?? $object['amount_due']
            ?? $object['amount_refunded']
            ?? $subscription['items']['data'][0]['price']['unit_amount']
            ?? 0
        )) / 100;
        $currency = strtoupper((string) (
            $object['currency']
            ?? $subscription['currency']
            ?? $subscription['items']['data'][0]['price']['currency']
            ?? 'EUR'
        ));
        $base = [
            'tree_id' => (int) ($metadata['tree_id'] ?? 0),
            'plan_id' => (int) ($metadata['plan_id'] ?? 0),
            'user_id' => (int) ($metadata['user_id'] ?? 0),
            'idempotency_key' => $metadata['idempotency_key'] ?? null,
            'subscription_reference' => (string) ($subscriptionId ?? ''),
            'customer_reference' => (string) ($object['customer'] ?? $subscription['customer'] ?? ''),
            'period_start' => $periodStart ? date('c', (int) $periodStart) : null,
            'period_end' => $periodEnd ? date('c', (int) $periodEnd) : null,
            'amount' => $amount,
            'currency' => $currency,
        ];

        if (in_array($type, ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            $providerStatus = $type === 'customer.subscription.deleted'
                ? 'canceled'
                : (string) ($subscription['status'] ?? 'active');

            return [...$base,
                'kind' => 'subscription',
                'subscription_status' => match ($providerStatus) {
                    'active', 'trialing' => 'active',
                    'past_due', 'unpaid', 'incomplete' => 'past_due',
                    default => 'cancelled',
                },
                'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            ];
        }

        return [...$base,
            'kind' => 'payment',
            'reference' => (string) ($object['id'] ?? $payload['id']),
            'status' => match ($type) {
                'invoice.paid' => 'paid',
                'invoice.payment_failed', 'invoice.payment_action_required' => 'failed',
                'charge.refunded' => 'refunded',
            },
        ];
    }

    private function paypal(Request $request, array $payload): array
    {
        $this->verifyPayPalWebhook($request, $payload);

        $resource = $payload['resource'] ?? [];
        $reference = (string) ($resource['id'] ?? '');
        $payment = $reference !== ''
            ? Payment::query()->where('provider', 'paypal')->where('provider_reference', $reference)->first()
            : null;

        if (! $payment && filled($resource['supplementary_data']['related_ids']['order_id'] ?? null)) {
            $payment = Payment::query()
                ->where('provider', 'paypal')
                ->where('provider_reference', $resource['supplementary_data']['related_ids']['order_id'])
                ->first();
        }

        if (! $payment) {
            return ['kind' => 'ignored'];
        }

        $eventType = (string) ($payload['event_type'] ?? '');
        $status = str_contains($eventType, 'COMPLETED') || ($resource['status'] ?? '') === 'COMPLETED'
            ? 'paid'
            : (str_contains($eventType, 'DENIED') || str_contains($eventType, 'FAILED') ? 'failed' : 'pending');

        return [
            'reference' => $reference ?: (string) $payment->provider_reference,
            'tree_id' => $payment->tree_id,
            'plan_id' => $payment->plan_id,
            'user_id' => $payment->user_id,
            'idempotency_key' => $payment->idempotency_key,
            'status' => $status,
            'amount' => $resource['amount']['value'] ?? $payment->amount,
            'currency' => $resource['amount']['currency_code'] ?? $payment->currency,
        ];
    }

    private function verifyPayPalWebhook(Request $request, array $payload): void
    {
        $method = PaymentMethod::query()
            ->where('provider', 'paypal')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->first(fn (PaymentMethod $method): bool => filled($method->credential('client_id'))
                && filled($method->credential('secret_key'))
                && filled($method->credential('webhook_id') ?: $method->webhook_secret));

        abort_unless($method, 503, 'PayPal webhook не настроен: укажите Client ID, Secret и Webhook ID.');

        $clientId = (string) $method->credential('client_id');
        $secret = (string) $method->credential('secret_key');
        $webhookId = (string) ($method->credential('webhook_id') ?: $method->webhook_secret);
        $baseUrl = $method->test_mode ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        $token = Http::asForm()
            ->withBasicAuth($clientId, $secret)
            ->post($baseUrl.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json('access_token');

        $verification = Http::withToken((string) $token)
            ->acceptJson()
            ->post($baseUrl.'/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'cert_url' => $request->header('PAYPAL-CERT-URL'),
                'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ])
            ->throw()
            ->json();

        abort_unless(($verification['verification_status'] ?? null) === 'SUCCESS', 403);
    }

    private function yookassa(array $payload): array
    {
        $paymentId = (string) ($payload['object']['id'] ?? '');
        if ($paymentId === '') {
            throw new RuntimeException('ЮKassa не передала ID платежа.');
        }

        $metadata = $payload['object']['metadata'] ?? [];
        $tree = isset($metadata['tree_id']) ? FamilyTree::query()->find((int) $metadata['tree_id']) : null;
        $method = $tree
            ? PaymentMethod::query()->activeFor($tree->billingRegion(), $tree->billingCurrency())->where('provider', 'yookassa')->first()
            : PaymentMethod::query()->where('provider', 'yookassa')->where('is_active', true)->orderBy('sort_order')->first();

        $shopId = (string) ($method?->credential('shop_id') ?: PlatformSetting::value('billing_shop_id'));
        $secret = (string) ($method?->credential('secret_key') ?: PlatformSetting::value('billing_secret_key'));
        abort_if($shopId === '' || $secret === '', 503, 'ЮKassa не настроена.');

        $verified = Http::withBasicAuth($shopId, $secret)
            ->get("https://api.yookassa.ru/v3/payments/{$paymentId}")
            ->throw()
            ->json();
        $metadata = $verified['metadata'] ?? $metadata;

        return [
            'reference' => $paymentId,
            'tree_id' => (int) ($metadata['tree_id'] ?? 0),
            'plan_id' => (int) ($metadata['plan_id'] ?? 0),
            'user_id' => (int) ($metadata['user_id'] ?? 0),
            'idempotency_key' => $metadata['idempotency_key'] ?? null,
            'status' => match ($verified['status'] ?? '') {
                'succeeded' => $verified['refunded_amount']['value'] ?? false ? 'refunded' : 'paid',
                'canceled' => 'failed',
                default => 'pending',
            },
            'amount' => $verified['amount']['value'] ?? 0,
            'currency' => $verified['amount']['currency'] ?? 'RUB',
        ];
    }

    private function robokassa(Request $request): array
    {
        $invoiceId = (string) $request->input('InvId');
        $sum = (string) $request->input('OutSum');
        $signature = mb_strtoupper((string) $request->input('SignatureValue'));
        $payment = Payment::query()->where('provider', 'robokassa')->where('provider_reference', $invoiceId)->firstOrFail();
        $method = PaymentMethod::query()
            ->activeFor($payment->tree->billingRegion(), $payment->tree->billingCurrency())
            ->where('provider', 'robokassa')
            ->first();
        $password2 = (string) $method?->credential('password2');
        abort_if($password2 === '', 503, 'Не указан пароль #2 Robokassa.');

        $expected = mb_strtoupper(md5("{$sum}:{$invoiceId}:{$password2}"));
        abort_unless(hash_equals($expected, $signature), 403);

        return [
            'reference' => $invoiceId,
            'tree_id' => $payment->tree_id,
            'plan_id' => $payment->plan_id,
            'user_id' => $payment->user_id,
            'idempotency_key' => $payment->idempotency_key,
            'status' => 'paid',
            'amount' => $sum,
            'currency' => $payment->currency ?: 'RUB',
        ];
    }

    private function cloudpayments(Request $request): array
    {
        $invoiceId = (string) ($request->input('InvoiceId') ?: $request->input('TransactionId'));
        $payment = Payment::query()
            ->where('provider', 'cloudpayments')
            ->where('provider_reference', $invoiceId)
            ->first();

        if (! $payment) {
            return ['kind' => 'ignored'];
        }
        $method = PaymentMethod::query()
            ->activeFor($payment->tree->billingRegion(), $payment->tree->billingCurrency())
            ->where('provider', 'cloudpayments')
            ->first();
        $secret = (string) ($method?->credential('secret_key') ?: $method?->credential('api_secret'));
        if ($secret !== '') {
            $signature = (string) $request->header('Content-HMAC');
            $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
            abort_unless($signature !== '' && hash_equals($expected, $signature), 403);
        }
        $providerStatus = (string) $request->input('Status');
        $isPaid = $providerStatus === 'Completed'
            || $request->boolean('Success');

        return [
            'reference' => $invoiceId,
            'tree_id' => $payment->tree_id,
            'plan_id' => $payment->plan_id,
            'user_id' => $payment->user_id,
            'idempotency_key' => $payment->idempotency_key,
            'status' => $isPaid ? 'paid' : 'failed',
            'amount' => $request->input('Amount', $payment->amount),
            'currency' => $request->input('Currency', $payment->currency ?: 'RUB'),
        ];
    }

    private function generic(Request $request): array
    {
        $secret = (string) (
            PlatformSetting::value('billing_webhook_secret')
            ?: config('services.billing.webhook_secret')
        );
        abort_if($secret === '', 503, 'Платёжный провайдер не настроен.');
        $signature = (string) $request->header('X-Idommoy-Signature');
        abort_unless(
            $signature !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature),
            403,
        );

        return $request->validate([
            'reference' => ['required', 'string', 'max:190'],
            'tree_id' => ['required', 'integer', 'exists:family_trees,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', 'in:pending,paid,failed,refunded'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'idempotency_key' => ['nullable', 'string', 'max:190'],
        ]);
    }

    private function verifyStripeSignature(Request $request): void
    {
        $signature = (string) $request->header('Stripe-Signature');
        preg_match('/(?:^|,)t=(\d+)/', $signature, $timestamp);
        preg_match('/(?:^|,)v1=([a-f0-9]+)/', $signature, $provided);
        abort_unless(isset($timestamp[1], $provided[1]), 403);
        abort_if(abs(time() - (int) $timestamp[1]) > 300, 403);

        foreach ($this->stripeWebhookSecrets() as $secret) {
            $expected = hash_hmac('sha256', $timestamp[1].'.'.$request->getContent(), $secret);
            if (hash_equals($expected, $provided[1])) {
                return;
            }
        }

        abort(403);
    }

    private function stripeObject(string $path): array
    {
        $secret = $this->stripeSecret();
        abort_if($secret === '', 503, 'Не указан секретный ключ Stripe.');

        return Http::withToken($secret)
            ->get('https://api.stripe.com/v1/'.$path)
            ->throw()
            ->json();
    }

    private function stripeSecret(): string
    {
        return (string) (
            PaymentMethod::query()
                ->where('provider', 'stripe')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->first(fn (PaymentMethod $method): bool => filled($method->credential('secret_key')))
                ?->credential('secret_key')
            ?: PlatformSetting::value('billing_secret_key')
        );
    }

    private function stripeWebhookSecrets(): array
    {
        return collect(PaymentMethod::query()
            ->where('provider', 'stripe')
            ->where('is_active', true)
            ->get()
            ->map(fn (PaymentMethod $method): ?string => $method->webhook_secret)
            ->filter()
            ->values()
            ->all())
            ->push(PlatformSetting::value('billing_webhook_secret'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function providerResponse(string $provider, array $data): JsonResponse|Response
    {
        if ($provider === 'robokassa') {
            return response('OK'.($data['reference'] ?? ''));
        }

        if ($provider === 'cloudpayments') {
            return response()->json(['code' => 0]);
        }

        return response()->json(['ok' => true] + array_intersect_key($data, array_flip([
            'duplicate',
            'ignored',
            'subscription',
            'payment_id',
        ])));
    }
}
