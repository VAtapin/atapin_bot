<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook {--url= : Public base URL of the application}')]
#[Description('Register the webhook and commands for the Telegram bot')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramBot $bot): int
    {
        $baseUrl = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $secret = (string) config('services.telegram.webhook_secret');

        if (! str_starts_with($baseUrl, 'https://')) {
            $this->error('Telegram requires a public HTTPS URL. Pass it with --url.');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET before registering the webhook.');

            return self::FAILURE;
        }

        $bot->request('setWebhook', [
            'url' => $baseUrl.'/api/telegram/webhook',
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'edited_message'],
            'drop_pending_updates' => false,
        ]);

        $bot->request('setMyCommands', [
            'commands' => [
                ['command' => 'tree', 'description' => 'Открыть семейное древо'],
                ['command' => 'birthdays', 'description' => 'Ближайшие дни рождения'],
                ['command' => 'help', 'description' => 'Список команд'],
            ],
        ]);

        $this->info('Webhook and bot commands registered.');

        return self::SUCCESS;
    }
}
