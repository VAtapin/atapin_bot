<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($metrics as $label => $value)
            <x-filament::section>
                <div class="text-sm text-gray-500">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Последние ошибки Telegram">
        @forelse($recentErrors as $error)
            <div class="border-b border-gray-200 py-3 last:border-0 dark:border-gray-700">
                <div class="text-sm font-semibold">
                    Update {{ $error['id'] }} · {{ $error['created_at'] }}
                </div>
                <div class="mt-1 break-words text-sm text-danger-600">{{ $error['error'] }}</div>
            </div>
        @empty
            <p class="text-sm text-gray-500">Ошибок не найдено.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
