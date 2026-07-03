<?php

namespace App\Services;

use App\Models\FamilyTree;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CustomTelegramBotService
{
    public function configure(FamilyTree $tree): array
    {
        abort_unless($tree->plan?->custom_bot, 403, 'Тариф не поддерживает собственного бота.');
        $token = (string) $tree->custom_bot_token;
        if ($token === '') {
            throw new RuntimeException('Сначала укажите токен бота.');
        }

        try {
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
                'commands' => $this->commands(),
            ]);
            $this->request($token, 'setChatMenuButton', [
                'menu_button' => ['type' => 'commands'],
            ]);
            $webhook = $this->request($token, 'getWebhookInfo');
            $lastError = trim((string) ($webhook['last_error_message'] ?? ''));
            $isActive = ($webhook['url'] ?? null) === $url && $lastError === '';

            $tree->update([
                'custom_bot_username' => $bot['username'] ?? null,
                'custom_bot_webhook_secret' => $secret,
                'custom_bot_verified_at' => now(),
                'custom_bot_status' => $isActive ? 'active' : 'warning',
                'custom_bot_last_error' => $lastError ?: null,
                'custom_bot_pending_updates' => (int) ($webhook['pending_update_count'] ?? 0),
                'custom_bot_checked_at' => now(),
            ]);

            return $bot;
        } catch (Throwable $exception) {
            try {
                $tree->updateQuietly([
                    'custom_bot_status' => 'error',
                    'custom_bot_last_error' => mb_substr($exception->getMessage(), 0, 2000),
                    'custom_bot_checked_at' => now(),
                ]);
            } catch (Throwable) {
                // Preserve the original Telegram or database exception.
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, array{command: string, description: string}>
     */
    public function commands(): array
    {
        return [
            ['command' => 'tree', 'description' => 'Открыть семейное дерево'],
            ['command' => 'list', 'description' => 'Список родственников'],
            ['command' => 'photos', 'description' => 'Семейные фотографии'],
            ['command' => 'person', 'description' => 'Найти человека'],
            ['command' => 'family', 'description' => 'Семейная ветвь человека'],
            ['command' => 'me', 'description' => 'Моя карточка и близкие'],
            ['command' => 'grandchildren', 'description' => 'Мои внуки'],
            ['command' => 'nephews', 'description' => 'Мои племянники'],
            ['command' => 'birthdays', 'description' => 'Ближайшие дни рождения'],
            ['command' => 'events', 'description' => 'Семейные события'],
            ['command' => 'stats', 'description' => 'Статистика архива'],
            ['command' => 'credentials', 'description' => 'Получить доступ к сайту'],
            ['command' => 'site', 'description' => 'Войти на сайт без пароля'],
            ['command' => 'help', 'description' => 'Все команды и подсказка'],
        ];
    }

    public function disconnect(FamilyTree $tree): void
    {
        $token = (string) $tree->custom_bot_token;
        if ($token === '') {
            return;
        }

        try {
            $this->request($token, 'deleteWebhook', ['drop_pending_updates' => false]);
            $tree->updateQuietly([
                'custom_bot_status' => 'disconnected',
                'custom_bot_checked_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function request(string $token, string $method, array $data = []): array
    {
        $response = Http::asJson()
            ->acceptJson()
            ->timeout(15)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $data);
        $payload = (array) $response->json();

        if (! $response->successful() || ! ($payload['ok'] ?? false)) {
            throw new RuntimeException(
                (string) ($payload['description'] ?? 'Telegram не ответил или отклонил запрос.'),
            );
        }

        return (array) ($payload['result'] ?? []);
    }
}
