<?php

namespace Tests\Feature;

use App\Models\ParentChild;
use App\Models\Person;
use App\Models\PersonPhoto;
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

    public function test_relation_filter_can_show_grandchildren_and_person_route_sets_focus(): void
    {
        $grandparent = Person::factory()->create();
        $child = Person::factory()->create();
        $grandchild = Person::factory()->create();
        ParentChild::query()->create([
            'parent_id' => $grandparent->id,
            'child_id' => $child->id,
            'type' => 'biological',
        ]);
        ParentChild::query()->create([
            'parent_id' => $child->id,
            'child_id' => $grandchild->id,
            'type' => 'biological',
        ]);
        $user = TelegramUser::query()->create([
            'telegram_user_id' => 78,
            'status' => 'approved',
            'person_id' => $grandparent->id,
        ]);

        $this->withSession(['family_telegram_user_id' => $user->id])
            ->getJson('/api/family/tree?relation=grandchildren')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.id', (string) $grandchild->id)
            ->assertJsonPath('people.0.relation', 'grandchildren');

        $this->get('/family/person/'.$child->id)
            ->assertOk()
            ->assertSee('"focusId":'.$child->id, false)
            ->assertSee('"openPersonId":'.$child->id, false);
    }

    public function test_open_mini_app_can_consume_queued_navigation(): void
    {
        $user = TelegramUser::query()->create([
            'telegram_user_id' => 79,
            'status' => 'approved',
            'mini_app_action' => [
                'tab' => 'list',
                'relation' => 'nephews',
            ],
        ]);

        $this->withSession(['family_telegram_user_id' => $user->id])
            ->postJson('/api/family/navigation')
            ->assertOk()
            ->assertJsonPath('action.tab', 'list')
            ->assertJsonPath('action.relation', 'nephews');

        $this->assertNull($user->fresh()->mini_app_action);

        $this->withSession(['family_telegram_user_id' => $user->id])
            ->postJson('/api/family/navigation')
            ->assertOk()
            ->assertJsonPath('action', null);
    }

    public function test_gallery_hides_gedcom_cutout_duplicate_without_deleting_photos(): void
    {
        $person = Person::factory()->create([
            'first_name' => 'София',
            'last_name' => 'Атапин',
        ]);
        PersonPhoto::query()->create([
            'person_id' => $person->id,
            'source_url' => 'https://example.test/cutout.jpg',
            'gedcom_data' => [
                '_CUTOUT' => 'Y',
                '_PARENTRIN' => 'MH:P100',
                '_PHOTO_RIN' => 'MH:P101',
            ],
        ]);
        PersonPhoto::query()->create([
            'person_id' => $person->id,
            'source_url' => 'https://example.test/original.jpg',
            'gedcom_data' => [
                '_PARENTPHOTO' => 'Y',
                '_PHOTO_RIN' => 'MH:P100',
            ],
        ]);
        $user = TelegramUser::query()->create([
            'telegram_user_id' => 80,
            'status' => 'approved',
        ]);

        $this->withSession(['family_telegram_user_id' => $user->id])
            ->getJson('/api/family/gallery')
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.person_name', 'Атапин София')
            ->assertJsonPath('photos.0.url', 'https://example.test/original.jpg');

        $this->assertSame(2, PersonPhoto::query()->count());
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
