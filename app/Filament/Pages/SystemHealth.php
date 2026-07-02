<?php

namespace App\Filament\Pages;

use App\Models\FamilyTree;
use App\Models\Subscription;
use App\Models\TelegramUpdate;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Мониторинг';

    protected static ?string $title = 'Состояние платформы';

    protected string $view = 'filament.pages.system-health';

    public array $metrics = [];

    public array $recentErrors = [];

    public function mount(): void
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $this->metrics = [
            'Свободно на диске' => is_numeric($free)
                ? number_format($free / 1073741824, 1, ',', ' ').' ГБ'
                : 'неизвестно',
            'Занято на диске' => is_numeric($free) && is_numeric($total)
                ? number_format(($total - $free) / 1073741824, 1, ',', ' ').' ГБ'
                : 'неизвестно',
            'Ошибки Telegram за сутки' => TelegramUpdate::query()
                ->whereNotNull('error')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'Неудачные задания' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->count()
                : 0,
            'Деревья к архивированию' => FamilyTree::query()
                ->whereNotNull('deletion_scheduled_at')
                ->count(),
            'Просроченные подписки' => Subscription::query()
                ->whereIn('status', ['past_due', 'expired'])
                ->count(),
        ];
        $this->recentErrors = TelegramUpdate::query()
            ->whereNotNull('error')
            ->latest('id')
            ->limit(20)
            ->get(['telegram_update_id', 'error', 'created_at'])
            ->map(fn (TelegramUpdate $update): array => [
                'id' => $update->telegram_update_id,
                'error' => $update->error,
                'created_at' => $update->created_at?->format('d.m.Y H:i:s'),
            ])
            ->all();
    }
}
