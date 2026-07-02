<?php

namespace Tests\Feature;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\Plan;
use App\Models\TelegramGroup;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Models\User;
use App\Support\CurrentTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        config()->set('services.telegram.bot_username', 'idommoy_bot');
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
        $user = $this->approvedTelegramUser();
        Person::factory()->create(['first_name' => 'Анатолий', 'last_name' => 'Атапин']);

        $this->sendPrivateMessage(2001, '/person');
        $this->assertSame('person', $user->fresh()->pending_command);

        $this->sendPrivateMessage(2002, 'Анатолий');
        $this->assertNull($user->fresh()->pending_command);
        $this->assertSame([
            'tab' => 'list',
            'q' => 'Анатолий',
            'scope' => 'all',
            'tree_id' => FamilyTree::query()->first()->id,
            'tree_name' => FamilyTree::query()->first()->name,
        ], $user->fresh()->mini_app_action);

        $requests = Http::recorded();
        $this->assertStringContainsString(
            'Напишите следующим сообщением',
            $requests[0][0]->data()['text'],
        );
        $this->assertSame(
            route('family.tree.person', [
                'tree' => FamilyTree::query()->first(),
                'person' => Person::query()->first()->id,
            ]),
            $requests[1][0]->data()['reply_markup']['inline_keyboard'][0][0]['web_app']['url'],
        );
    }

    public function test_section_command_is_queued_for_an_already_open_mini_app(): void
    {
        $user = $this->approvedTelegramUser();

        $this->sendPrivateMessage(2101, '/photos');

        $this->assertSame(
            [
                'tab' => 'gallery',
                'tree_id' => FamilyTree::query()->first()->id,
                'tree_name' => FamilyTree::query()->first()->name,
            ],
            $user->fresh()->mini_app_action,
        );
    }

    public function test_approved_linked_user_can_receive_new_site_credentials(): void
    {
        $person = Person::factory()->create();
        $telegramUser = $this->approvedTelegramUser($person);

        $this->sendPrivateMessage(2201, '/credentials');

        $familyUser = $telegramUser->user->fresh();
        $this->assertNotNull($familyUser->login);

        $request = Http::recorded()->last()[0];
        preg_match('/Новый пароль: <code>([^<]+)<\/code>/', $request->data()['text'], $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $this->assertTrue(Hash::check($matches[1], $familyUser->password));
        $this->assertTrue($request->data()['protect_content']);
    }

    public function test_site_command_sends_one_time_login_link(): void
    {
        $user = $this->approvedTelegramUser();

        $this->sendPrivateMessage(2202, '/site');

        $request = Http::recorded()->last()[0];
        $url = $request->data()['reply_markup']['inline_keyboard'][0][0]['url'];

        $this->get($url)
            ->assertRedirect('/family/test-family')
            ->assertSessionHas('family_telegram_user_id', $user->id);

        $this->get($url)->assertForbidden();
    }

    public function test_group_person_result_uses_authenticated_mini_app_deep_link(): void
    {
        $this->approvedTelegramUser();
        TelegramGroup::query()->create([
            'telegram_chat_id' => -100123,
            'title' => 'Большая семья',
            'is_active' => true,
        ]);
        $person = Person::factory()->create([
            'first_name' => 'Анатолий',
            'last_name' => 'Атапин',
        ]);

        $this->sendGroupMessage(2301, '/person');
        $this->sendGroupMessage(2302, 'Анатолий');

        $request = Http::recorded()->last()[0];
        $url = $request->data()['reply_markup']['inline_keyboard'][0][0]['url'];
        $this->assertSame(
            'https://t.me/idommoy_bot?startapp=tree_'
                .app(CurrentTree::class)->id().'_person_'.$person->id,
            $url,
        );
    }

    public function test_admin_can_manage_user_access_from_inline_buttons(): void
    {
        config()->set('services.telegram.admin_ids', ['999']);
        $targetUser = User::factory()->create();
        $membership = TreeMembership::query()->create([
            'tree_id' => app(CurrentTree::class)->id(),
            'user_id' => $targetUser->id,
            'role' => 'guest',
            'status' => 'pending',
        ]);
        TelegramUser::query()->create([
            'user_id' => $targetUser->id,
            'current_tree_id' => app(CurrentTree::class)->id(),
            'telegram_user_id' => 321,
            'status' => 'pending',
        ]);

        $this->sendMembershipCallback(3001, 'approve', $membership);
        $this->assertSame('approved', $membership->fresh()->status);

        $this->sendMembershipCallback(3002, 'moderator', $membership);
        $this->assertSame('moderator', $membership->fresh()->role);

        $this->sendMembershipCallback(3003, 'member', $membership);
        $this->assertSame('member', $membership->fresh()->role);

        $this->sendMembershipCallback(3004, 'block', $membership);
        $this->assertSame('blocked', $membership->fresh()->status);

        foreach (range(1, 4) as $callbackId) {
            Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
                && $request->data()['callback_query_id'] === 'callback-'.$callbackId);
        }
    }

    public function test_user_can_switch_between_approved_trees_from_bot(): void
    {
        $user = User::factory()->create();
        $defaultTree = FamilyTree::query()->firstOrFail();
        $secondTree = FamilyTree::query()->create([
            'name' => 'Второе дерево',
            'slug' => 'second-tree',
            'status' => 'active',
            'plan_id' => Plan::query()->first()->id,
        ]);
        TreeMembership::query()->create([
            'tree_id' => $defaultTree->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'approved',
        ]);
        TreeMembership::query()->create([
            'tree_id' => $secondTree->id,
            'user_id' => $user->id,
            'role' => 'guest',
            'status' => 'approved',
        ]);
        $telegramUser = TelegramUser::query()->create([
            'user_id' => $user->id,
            'current_tree_id' => $defaultTree->id,
            'telegram_user_id' => 321,
            'status' => 'approved',
        ]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => 4001,
                'callback_query' => [
                    'id' => 'tree-callback',
                    'from' => ['id' => 321, 'first_name' => 'Иван'],
                    'data' => 'tree:select:'.$secondTree->id,
                ],
            ])
            ->assertOk();

        $this->assertSame($secondTree->id, $telegramUser->fresh()->current_tree_id);
        $this->assertSame($secondTree->id, $telegramUser->fresh()->mini_app_action['tree_id']);
    }

    private function sendMembershipCallback(
        int $updateId,
        string $action,
        TreeMembership $membership,
    ): void {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/telegram/webhook', [
                'update_id' => $updateId,
                'callback_query' => [
                    'id' => 'callback-'.($updateId - 3000),
                    'from' => ['id' => 999, 'first_name' => 'Админ'],
                    'data' => 'membership:'.$action.':'.$membership->id,
                    'message' => [
                        'message_id' => 55,
                        'chat' => ['id' => 999, 'type' => 'private'],
                    ],
                ],
            ])
            ->assertOk();
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

    private function sendGroupMessage(int $updateId, string $text): void
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
                    'chat' => [
                        'id' => -100123,
                        'type' => 'supergroup',
                        'title' => 'Большая семья',
                    ],
                ],
            ])
            ->assertOk();
    }

    private function approvedTelegramUser(?Person $person = null): TelegramUser
    {
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => app(CurrentTree::class)->id(),
            'user_id' => $user->id,
            'person_id' => $person?->id,
            'role' => $person ? 'member' : 'guest',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return TelegramUser::query()->create([
            'user_id' => $user->id,
            'current_tree_id' => app(CurrentTree::class)->id(),
            'telegram_user_id' => 321,
            'first_name' => 'Иван',
            'status' => 'approved',
            'person_id' => $person?->id,
        ]);
    }
}
