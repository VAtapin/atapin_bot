<?php

namespace Tests\Feature;

use App\Models\ExternalIdentity;
use App\Models\TelegramAccountLinkToken;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\TelegramAccountLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.bot_username', 'idommoy_bot');
        config()->set('services.telegram.webhook_secret', 'test-secret');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    public function test_authenticated_user_receives_short_one_time_bot_link(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/account/telegram/connect');

        $response->assertRedirect();
        $this->assertMatchesRegularExpression(
            '~^https://t\.me/idommoy_bot\?start=link_[a-z0-9]{32}$~',
            $response->headers->get('Location'),
        );
        $this->assertDatabaseCount(TelegramAccountLinkToken::class, 1);
    }

    public function test_start_link_command_connects_telegram_to_existing_account(): void
    {
        $target = User::factory()->create();
        $url = app(TelegramAccountLinkService::class)->createDeepLink($target);
        preg_match('/start=link_([a-z0-9]{32})$/', $url, $matches);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 71001,
                'message' => [
                    'message_id' => 10,
                    'text' => '/start link_'.$matches[1],
                    'from' => [
                        'id' => 887766,
                        'first_name' => 'Иван',
                        'username' => 'ivan',
                        'language_code' => 'ru',
                    ],
                    'chat' => [
                        'id' => 887766,
                        'type' => 'private',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $telegramUser = TelegramUser::query()
            ->where('telegram_user_id', 887766)
            ->firstOrFail();
        $this->assertSame($target->id, $telegramUser->user_id);
        $this->assertDatabaseHas(ExternalIdentity::class, [
            'user_id' => $target->id,
            'provider' => 'telegram',
            'provider_user_id' => '887766',
        ]);
        $this->assertNotNull(TelegramAccountLinkToken::query()->firstOrFail()->used_at);

        $sentText = Http::recorded()->last()[0]->data()['text'];
        $this->assertStringContainsString('Telegram подключён', $sentText);
    }
}
