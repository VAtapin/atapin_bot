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
            $payment = $payments->record(
                FamilyTree::query()->findOrFail($data['tree_id']),
                Plan::query()->findOrFail($data['plan_id']),
                $provider,
                $data['reference'],
                $data['status'],
                $data['amount'],
                $data['currency'],
                $payload,
                isset($data['user_id']) ? User::query()->find($data['user_id']) : null,
                $data['idempotency_key'] ?? null,
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

        $object = $payload['data']['object'] ?? [];
        $metadata = $object['metadata'] ?? [];
        $status = match ($payload['type'] ?? '') {
            'checkout.session.completed', 'payment_intent.succeeded' => 'paid',
            'charge.refunded' => 'refunded',
            'payment_intent.payment_failed' => 'failed',
            default => 'pending',
        };

        return [
            'reference' => (string) ($object['id'] ?? $payload['id']),
            'tree_id' => (int) ($metadata['tree_id'] ?? 0),
            'plan_id' => (int) ($metadata['plan_id'] ?? 0),
            'user_id' => (int) ($metadata['user_id'] ?? 0),
            'idempotency_key' => $metadata['idempotency_key'] ?? null,
            'status' => $status,
            'amount' => ((int) ($object['amount_total'] ?? $object['amount_received'] ?? 0)) / 100,
            'currency' => strtoupper((string) ($object['currency'] ?? 'EUR')),
        ];
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
