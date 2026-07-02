<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentService $payments): JsonResponse
    {
        $secret = (string) config('services.billing.webhook_secret');
        abort_if($secret === '', 503, 'Платёжный провайдер не настроен.');
        $signature = (string) $request->header('X-Idommoy-Signature');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        abort_unless($signature !== '' && hash_equals($expected, $signature), 403);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:190'],
            'tree_id' => ['required', 'integer', 'exists:family_trees,id'],
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'status' => ['required', 'in:pending,paid,failed,refunded'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $payment = $payments->record(
            FamilyTree::query()->findOrFail($data['tree_id']),
            Plan::query()->findOrFail($data['plan_id']),
            $provider,
            $data['reference'],
            $data['status'],
            $data['amount'],
            $data['currency'],
            $request->all(),
        );

        return response()->json(['ok' => true, 'payment_id' => $payment->id]);
    }
}
