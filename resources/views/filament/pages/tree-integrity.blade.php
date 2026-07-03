<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500">Всего замечаний</div>
            <div class="mt-1 text-2xl font-semibold">{{ $report['summary']['total'] ?? 0 }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Ошибки</div>
            <div class="mt-1 text-2xl font-semibold text-danger-600">{{ $report['summary']['errors'] ?? 0 }}</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Предупреждения</div>
            <div class="mt-1 text-2xl font-semibold text-warning-600">{{ $report['summary']['warnings'] ?? 0 }}</div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Результат проверки">
        @forelse($report['issues'] ?? [] as $issue)
            <div class="border-b border-gray-200 py-3 last:border-0 dark:border-gray-700">
                <div class="font-medium">
                    {{ $issue['severity'] === 'error' ? 'Ошибка' : 'Предупреждение' }}
                    · {{ $issue['type'] }}
                </div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $issue['message'] }}</div>
            </div>
        @empty
            <p class="text-success-600">Проблем целостности не обнаружено.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
