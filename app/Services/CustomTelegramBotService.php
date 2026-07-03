<?php

namespace App\Services;

use App\Models\FamilyTree;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CustomTelegramBotService
{
    public function configure(FamilyTree $tree): array
    {
        abort_unless($tree->plan?->custom_bot, 403, 'Тариф не поддерживает собственного бота.');
        $token = (string) $tree->custom_bot_token;
        if ($token === '') {
            throw new RuntimeException('Сначала укажите токен бота.');
        }

        $bot = $this->request($token, 'getMe');
        $secret = $tree->custom_bot_webhook_secret ?: Str::random(48);
        $url = route('telegram.custom-webhook', ['tree' => $tree->slug]);

        $this->request($token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'edited_message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
        $this->request($token, 'setMyCommands', [
            'commands' => [
                ['command' => 'tree', 'description' => 'Открыть семейное дерево'],
                ['command' => 'person', 'description' => 'Найти человека'],
                ['command' => 'events', 'description' => 'Семейные события'],
                ['command' => 'birthdays', 'description' => 'Ближайшие дни рождения'],
                ['command' => 'site', 'description' => 'Перейти на сайт'],
                ['command' => 'help', 'description' => 'Список команд'],
            ],
        ]);
        $this->request($token, 'setChatMenuButton', [
            'menu_button' => [
                'type' => 'web_app',
                'text' => 'Открыть семейное дерево',
                'web_app' => ['url' => route('family.tree', $tree)],
            ],
        ]);

        $tree->update([
            'custom_bot_username' => $bot['username'] ?? null,
            'custom_bot_webhook_secret' => $secret,
            'custom_bot_verified_at' => now(),
        ]);

        return $bot;
    }

    private function request(string $token, string $method, array $data = []): array
    {
        $response = Http::asJson()
            ->acceptJson()
            ->timeout(15)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $data)
            ->throw()
            ->json();
        if (! ($response['ok'] ?? false)) {
            throw new RuntimeException($response['description'] ?? 'Telegram отклонил запрос.');
        }

        return (array) ($response['result'] ?? []);
    }
}
