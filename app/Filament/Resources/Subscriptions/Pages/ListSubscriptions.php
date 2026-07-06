<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Support\CurrentTree;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    public function getSubheading(): ?string
    {
        $tree = app(CurrentTree::class)->get()?->fresh(['plan', 'subscriptions.plan']);
        $plan = $tree?->effectivePlan();

        if (! $tree || ! $plan) {
            return null;
        }

        $peopleCount = $tree->people()->count();
        $storageUsed = number_format(((int) $tree->storage_used_bytes) / 1048576, 0, ',', ' ').' МБ';
        $storageLimit = $plan->storage_limit_bytes >= 1073741824
            ? number_format($plan->storage_limit_bytes / 1073741824, 1, ',', ' ').' ГБ'
            : number_format($plan->storage_limit_bytes / 1048576, 0, ',', ' ').' МБ';

        $hasHigherPlan = Plan::query()
            ->with('prices')
            ->where('is_active', true)
            ->get()
            ->contains(fn (Plan $candidate): bool => $candidate->priceAmountFor(
                $tree->billingRegion(),
                $tree->billingCurrency(),
            ) > $plan->priceAmountFor($tree->billingRegion(), $tree->billingCurrency()));

        return "Текущий тариф: {$plan->name}. Людей: {$peopleCount} из {$plan->people_limit}. "
            ."Фото и файлы: {$storageUsed} из {$storageLimit}."
            .($hasHigherPlan ? ' Если места мало — выберите тариф выше.' : '');
    }

    protected function getHeaderActions(): array
    {
        $tree = app(CurrentTree::class)->get()?->fresh(['plan', 'subscriptions.plan']);
        $user = auth()->user();

        if (! $tree || ! $user?->ownsTree($tree) || ! PlatformSetting::value('billing_enabled', false)) {
            return [];
        }

        $currentSubscription = $tree->currentSubscription();
        $currentPlan = $tree->effectivePlan();

        $region = $tree->billingRegion();
        $currency = $tree->billingCurrency();

        return Plan::query()
            ->with('prices')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price_monthly')
            ->get()
            ->filter(fn (Plan $plan): bool => $plan->priceAmountFor($region, $currency) > 0.0)
            ->map(function (Plan $plan) use ($tree, $currentPlan, $currentSubscription): Action {
                $region = $tree->billingRegion();
                $currency = $tree->billingCurrency();
                $amount = $plan->priceAmountFor($region, $currency);
                $isCurrent = (int) $currentPlan?->id === (int) $plan->id;
                $canPayCurrent = $isCurrent
                    && $amount > 0.0
                    && $currentSubscription
                    && in_array($currentSubscription->status, ['trial', 'past_due'], true)
                    && blank($currentSubscription->provider_reference);
                $storage = $plan->storage_limit_bytes >= 1073741824
                    ? number_format($plan->storage_limit_bytes / 1073741824, 1, ',', ' ').' ГБ'
                    : number_format($plan->storage_limit_bytes / 1048576, 0, ',', ' ').' МБ';

                return Action::make('upgrade_to_plan_'.$plan->id)
                    ->label($canPayCurrent
                        ? 'Оплатить «'.$plan->name.'»'
                        : ($isCurrent
                            ? 'Текущий тариф: '.$plan->name
                            : 'Перейти на «'.$plan->name.'» — '.number_format($amount, 2, ',', ' ').' '.$currency.'/мес.'))
                    ->icon($isCurrent && ! $canPayCurrent ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-up-circle')
                    ->color($isCurrent && ! $canPayCurrent ? 'gray' : 'success')
                    ->disabled($isCurrent && ! $canPayCurrent)
                    ->tooltip($plan->people_limit.' человек, '.$storage.', '
                        .($plan->custom_bot ? 'свой бот, ' : '')
                        .($plan->custom_domain ? 'свой домен' : 'семейный архив'))
                    ->url($isCurrent && ! $canPayCurrent ? null : route('billing.checkout', [
                        'tree' => $tree,
                        'plan' => $plan,
                    ]));
            })
            ->all();
    }
}
