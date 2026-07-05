<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBot
{
    private bool $hasExplicitBot = false;

    private ?FamilyTree $explicitTree = null;

    /**
     * Keep replies in the same bot that received the webhook.
     * A null tree explicitly means the platform bot.
     */
    public function respondAs(?FamilyTree $tree): static
    {
        $this->hasExplicitBot = true;
        $this->explicitTree = $tree;

        return $this;
    }

    public function isRespondingAsPlatformBot(): bool
    {
        return $this->hasExplicitBot && ! $this->explicitTree;
    }

    public function responseUsername(): string
    {
        $tree = $this->hasExplicitBot
            ? $this->explicitTree
            : app(CurrentTree::class)->get();

        return ltrim((string) (
            $tree?->custom_bot_verified_at && $tree?->custom_bot_username
                ? $tree->custom_bot_username
                : config('services.telegram.bot_username')
        ), '@');
    }

    public function request(string $method, array $data = []): mixed
    {
        $tree = $this->hasExplicitBot
            ? $this->explicitTree
            : app(CurrentTree::class)->get();
        $token = (string) (
            $tree?->custom_bot_verified_at
                && $tree?->custom_bot_token
                ? $tree->custom_bot_token
                : config('services.telegram.bot_token')
        );

        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $response = $this->client()->post(
            "https://api.telegram.org/bot{$token}/{$method}",
            $data,
        );

        $response->throw();
        $payload = $response->json();

        if (! ($payload['ok'] ?? false)) {
            throw new RuntimeException($payload['description'] ?? 'Telegram API request failed.');
        }

        return $payload['result'] ?? [];
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        return $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            ...$options,
        ]);
    }

    private function client(): PendingRequest
    {
        return Http::asJson()->acceptJson()->timeout(15)->retry(2, 250);
    }
}
