<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\TelegramGroup;
use App\Services\TelegramBot;
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
                    ->whereMonth('birth_date', $now->month)
                    ->whereDay('birth_date', $now->day)
                    ->get();

                if ($people->isEmpty()) {
                    $group->update(['birthday_last_sent_on' => $now->toDateString()]);

                    return;
                }

                $lines = $people->map(function (Person $person) use ($now): string {
                    $age = $person->birth_date?->diffInYears($now);
                    $ageText = $age ? " — исполняется {$age}" : '';

                    return '🎉 <b>'.e($person->full_name)."</b>{$ageText}";
                })->implode("\n");

                try {
                    $bot->sendMessage(
                        $group->telegram_chat_id,
                        "<b>Сегодня день рождения!</b>\n\n{$lines}\n\nПоздравляем! 🎂",
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
