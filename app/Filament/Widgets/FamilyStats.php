<?php

namespace App\Filament\Widgets;

use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\TelegramGroup;
use App\Models\TelegramUser;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FamilyStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Людей в древе', Person::query()->where('is_published', true)->count())
                ->description('Всего карточек: '.Person::query()->count()),
            Stat::make('Ожидают доступа', TelegramUser::query()->where('status', 'pending')->count())
                ->description('Заявки пользователей Telegram')
                ->color(TelegramUser::query()->where('status', 'pending')->exists() ? 'warning' : 'success'),
            Stat::make('Активные группы', TelegramGroup::query()->where('is_active', true)->count())
                ->description('Подтверждённые семейные чаты'),
            Stat::make('События', FamilyEvent::query()->where('is_published', true)->count())
                ->description('Опубликованные семейные даты'),
        ];
    }
}
