<?php

namespace Tests\Feature;

use App\Models\Person;
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

    public function test_person_command_waits_for_the_next_message(): void
    {
        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321,
            'first_name' => 'Иван',
            'status' => 'approved',
        ]);
        Person::factory()->create(['first_name' => 'Анатолий', 'last_name' => 'Атапин']);

        $this->sendPrivateMessage(2001, '/person');
        $this->assertSame('person', $user->fresh()->pending_command);

        $this->sendPrivateMessage(2002, 'Анатолий');
        $this->assertNull($user->fresh()->pending_command);

        $requests = Http::recorded();
        $this->assertStringContainsString(
            'Напишите следующим сообщением',
            $requests[0][0]->data()['text'],
        );
        $this->assertSame(
            route('family.person', Person::query()->first()->id),
            $requests[1][0]->data()['reply_markup']['inline_keyboard'][0][0]['web_app']['url'],
        );
    }

    public function test_admin_can_approve_user_from_inline_button(): void
    {
        config()->set('services.telegram.admin_ids', ['999']);
        $target = TelegramUser::query()->create([
            'telegram_user_id' => 321,
            'status' => 'pending',
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 3001,
                'callback_query' => [
                    'id' => 'callback-1',
                    'from' => ['id' => 999, 'first_name' => 'Админ'],
                    'data' => 'access:approve:'.$target->id,
                    'message' => [
                        'message_id' => 55,
                        'chat' => ['id' => 999, 'type' => 'private'],
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame('approved', $target->fresh()->status);
    }

    private function sendPrivateMessage(int $updateId, string $text): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => $updateId,
                'message' => [
                    'message_id' => $updateId,
                    'text' => $text,
                    'from' => [
                        'id' => 321,
                        'first_name' => 'Иван',
                        'language_code' => 'ru',
                    ],
                    'chat' => ['id' => 321, 'type' => 'private'],
                ],
            ])
            ->assertOk();
    }
}
