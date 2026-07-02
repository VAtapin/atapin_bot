<?php

namespace App\Console\Commands;

use App\Models\Partnership;
use App\Models\Person;
use App\Models\TelegramGroup;
use App\Services\TelegramBot;
use App\Support\CurrentTree;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram:send-birthdays {--force : Ignore group hour and last-send date}')]
#[Description('Send today birthday notifications to approved family groups')]
class SendBirthdayNotifications extends Command
{
    public function handle(TelegramBot $bot): int
    {
        $failed = false;

        TelegramGroup::query()
            ->where('is_active', true)
            ->where('notify_birthdays', true)
            ->each(function (TelegramGroup $group) use ($bot, &$failed): void {
                app(CurrentTree::class)->set($group->tree);
                $now = now($group->timezone);

                if (! $this->option('force')) {
                    if ($now->hour !== $group->birthday_notification_hour) {
                        return;
                    }

                    if ($group->birthday_last_sent_on?->isSameDay($now)) {
                        return;
                    }
                }

                $people = Person::query()
                    ->where('is_published', true)
                    ->whereNull('death_date')
                    ->where('birth_date_precision', 'day')
                    ->whereMonth('birth_date', $now->month)
                    ->whereDay('birth_date', $now->day)
                    ->get();
                $anniversaries = Partnership::query()
                    ->with(['partnerOne', 'partnerTwo'])
                    ->whereMonth('started_at', $now->month)
                    ->whereDay('started_at', $now->day)
                    ->whereNull('ended_at')
                    ->whereIn('status', ['married', 'partners'])
                    ->get();

                if ($people->isEmpty() && $anniversaries->isEmpty()) {
                    $group->update(['birthday_last_sent_on' => $now->toDateString()]);

                    return;
                }

                $lines = $people->map(function (Person $person) use ($now): string {
                    $age = $person->birth_date?->diffInYears($now);
                    $ageText = $age ? " — исполняется {$age}" : '';

                    return '🎉 <b>'.e($person->full_name)."</b>{$ageText}";
                })->implode("\n");
                $anniversaryLines = $anniversaries->map(function (Partnership $partnership) use ($now): string {
                    $years = $partnership->started_at?->diffInYears($now);

                    return '💍 <b>'.e($partnership->partnerOne->full_name)
                        .' и '.e($partnership->partnerTwo->full_name)."</b> — {$years} лет";
                })->implode("\n");
                $sections = collect([
                    $lines !== '' ? "<b>Сегодня день рождения!</b>\n\n{$lines}\n\nПоздравляем! 🎂" : null,
                    $anniversaryLines !== '' ? "<b>Сегодня годовщина!</b>\n\n{$anniversaryLines} 💐" : null,
                ])->filter()->implode("\n\n");

                try {
                    $bot->sendMessage(
                        $group->telegram_chat_id,
                        $sections,
                    );
                    $group->update(['birthday_last_sent_on' => $now->toDateString()]);
                    $this->info("Sent to {$group->title}");
                } catch (Throwable $exception) {
                    report($exception);
                    $failed = true;
                    $this->error("{$group->title}: {$exception->getMessage()}");
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
