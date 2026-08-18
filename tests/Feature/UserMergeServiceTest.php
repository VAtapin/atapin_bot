<?php

namespace Tests\Feature;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\TelegramAccountLinkToken;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_account_can_be_merged_into_tree_owner(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $owner = User::factory()->create([
            'name' => 'Владимир Атапин',
            'email' => 'owner@example.test',
        ]);
        $duplicate = User::factory()->create([
            'name' => 'Vladimir Atapin',
            'email' => 'telegram_2090702029@idommoy.local',
        ]);
        $person = Person::factory()->create(['tree_id' => $tree->id]);

        $tree->update(['owner_user_id' => $owner->id]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $owner->id,
            'person_id' => $person->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $duplicate->id,
            'role' => 'moderator',
            'status' => 'approved',
        ]);
        TelegramUser::query()->create([
            'user_id' => $duplicate->id,
            'current_tree_id' => $tree->id,
            'telegram_user_id' => 2090702029,
            'first_name' => 'Vladimir',
            'last_name' => 'Atapin',
            'status' => 'approved',
        ]);
        TelegramAccountLinkToken::query()->create([
            'user_id' => $duplicate->id,
            'token_hash' => hash('sha256', 'stale-link-token'),
            'expires_at' => now()->addHour(),
        ]);

        app(UserMergeService::class)->merge($duplicate, $owner, $owner);

        $this->assertSame($owner->id, $tree->fresh()->owner_user_id);
        $this->assertDatabaseHas('tree_memberships', [
            'tree_id' => $tree->id,
            'user_id' => $owner->id,
            'person_id' => $person->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);
        $this->assertDatabaseMissing('tree_memberships', [
            'tree_id' => $tree->id,
            'user_id' => $duplicate->id,
        ]);
        $this->assertDatabaseHas('telegram_users', [
            'telegram_user_id' => 2090702029,
            'user_id' => $owner->id,
            'current_tree_id' => $tree->id,
            'person_id' => $person->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseMissing('telegram_account_link_tokens', [
            'user_id' => $duplicate->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $duplicate->id,
            'is_active' => false,
            'merged_into_user_id' => $owner->id,
        ]);
    }

    public function test_real_email_moves_to_technical_target_account(): void
    {
        $source = User::factory()->create([
            'email' => 'real@example.test',
        ]);
        $target = User::factory()->create([
            'email' => 'telegram_123456789@idommoy.local',
        ]);

        app(UserMergeService::class)->merge($source, $target);

        $this->assertSame('real@example.test', $target->fresh()->email);
        $this->assertStringStartsWith(
            'merged_'.$source->id.'_',
            $source->fresh()->email,
        );
        $this->assertSame($target->id, $source->fresh()->merged_into_user_id);
    }
}
