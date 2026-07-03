<?php

namespace App\Filament\Pages;

use App\Models\FamilyTree;
use App\Models\PaymentWebhookLog;
use App\Models\SmtpTestLog;
use App\Models\Subscription;
use App\Models\SystemHeartbeat;
use App\Models\TelegramUpdate;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
        $databaseOk = true;
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $databaseOk = false;
        }
        $cacheOk = true;
        try {
            Cache::put('system-health-check', 'ok', 30);
            $cacheOk = Cache::get('system-health-check') === 'ok';
        } catch (Throwable) {
            $cacheOk = false;
        }
        $scheduler = SystemHeartbeat::query()->where('key', 'scheduler')->first();
        $queue = SystemHeartbeat::query()->where('key', 'queue')->first();
        $smtp = SmtpTestLog::query()->latest('id')->first();
        $paymentWebhook = Schema::hasTable('payment_webhook_logs')
            ? PaymentWebhookLog::query()->latest('id')->first()
            : null;
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        $this->metrics = [
            'База данных' => $databaseOk ? 'работает' : 'ошибка',
            'Кеш' => $cacheOk ? 'работает' : 'ошибка',
            'Публичное хранилище' => is_link(public_path('storage')) || is_dir(public_path('storage'))
                ? 'подключено'
                : 'storage:link отсутствует',
            'GD / миниатюры' => function_exists('imagecreatefromstring') ? 'доступно' : 'PHP GD не установлен',
            'Планировщик' => $scheduler?->last_seen_at?->diffForHumans() ?: 'ещё не запускался',
            'Обработчик очереди' => $queue?->last_seen_at?->diffForHumans() ?: 'не подтверждён',
            'SMTP' => $smtp
                ? ($smtp->status === 'accepted' ? 'принято '.$smtp->created_at->diffForHumans() : 'ошибка: '.$smtp->stage)
                : 'не проверялся',
            'Telegram' => config('services.telegram.bot_token') ? 'токен задан' : 'токен отсутствует',
            'Платёжный webhook' => $paymentWebhook?->processed_at?->diffForHumans() ?: 'событий ещё нет',
            'Домены с ошибкой' => FamilyTree::query()
                ->whereNotNull('primary_domain')
                ->whereNotNull('domain_last_error')
                ->count(),
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
