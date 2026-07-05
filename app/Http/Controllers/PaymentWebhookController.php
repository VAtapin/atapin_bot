<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\PaymentWebhookLog;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentService $payments): JsonResponse
    {
        $payload = $request->json()->all();
        $eventId = (string) ($payload['id'] ?? $payload['event_id'] ?? $request->input('reference', ''));
        $log = PaymentWebhookLog::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => $eventId ?: null],
            [
                'status' => 'received',
                'signature' => mb_substr((string) (
                    $request->header('Stripe-Signature')
                    ?: $request->header('X-Idommoy-Signature')
                ), 0, 255),
                'payload' => $payload,
            ],
        );
        if ($log->processed_at) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        try {
            $data = match ($provider) {
                'stripe' => $this->stripe($request, $payload),
                'yookassa' => $this->yookassa($payload),
                default => $this->generic($request),
            };
            if (($data['kind'] ?? null) === 'ignored') {
                $log->update(['status' => 'ignored', 'processed_at' => now()]);

                return response()->json(['ok' => true, 'ignored' => true]);
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

                return response()->json(['ok' => true, 'subscription' => true]);
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

            return response()->json(['ok' => true, 'payment_id' => $payment->id]);
        } catch (Throwable $exception) {
            $log->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            report($exception);
            throw $exception;
        }
    }

    private function stripe(Request $request, array $payload): array
    {
        $secret = (string) PlatformSetting::value('billing_webhook_secret');
        $signature = (string) $request->header('Stripe-Signature');
        preg_match('/(?:^|,)t=(\d+)/', $signature, $timestamp);
        preg_match('/(?:^|,)v1=([a-f0-9]+)/', $signature, $provided);
        abort_unless($secret && isset($timestamp[1], $provided[1]), 403);
        abort_if(abs(time() - (int) $timestamp[1]) > 300, 403);
        $expected = hash_hmac('sha256', $timestamp[1].'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $provided[1]), 403);

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

    private function stripeObject(string $path): array
    {
        $secret = (string) PlatformSetting::value('billing_secret_key');
        abort_if($secret === '', 503, 'Не указан секретный ключ Stripe.');

        return Http::withToken($secret)
            ->get('https://api.stripe.com/v1/'.$path)
            ->throw()
            ->json();
    }

    private function yookassa(array $payload): array
    {
        $paymentId = (string) ($payload['object']['id'] ?? '');
        if ($paymentId === '') {
            throw new RuntimeException('ЮKassa не передала ID платежа.');
        }
        $verified = Http::withBasicAuth(
            (string) PlatformSetting::value('billing_shop_id'),
            (string) PlatformSetting::value('billing_secret_key'),
        )->get("https://api.yookassa.ru/v3/payments/{$paymentId}")->throw()->json();
        $metadata = $verified['metadata'] ?? [];

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
}
