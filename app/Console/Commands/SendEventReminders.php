<?php

namespace App\Console\Commands;

use App\Models\ExternalIdentity;
use App\Models\FamilyEvent;
use App\Models\FamilyTree;
use App\Services\TelegramBot;
use App\Support\CurrentTree;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders';

    protected $description = 'Send Telegram reminders for upcoming family events';

    public function handle(TelegramBot $bot): int
    {
        FamilyTree::query()->where('status', 'active')->each(function (FamilyTree $tree) use ($bot): void {
            app(CurrentTree::class)->set($tree);
            $now = now($tree->timezone);

            FamilyEvent::query()
                ->where('is_published', true)
                ->whereNotNull('reminder_minutes')
                ->get()
                ->each(function (FamilyEvent $event) use ($tree, $bot, $now): void {
                    $occurrence = Carbon::parse(
                        $event->event_date->format('Y-m-d').' '.($event->event_time ?: '09:00:00'),
                        $tree->timezone,
                    );
                    if ($event->is_annual) {
                        $occurrence->year($now->year);
                        if ($occurrence->lt($now)) {
                            $occurrence->addYear();
                        }
                    }
                    $sendAt = $occurrence->copy()->subMinutes((int) $event->reminder_minutes);
                    if (! $sendAt->between($now->copy()->subMinutes(5), $now->copy()->addMinutes(5))) {
                        return;
                    }

                    $tree->memberships()->where('status', 'approved')->pluck('user_id')
                        ->each(function (int $userId) use ($event, $occurrence, $bot): void {
                            $created = DB::table('family_event_reminders')->insertOrIgnore([
                                'family_event_id' => $event->id,
                                'user_id' => $userId,
                                'occurrence_at' => $occurrence->utc()->format('Y-m-d H:i:s'),
                                'sent_at' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            if (! $created) {
                                return;
                            }
                            $telegramId = ExternalIdentity::query()
                                ->where('user_id', $userId)
                                ->where('provider', 'telegram')
                                ->value('provider_user_id');
                            if (! $telegramId) {
                                return;
                            }
                            try {
                                $bot->sendMessage(
                                    $telegramId,
                                    '📅 <b>'.e($event->title).'</b>'."\n"
                                    .$occurrence->translatedFormat('d F Y, H:i')
                                    .($event->place ? "\n📍 ".e($event->place) : ''),
                                );
                            } catch (Throwable $exception) {
                                report($exception);
                            }
                        });
                });
        });

        return self::SUCCESS;
    }
}
