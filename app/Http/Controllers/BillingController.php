<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Services\BillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function checkout(Request $request, FamilyTree $tree, Plan $plan, BillingService $billing): RedirectResponse
    {
        abort_unless($request->user()?->ownsTree($tree), 403);
        abort_unless($plan->is_active, 404);

        return $billing->checkout($tree, $plan, $request->user());
    }

    public function returned(Request $request, FamilyTree $tree): RedirectResponse
    {
        abort_unless($request->user()?->ownsTree($tree), 403);

        return redirect('/manage/'.$tree->slug.'/subscriptions')
            ->with('status', $request->string('status')->toString() === 'success'
                ? 'Платёж обрабатывается. Статус обновится после подтверждения провайдера.'
                : 'Оплата отменена.');
    }
}
