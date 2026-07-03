<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\FamilyTree;
use App\Models\Subscription;
use App\Models\TelegramUpdate;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $storage = (int) FamilyTree::query()->sum('storage_used_bytes');
        $expiring = Subscription::query()
            ->whereIn('status', ['trial', 'active'])
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(14)])
            ->count();
        $failedUpdates = TelegramUpdate::query()
            ->whereNotNull('error')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $freeBytes = @disk_free_space(storage_path());

        return [
            Stat::make('Семейные деревья', FamilyTree::query()->count())
                ->description('Активных: '.FamilyTree::query()->where('status', 'active')->count())
                ->url(FamilyTreeResource::getUrl('index')),
            Stat::make('Пользователи', User::query()->count())
                ->description('Активных: '.User::query()->where('is_active', true)->count())
                ->url(UserResource::getUrl('index')),
            Stat::make('Подписки', Subscription::query()->whereIn('status', ['trial', 'active'])->count())
                ->description("Заканчиваются за 14 дней: {$expiring}")
                ->url(SubscriptionResource::getUrl('index'))
                ->color($expiring > 0 ? 'warning' : 'success'),
            Stat::make('Хранилище', number_format($storage / 1073741824, 2, ',', ' ').' ГБ')
                ->description(is_numeric($freeBytes)
                    ? 'Свободно на сервере: '.number_format($freeBytes / 1073741824, 1, ',', ' ').' ГБ'
                    : 'Суммарно по всем деревьям'),
            Stat::make('Требуют внимания', FamilyTree::query()
                ->whereIn('status', ['suspended', 'archived'])
                ->count())
                ->description('Приостановленные и архивные деревья'),
            Stat::make('Системные ошибки', $failedUpdates)
                ->description('Ошибки Telegram за последние сутки')
                ->url(TelegramUpdateResource::getUrl('index'))
                ->color($failedUpdates > 0 ? 'danger' : 'success'),
        ];
    }
}
