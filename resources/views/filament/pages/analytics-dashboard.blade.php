<x-filament-panels::page>
    <x-filament::section heading="Фильтры">
        <form method="get" class="grid gap-3 md:grid-cols-3 xl:grid-cols-4">
            <label class="grid gap-1 text-sm">
                <span>Период</span>
                <select name="period" class="rounded-lg border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    @foreach(['7' => '7 дней', '30' => '30 дней', '90' => '90 дней', '365' => '1 год'] as $value => $label)
                        <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm"><span>UTM source</span><input name="utm_source" value="{{ $utmSource }}" list="utm-sources" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></label>
            <label class="grid gap-1 text-sm"><span>UTM medium</span><input name="utm_medium" value="{{ $utmMedium }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></label>
            <label class="grid gap-1 text-sm"><span>UTM campaign</span><input name="utm_campaign" value="{{ $utmCampaign }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></label>
            <label class="grid gap-1 text-sm"><span>Landing page</span><input name="landing_page" value="{{ $landingPage }}" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></label>
            <label class="grid gap-1 text-sm">
                <span>Источник трафика</span>
                <select name="traffic_source" class="rounded-lg border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Все</option>
                    <option value="direct" @selected($trafficSource === 'direct')>Прямой</option>
                    <option value="referral" @selected($trafficSource === 'referral')>Переход с сайта</option>
                    <option value="utm" @selected($trafficSource === 'utm')>UTM / реклама</option>
                </select>
            </label>
            <label class="grid gap-1 text-sm">
                <span>Платформа</span>
                <select name="platform" class="rounded-lg border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Все</option>
                    @foreach(['web' => 'Web', 'telegram' => 'Telegram', 'vk' => 'VK', 'ok' => 'OK', 'max' => 'MAX'] as $value => $label)
                        <option value="{{ $value }}" @selected($platform === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1 text-sm"><span>Событие</span><input name="event" value="{{ $eventName }}" list="event-names" class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900"></label>
            <label class="grid gap-1 text-sm">
                <span>Тариф</span>
                <select name="plan_id" class="rounded-lg border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Все</option>
                    @foreach($plans as $id => $name)<option value="{{ $id }}" @selected($planId === (string) $id)>{{ $name }}</option>@endforeach
                </select>
            </label>
            <div class="flex items-end gap-2">
                <x-filament::button type="submit">Применить</x-filament::button>
                <x-filament::button color="gray" tag="a" href="{{ url()->current() }}">Сбросить</x-filament::button>
            </div>
        </form>
        <datalist id="utm-sources">@foreach($utmSources as $source)<option value="{{ $source }}">@endforeach</datalist>
        <datalist id="event-names">@foreach($eventNames as $name)<option value="{{ $name }}">@endforeach</datalist>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach($metrics as $label => $value)
            <x-filament::section><div class="text-sm text-gray-500">{{ $label }}</div><div class="mt-1 text-2xl font-semibold">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach($conversions as $label => $value)
            <x-filament::section><div class="text-sm text-gray-500">{{ $label }}</div><div class="mt-1 text-2xl font-semibold text-primary-600">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Динамика по дням">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b dark:border-gray-700"><th class="p-2">День</th><th class="p-2">Визиты</th><th class="p-2">Регистрации</th><th class="p-2">Деревья</th><th class="p-2">Оплаты</th><th class="p-2">Выручка</th></tr></thead>
                <tbody>@forelse($daily as $row)<tr class="border-b last:border-0 dark:border-gray-800"><td class="p-2">{{ $row['day'] }}</td><td class="p-2">{{ $row['visits'] }}</td><td class="p-2">{{ $row['registrations'] }}</td><td class="p-2">{{ $row['trees'] }}</td><td class="p-2">{{ $row['payments'] }}</td><td class="p-2">{{ number_format($row['revenue'], 2, ',', ' ') }}</td></tr>@empty<tr><td class="p-4 text-gray-500" colspan="6">Данных пока нет.</td></tr>@endforelse</tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Лучшие источники и кампании">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b dark:border-gray-700"><th class="p-2">Источник</th><th class="p-2">Кампания</th><th class="p-2">Визиты</th><th class="p-2">Регистрации</th><th class="p-2">Конверсия</th><th class="p-2">5+ людей</th><th class="p-2">Оплаты</th><th class="p-2">Выручка</th></tr></thead>
                <tbody>@forelse($campaigns as $row)<tr class="border-b last:border-0 dark:border-gray-800"><td class="p-2">{{ $row->source }}</td><td class="p-2">{{ $row->campaign }}</td><td class="p-2">{{ $row->visits }}</td><td class="p-2">{{ $row->registrations }}</td><td class="p-2">{{ $row->visits > 0 ? number_format($row->registrations / $row->visits * 100, 1, ',', ' ').'%' : '0%' }}</td><td class="p-2">{{ $row->quality }}</td><td class="p-2">{{ $row->purchases }}</td><td class="p-2">{{ number_format($row->revenue, 2, ',', ' ') }}</td></tr>@empty<tr><td class="p-4 text-gray-500" colspan="8">Данных пока нет.</td></tr>@endforelse</tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section heading="Пользователи с качественными действиями">
            @forelse($qualityUsers as $item)<div class="flex justify-between border-b py-2 last:border-0 dark:border-gray-800"><span>User #{{ $item->user_id }} · {{ $item->user?->name }}</span><strong>{{ $item->actions }}</strong></div>@empty<p class="text-sm text-gray-500">Данных пока нет.</p>@endforelse
        </x-filament::section>
        <x-filament::section heading="Самые активные деревья">
            @forelse($activeTrees as $item)<div class="flex justify-between border-b py-2 last:border-0 dark:border-gray-800"><span>{{ $item->tree?->name ?: 'Дерево #'.$item->tree_id }}</span><strong>{{ $item->actions }}</strong></div>@empty<p class="text-sm text-gray-500">Данных пока нет.</p>@endforelse
        </x-filament::section>
    </div>

    <x-filament::section heading="Последние события">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b dark:border-gray-700"><th class="p-2">Время</th><th class="p-2">Событие</th><th class="p-2">Платформа</th><th class="p-2">Источник</th><th class="p-2">Дерево</th></tr></thead>
                <tbody>@forelse($latestEvents as $event)<tr class="border-b last:border-0 dark:border-gray-800"><td class="p-2">{{ $event->occurred_at?->format('d.m.Y H:i') }}</td><td class="p-2">{{ $event->event_name }}</td><td class="p-2">{{ $event->platform }}</td><td class="p-2">{{ $event->utm_source ?: 'direct' }}</td><td class="p-2">{{ $event->tree?->name }}</td></tr>@empty<tr><td class="p-4 text-gray-500" colspan="5">Событий пока нет.</td></tr>@endforelse</tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
