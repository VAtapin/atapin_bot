<?php

namespace Tests\Feature;

use App\Models\ExternalIdentity;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\FamilyNotificationService;
use App\Support\CurrentTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramUserNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_and_user_receive_tree_scoped_access_notifications(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $tree = app(CurrentTree::class)->get();
        $owner = User::factory()->create();
        $tree->update(['owner_user_id' => $owner->id]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);
        ExternalIdentity::query()->create([
            'user_id' => $owner->id,
            'provider' => 'telegram',
            'provider_user_id' => '999',
        ]);
        $member = User::factory()->create();
        ExternalIdentity::query()->create([
            'user_id' => $member->id,
            'provider' => 'telegram',
            'provider_user_id' => '321',
        ]);
        $membership = TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $member->id,
            'role' => 'guest',
            'status' => 'pending',
        ])->load(['tree', 'user']);
        $notifications = app(FamilyNotificationService::class);

        $notifications->membershipRequested($membership);

        Http::assertSent(function ($request) use ($membership): bool {
            $data = $request->data();

            return $data['chat_id'] === '999'
                && $data['reply_markup']['inline_keyboard'][0][0]['callback_data']
                    === 'membership:approve:'.$membership->id;
        });

        $membership->update(['status' => 'approved']);
        $notifications->membershipChanged($membership->fresh()->load(['tree', 'user']));

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['chat_id'] === '321'
                && str_contains($data['text'], 'разрешён');
        });
    }
}
