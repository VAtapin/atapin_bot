<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Services\AnalyticsService;
use App\Services\BillingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class BillingController extends Controller
{
    public function checkout(Request $request, FamilyTree $tree, Plan $plan, BillingService $billing): RedirectResponse|View
    {
        abort_unless($request->user()?->ownsTree($tree), 403);
        abort_unless($plan->is_active, 404);

        $region = $tree->billingRegion();
        $currency = $tree->billingCurrency();
        $amount = $plan->priceAmountFor($region, $currency);

        if ($amount <= 0.0) {
            return redirect('/manage/'.$tree->slug.'/subscriptions')
                ->with('status', 'Бесплатный тариф уже доступен без оплаты. Для больших лимитов выберите платный тариф.');
        }

        app(AnalyticsService::class)->record(
            'view_plan',
            $request,
            $request->user(),
            $tree,
            [
                'tree_id' => $tree->id,
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'region' => $region,
                'currency' => $currency,
                'value' => $amount,
            ],
        );

        try {
            $methods = PaymentMethod::query()->activeFor($region, $currency)->get();
            $selectedMethod = null;

            if ($request->filled('method')) {
                $selectedMethod = $methods->firstWhere('code', $request->string('method')->toString());
                throw_unless($selectedMethod, new RuntimeException('Выбранный способ оплаты недоступен для этого дерева.'));
            }

            if (! $selectedMethod && $methods->count() > 1) {
                return view('public.billing.choose', [
                    'tree' => $tree,
                    'plan' => $plan,
                    'methods' => $methods,
                    'amount' => $amount,
                    'currency' => $currency,
                    'footerPages' => collect(),
                ]);
            }

            return $billing->checkout($tree, $plan, $request->user(), $selectedMethod ?: $methods->first());
        } catch (Throwable $exception) {
            report($exception);

            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Не удалось открыть оплату. Проверьте настройки выбранного способа оплаты и попробуйте ещё раз.';

            return redirect('/manage/'.$tree->slug.'/subscriptions')
                ->with('status', $message);
        }
    }

    public function returned(Request $request, FamilyTree $tree): RedirectResponse
    {
        abort_unless($request->user()?->ownsTree($tree), 403);

        if ($request->filled('token')) {
            try {
                app(BillingService::class)->capturePayPalReturn($tree, $request->string('token')->toString());
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        app(AnalyticsService::class)->record(
            'checkout_return',
            $request,
            $request->user(),
            $tree,
            ['tree_id' => $tree->id, 'status' => $request->string('status')->toString()],
        );

        return redirect('/manage/'.$tree->slug.'/subscriptions')
            ->with('status', $request->string('status')->toString() === 'success'
                ? 'Платёж обрабатывается. Статус обновится после подтверждения провайдера.'
                : 'Оплата отменена.');
    }

    public function cloudpayments(Request $request, Payment $payment): RedirectResponse|View
    {
        $payment->loadMissing(['tree', 'plan', 'user']);
        $tree = $payment->tree;

        abort_unless($tree && $request->user()?->ownsTree($tree), 403);
        abort_unless($payment->provider === 'cloudpayments' && $payment->status === 'pending', 404);

        $method = PaymentMethod::query()
            ->activeFor($tree->billingRegion(), $tree->billingCurrency())
            ->where('provider', 'cloudpayments')
            ->first();
        $publicId = (string) ($method?->credential('public_id') ?: $method?->credential('shop_id'));

        if (! $method || $publicId === '') {
            return redirect('/manage/'.$tree->slug.'/payments')
                ->with('status', 'CloudPayments не настроен: укажите Public ID в способе оплаты.');
        }

        return view('public.billing.cloudpayments', [
            'payment' => $payment,
            'tree' => $tree,
            'plan' => $payment->plan,
            'publicId' => $publicId,
            'footerPages' => collect(),
            'seo' => [
                'title' => 'Оплата тарифа — Я и дом мой',
                'description' => 'Оплата семейного архива через CloudPayments.',
                'robots' => 'noindex, nofollow',
            ],
        ]);
    }
}
