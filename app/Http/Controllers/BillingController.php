<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Services\AnalyticsService;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function checkout(Request $request, FamilyTree $tree, Plan $plan, BillingService $billing): RedirectResponse
    {
        abort_unless($request->user()?->ownsTree($tree), 403);
        abort_unless($plan->is_active, 404);
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
                'currency' => $plan->currency,
                'value' => (float) $plan->price_monthly,
            ],
        );

        return $billing->checkout($tree, $plan, $request->user());
    }

    public function returned(Request $request, FamilyTree $tree): RedirectResponse
    {
        abort_unless($request->user()?->ownsTree($tree), 403);
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
}
