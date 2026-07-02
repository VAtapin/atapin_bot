<?php

namespace App\Console\Commands;

use App\Models\FamilyTree;
use App\Models\Subscription;
use App\Models\TelegramUpdate;
use App\Services\FamilyNotificationService;
use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorPlatform extends Command
{
    protected $signature = 'platform:monitor';

    protected $description = 'Monitor storage, failed updates and dormant family trees';

    public function handle(
        TelegramBot $bot,
        FamilyNotificationService $notifications,
    ): int {
        $problems = [];
        $free = @disk_free_space(storage_path());
        if (is_numeric($free) && $free < 2 * 1024 * 1024 * 1024) {
            $problems[] = 'На сервере осталось менее 2 ГБ свободного места.';
        }
        $failedUpdates = TelegramUpdate::query()
            ->whereNotNull('error')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($failedUpdates > 0) {
            $problems[] = "Ошибок Telegram за сутки: {$failedUpdates}.";
        }

        FamilyTree::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('last_activity_at')
                ->orWhere(
                    'last_activity_at',
                    '<',
                    now()->subDays(config('platform.dormant_tree_days')),
                ))
            ->each(function (FamilyTree $tree) use ($notifications): void {
                $settings = $tree->settings ?? [];

                if (! $tree->deletion_scheduled_at) {
                    $tree->update([
                        'deletion_scheduled_at' => now()->addDays(config('platform.dormant_warning_days')),
                        'settings' => [...$settings, 'dormant_warning_30_sent_at' => now()->toIso8601String()],
                    ]);
                    $notifications->notifyManagers(
                        $tree,
                        '⚠️ Дерево давно не использовалось. Через '
                        .config('platform.dormant_warning_days')
                        .' дней оно будет архивировано, если никто не войдёт.',
                    );

                    return;
                }

                $days = now()->diffInDays($tree->deletion_scheduled_at, false);
                if ($days <= 7 && empty($settings['dormant_warning_7_sent_at'])) {
                    $tree->update(['settings' => [...$settings, 'dormant_warning_7_sent_at' => now()->toIso8601String()]]);
                    $notifications->notifyManagers(
                        $tree,
                        "⚠️ До архивирования семейного дерева осталось {$days} дней.",
                    );
                }

                if ($tree->deletion_scheduled_at->isPast()) {
                    $tree->update(['status' => 'archived']);
                    $tree->delete();
                }
            });

        Subscription::query()
            ->with('tree')
            ->whereIn('status', ['trial', 'active'])
            ->whereBetween('ends_at', [now(), now()->addDays(7)])
            ->get()
            ->each(function (Subscription $subscription) use ($notifications): void {
                if (Cache::add("subscription-warning:{$subscription->id}", true, now()->addDay())) {
                    $notifications->notifyManagers(
                        $subscription->tree,
                        '💳 Подписка семейного дерева заканчивается '
                        .$subscription->ends_at->translatedFormat('d.m.Y').'.',
                    );
                }
            });

        if ($problems !== []) {
            $text = "🚨 <b>Мониторинг «Я и дом мой»</b>\n\n".implode("\n", $problems);
            foreach (config('services.telegram.admin_ids', []) as $adminId) {
                try {
                    $bot->sendMessage($adminId, $text);
                } catch (Throwable $exception) {
                    Log::error($exception->getMessage());
                }
            }
        }

        $this->info($problems === [] ? 'Platform is healthy.' : implode(' ', $problems));

        return self::SUCCESS;
    }
}
