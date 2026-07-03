<?php

namespace Tests\Feature;

use App\Models\ExternalIdentity;
use App\Models\FamilyTree;
use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\CustomDomainService;
use App\Services\FamilyBranchService;
use App\Services\TreeDeletionService;
use App\Services\UserMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RuntimeTodoTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_attaches_spouse_but_does_not_walk_into_spouses_family(): void
    {
        $grandparent = Person::factory()->create();
        $focus = Person::factory()->create();
        $sister = Person::factory()->create();
        $niece = Person::factory()->create();
        $husband = Person::factory()->create();
        $husbandFather = Person::factory()->create();
        ParentChild::query()->create(['parent_id' => $grandparent->id, 'child_id' => $focus->id, 'type' => 'biological']);
        ParentChild::query()->create(['parent_id' => $grandparent->id, 'child_id' => $sister->id, 'type' => 'biological']);
        ParentChild::query()->create(['parent_id' => $sister->id, 'child_id' => $niece->id, 'type' => 'biological']);
        ParentChild::query()->create(['parent_id' => $husbandFather->id, 'child_id' => $husband->id, 'type' => 'biological']);
        Partnership::query()->create(['partner_one_id' => $niece->id, 'partner_two_id' => $husband->id, 'status' => 'married']);

        $ids = app(FamilyBranchService::class)->branchIds($focus->id, 3);

        $this->assertContains($niece->id, $ids);
        $this->assertContains($husband->id, $ids);
        $this->assertNotContains($husbandFather->id, $ids);
    }

    public function test_membership_person_wins_over_stale_telegram_person(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $correct = Person::factory()->create();
        $stale = Person::factory()->create();
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $user->id,
            'person_id' => $correct->id,
            'role' => 'member',
            'status' => 'approved',
        ]);
        $telegram = TelegramUser::query()->create([
            'user_id' => $user->id,
            'current_tree_id' => $tree->id,
            'person_id' => $stale->id,
            'telegram_user_id' => 70001,
            'status' => 'approved',
        ]);

        $this->withSession([
            'family_user_id' => $user->id,
            'family_telegram_user_id' => $telegram->id,
            'family_tree_id' => $tree->id,
        ])->withHeader('X-Family-Tree-ID', $tree->id)
            ->getJson('/api/family/tree')
            ->assertOk()
            ->assertJsonPath('focus_id', (string) $correct->id)
            ->assertJsonPath('viewer.person_id', (string) $correct->id);
    }

    public function test_super_admin_without_membership_does_not_become_random_person(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        Person::factory()->create(['birth_date' => '1800-01-01']);
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->withSession([
            'family_user_id' => $admin->id,
            'family_tree_id' => $tree->id,
        ])->withHeader('X-Family-Tree-ID', $tree->id)
            ->getJson('/api/family/tree')
            ->assertOk()
            ->assertJsonPath('focus_id', null)
            ->assertJsonPath('viewer.has_person', false);
    }

    public function test_gallery_is_cursor_paginated(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $person = Person::factory()->create();
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $user->id,
            'person_id' => $person->id,
            'role' => 'member',
            'status' => 'approved',
        ]);
        foreach (range(1, 50) as $index) {
            PersonPhoto::query()->create([
                'person_id' => $person->id,
                'source_url' => "https://example.test/photo-{$index}.jpg",
            ]);
        }

        $response = $this->withSession([
            'family_user_id' => $user->id,
            'family_tree_id' => $tree->id,
        ])->withHeader('X-Family-Tree-ID', $tree->id)
            ->getJson('/api/family/gallery?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'photos')
            ->assertJsonPath('has_more', true);

        $this->assertNotEmpty($response->json('next_cursor'));
    }

    public function test_normal_media_view_does_not_hit_old_120_request_limit(): void
    {
        Storage::fake('public');
        $tree = FamilyTree::query()->firstOrFail();
        $person = Person::factory()->create();
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $user->id,
            'person_id' => $person->id,
            'role' => 'member',
            'status' => 'approved',
        ]);
        Storage::disk('public')->put('trees/'.$tree->id.'/photo.jpg', 'image-data');
        $photo = PersonPhoto::query()->create([
            'person_id' => $person->id,
            'path' => 'trees/'.$tree->id.'/photo.jpg',
            'file_size' => 10,
        ]);
        $url = $photo->url;

        foreach (range(1, 130) as $attempt) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_uploaded_photo_gets_a_private_thumbnail(): void
    {
        Storage::fake('public');
        $tree = FamilyTree::query()->firstOrFail();
        $person = Person::factory()->create();
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('public')->put("trees/{$tree->id}/tiny.png", $png);

        $photo = PersonPhoto::query()->create([
            'person_id' => $person->id,
            'path' => "trees/{$tree->id}/tiny.png",
        ])->fresh();

        $this->assertNotNull($photo->thumbnail_path);
        Storage::disk('public')->assertExists($photo->thumbnail_path);
        $this->assertStringContainsString('/thumbnail?', $photo->thumbnail_url);
    }

    public function test_super_admin_can_purge_tree_immediately(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $tree = FamilyTree::query()->firstOrFail();
        $admin = User::factory()->create(['is_super_admin' => true]);
        Person::factory()->create();
        Storage::disk('public')->put("trees/{$tree->id}/photo.jpg", 'photo');

        app(TreeDeletionService::class)->purgeNow($tree, $admin, 'test');

        $this->assertDatabaseMissing('family_trees', ['id' => $tree->id]);
        $this->assertDatabaseHas('deleted_tree_audits', [
            'original_tree_id' => $tree->id,
            'deleted_by_user_id' => $admin->id,
        ]);
        Storage::disk('public')->assertMissing("trees/{$tree->id}");
    }

    public function test_congratulation_is_saved_and_delivered_to_telegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Config::set('services.telegram.bot_token', 'test-token');
        $tree = FamilyTree::query()->firstOrFail();
        $senderPerson = Person::factory()->create();
        $recipientPerson = Person::factory()->create();
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $sender->id,
            'person_id' => $senderPerson->id,
            'role' => 'member',
            'status' => 'approved',
        ]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $recipient->id,
            'person_id' => $recipientPerson->id,
            'role' => 'member',
            'status' => 'approved',
        ]);
        ExternalIdentity::query()->create([
            'user_id' => $recipient->id,
            'provider' => 'telegram',
            'provider_user_id' => '80001',
            'verified_at' => now(),
        ]);

        $this->withSession([
            'family_user_id' => $sender->id,
            'family_tree_id' => $tree->id,
        ])->withHeader('X-Family-Tree-ID', $tree->id)
            ->postJson('/api/family/congratulations', [
                'occasion' => 'birthday',
                'person_id' => $recipientPerson->id,
                'message' => 'С днём рождения!',
            ])
            ->assertCreated()
            ->assertJsonPath('deliveries.0.telegram', 'delivered');

        $this->assertDatabaseHas('congratulations', [
            'tree_id' => $tree->id,
            'recipient_person_id' => $recipientPerson->id,
            'telegram_status' => 'delivered',
        ]);
    }

    public function test_verified_custom_domain_opens_only_its_tree(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $tree->update([
            'primary_domain' => 'family.example.test',
            'domain_status' => 'active',
            'domain_verified_at' => now(),
        ]);

        $this->get('http://family.example.test/')
            ->assertOk()
            ->assertSee($tree->name);
    }

    public function test_custom_domain_is_normalised_and_platform_domain_is_rejected(): void
    {
        $service = app(CustomDomainService::class);

        $this->assertSame('family.example.com', $service->normalise(' HTTPS://Family.Example.com/path '));
        $this->expectException(ValidationException::class);
        $service->normalise('idommoy.com');
    }

    public function test_new_admin_and_tree_pages_render_for_their_roles(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $owner = User::factory()->create();
        $tree->update(['owner_user_id' => $owner->id]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        $this->actingAs($owner)
            ->get("/manage/{$tree->slug}/profile")
            ->assertOk()
            ->assertSee('Основные настройки');
        $this->get("/manage/{$tree->slug}/tree-integrity")->assertOk();

        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->get('/admin/super-administrators')->assertOk();
        $this->get('/admin/deleted-tree-audits')->assertOk();
        $this->get('/admin/account-integrity')->assertOk();
        $this->get('/admin/system-health')->assertOk();
    }

    public function test_merging_the_last_super_admin_transfers_platform_access(): void
    {
        $source = User::factory()->create(['is_super_admin' => true]);
        $target = User::factory()->create(['is_super_admin' => false]);

        app(UserMergeService::class)->merge($source, $target, $source);

        $this->assertFalse($source->fresh()->is_active);
        $this->assertFalse($source->fresh()->is_super_admin);
        $this->assertTrue($target->fresh()->is_super_admin);
        $this->assertTrue($target->fresh()->two_factor_required);
    }
}
