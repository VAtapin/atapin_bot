<?php

namespace Tests\Feature;

use App\Models\TelegramGroup;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.webhook_secret', 'test-secret');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->postJson('/api/telegram/webhook', ['update_id' => 1])
            ->assertForbidden();
    }

    public function test_webhook_registers_pending_user_and_group(): void
    {
        $payload = [
            'update_id' => 1001,
            'message' => [
                'message_id' => 10,
                'text' => '/start',
                'from' => [
                    'id' => 321,
                    'first_name' => 'Иван',
                    'username' => 'ivan',
                    'language_code' => 'ru',
                ],
                'chat' => [
                    'id' => -100123,
                    'type' => 'supergroup',
                    'title' => 'Большая семья',
                ],
            ],
        ];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas(TelegramUser::class, [
            'telegram_user_id' => 321,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas(TelegramGroup::class, [
            'telegram_chat_id' => -100123,
            'is_active' => false,
        ]);
        $this->assertNotNull(
            TelegramUpdate::query()->where('telegram_update_id', 1001)->value('processed_at'),
        );

        Http::assertSentCount(1);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', $payload)
            ->assertOk();

        Http::assertSentCount(1);
    }
}
