<?php

namespace Tests\Feature;

use App\Models\ParentChild;
use App\Models\Person;
use App\Models\TelegramUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MiniAppTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-bot-token';

    public function test_family_data_requires_valid_telegram_init_data(): void
    {
        $this->getJson('/api/family/tree')
            ->assertUnauthorized();
    }

    public function test_approved_user_can_filter_and_read_family_tree(): void
    {
        config()->set('services.telegram.bot_token', self::TOKEN);

        TelegramUser::query()->create([
            'telegram_user_id' => 42,
            'first_name' => 'Анна',
            'status' => 'approved',
        ]);

        $mother = Person::factory()->create([
            'first_name' => 'Анна',
            'last_name' => 'Атапина',
            'gender' => 'female',
        ]);
        $child = Person::factory()->create([
            'first_name' => 'Иван',
            'last_name' => 'Атапин',
            'gender' => 'male',
        ]);
        ParentChild::query()->create([
            'parent_id' => $mother->id,
            'child_id' => $child->id,
            'type' => 'biological',
        ]);

        $this->withHeader('X-Telegram-Init-Data', $this->signedInitData(42))
            ->getJson('/api/family/tree?gender=female')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.name', 'Атапина Анна')
            ->assertJsonCount(0, 'parent_child');

        $this->withHeader('X-Telegram-Init-Data', $this->signedInitData(42))
            ->getJson('/api/family/tree')
            ->assertOk()
            ->assertJsonCount(2, 'people')
            ->assertJsonCount(1, 'parent_child');
    }

    public function test_pending_user_cannot_read_family_data(): void
    {
        config()->set('services.telegram.bot_token', self::TOKEN);

        TelegramUser::query()->create([
            'telegram_user_id' => 42,
            'status' => 'pending',
        ]);

        $this->withHeader('X-Telegram-Init-Data', $this->signedInitData(42))
            ->getJson('/api/family/tree')
            ->assertForbidden()
            ->assertJsonPath('status', 'pending');
    }

    public function test_approved_browser_session_can_read_family_data_without_init_data(): void
    {
        $user = TelegramUser::query()->create([
            'telegram_user_id' => 77,
            'status' => 'approved',
        ]);
        Person::factory()->create();

        $this->withSession(['family_telegram_user_id' => $user->id])
            ->getJson('/api/family/tree')
            ->assertOk()
            ->assertJsonCount(1, 'people');
    }

    private function signedInitData(int $userId): string
    {
        $data = [
            'auth_date' => (string) time(),
            'query_id' => 'test-query',
            'signature' => 'telegram-ed25519-signature',
            'user' => json_encode([
                'id' => $userId,
                'first_name' => 'Анна',
                'language_code' => 'ru',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        ksort($data);
        $checkString = collect($data)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }
}
