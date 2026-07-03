<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DataIssues\DataIssueResource;
use App\Filament\Resources\FamilyEvents\FamilyEventResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\TelegramGroups\TelegramGroupResource;
use App\Filament\Resources\TreeMemberships\TreeMembershipResource;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\TelegramGroup;
use App\Models\TreeMembership;
use App\Support\CurrentTree;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FamilyStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $treeId = app(CurrentTree::class)->id();
        $pendingMemberships = TreeMembership::query()
            ->where('tree_id', $treeId)
            ->where('status', 'pending');
        $tree = app(CurrentTree::class)->get();
        $limit = (int) ($tree?->plan?->storage_limit_bytes ?? 0);
        $used = (int) ($tree?->storage_used_bytes ?? 0);
        $percent = $limit > 0 ? min(100, (int) round($used / $limit * 100)) : 0;

        return [
            Stat::make('Людей в древе', Person::query()->where('is_published', true)->count())
                ->description('Всего карточек: '.Person::query()->count())
                ->url(PersonResource::getUrl('index', tenant: $tree)),
            Stat::make('Ожидают доступа', (clone $pendingMemberships)->count())
                ->description('Заявки участников')
                ->url(TreeMembershipResource::getUrl('index', tenant: $tree))
                ->color((clone $pendingMemberships)->exists() ? 'warning' : 'success'),
            Stat::make('Активные группы', TelegramGroup::query()->where('is_active', true)->count())
                ->description('Подтверждённые семейные чаты')
                ->url(TelegramGroupResource::getUrl('index', tenant: $tree)),
            Stat::make('События', FamilyEvent::query()->where('is_published', true)->count())
                ->description('Опубликованные семейные даты')
                ->url(FamilyEventResource::getUrl('index', tenant: $tree)),
            Stat::make('Хранилище', number_format($used / 1048576, 1, ',', ' ').' МБ')
                ->description($limit > 0
                    ? "{$percent}% из ".number_format($limit / 1048576, 0, ',', ' ').' МБ'
                    : 'Без установленной квоты')
                ->color($percent >= 90 ? 'danger' : ($percent >= 80 ? 'warning' : 'success'))
                ->url(PersonResource::getUrl('index', tenant: $tree)),
            Stat::make('Сообщения об ошибках', $tree?->dataIssues()->where('status', 'open')->count() ?? 0)
                ->description('Открытые обращения семьи')
                ->url(DataIssueResource::getUrl('index', tenant: $tree)),
        ];
    }
}
