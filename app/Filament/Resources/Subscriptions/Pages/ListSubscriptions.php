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
        $tree = app(CurrentTree::class)->get()?->fresh('plan');

        if (! $tree?->plan) {
            return null;
        }

        $peopleCount = $tree->people()->count();
        $storageUsed = number_format(((int) $tree->storage_used_bytes) / 1048576, 0, ',', ' ').' МБ';
        $storageLimit = $tree->plan->storage_limit_bytes >= 1073741824
            ? number_format($tree->plan->storage_limit_bytes / 1073741824, 1, ',', ' ').' ГБ'
            : number_format($tree->plan->storage_limit_bytes / 1048576, 0, ',', ' ').' МБ';

        return "Текущий тариф: {$tree->plan->name}. Людей: {$peopleCount} из {$tree->plan->people_limit}. "
            ."Фото и файлы: {$storageUsed} из {$storageLimit}. Если места мало — выберите платный тариф выше.";
    }

    protected function getHeaderActions(): array
    {
        $tree = app(CurrentTree::class)->get();
        $user = auth()->user();

        if (! $tree || ! $user?->ownsTree($tree) || ! PlatformSetting::value('billing_enabled', false)) {
            return [];
        }

        return Plan::query()
            ->where('is_active', true)
            ->where('price_monthly', '>', 0)
            ->orderBy('sort_order')
            ->orderBy('price_monthly')
            ->get()
            ->map(function (Plan $plan) use ($tree): Action {
                $isCurrent = (int) $tree->plan_id === (int) $plan->id;
                $storage = $plan->storage_limit_bytes >= 1073741824
                    ? number_format($plan->storage_limit_bytes / 1073741824, 1, ',', ' ').' ГБ'
                    : number_format($plan->storage_limit_bytes / 1048576, 0, ',', ' ').' МБ';

                return Action::make('upgrade_to_plan_'.$plan->id)
                    ->label($isCurrent
                        ? 'Текущий тариф: '.$plan->name
                        : 'Перейти на «'.$plan->name.'»')
                    ->icon($isCurrent ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-up-circle')
                    ->color($isCurrent ? 'gray' : 'success')
                    ->disabled($isCurrent)
                    ->tooltip($plan->people_limit.' человек, '.$storage.', '
                        .($plan->custom_bot ? 'свой бот, ' : '')
                        .($plan->custom_domain ? 'свой домен' : 'семейный архив'))
                    ->url($isCurrent ? null : route('billing.checkout', [
                        'tree' => $tree,
                        'plan' => $plan,
                    ]));
            })
            ->all();
    }
}
