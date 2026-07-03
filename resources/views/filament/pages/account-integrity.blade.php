<x-filament-panels::page>
    <x-filament::section heading="Найденные проблемы">
        @forelse($report['issues'] ?? [] as $issue)
            <div class="border-b border-gray-200 py-3 last:border-0 dark:border-gray-700">
                <div class="font-medium">{{ $issue['type'] }}</div>
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $issue['message'] }}</div>
            </div>
        @empty
            <p class="text-success-600">Ссылки учётных записей согласованы.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
