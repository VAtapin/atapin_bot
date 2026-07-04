<?php

namespace Tests\Feature;

use App\Filament\Resources\TreeImports\TreeImportResource;
use App\Models\DataIssue;
use App\Models\FamilyTree;
use App\Models\ParentChild;
use App\Models\Person;
use App\Models\Plan;
use App\Models\TreeImport;
use App\Models\TreeInvitation;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\PersonMergeService;
use App\Services\TreeAccessService;
use App\Services\TreeArchiveService;
use App\Services\TreeImportService;
use App\Services\VkLaunchParams;
use App\Support\CurrentTree;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultiTreePlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_owned_private_tree(): void
    {
        $this->post('/register', [
            'name' => 'Иван Атапин',
            'email' => 'ivan@example.test',
            'password' => 'very-secret-password',
            'password_confirmation' => 'very-secret-password',
            'tree_name' => 'Семья Ивановых',
            'tree_slug' => 'ivanovy',
            'privacy_consent' => '1',
        ])->assertRedirect('/account?welcome=1');

        $tree = FamilyTree::query()->where('slug', 'ivanovy')->firstOrFail();
        $this->assertSame('Семья Ивановых', $tree->name);
        $this->assertDatabaseHas(TreeMembership::class, [
            'tree_id' => $tree->id,
            'user_id' => $tree->owner_user_id,
            'role' => 'owner',
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'tree_id' => $tree->id,
            'status' => 'trial',
        ]);
        $this->assertFalse($tree->owner->two_factor_enabled);
        $this->assertFalse($tree->owner->two_factor_required);
        $this->assertNotNull($tree->owner->privacy_accepted_at);
    }

    public function test_tree_header_strictly_isolates_people(): void
    {
        $defaultTree = FamilyTree::query()->firstOrFail();
        $secondTree = FamilyTree::query()->create([
            'name' => 'Второе дерево',
            'slug' => 'second',
            'status' => 'active',
            'plan_id' => Plan::query()->first()->id,
        ]);
        $first = Person::factory()->create(['tree_id' => $defaultTree->id, 'first_name' => 'Первый']);
        app(CurrentTree::class)->set($secondTree);
        $second = Person::factory()->create([
            'tree_id' => $secondTree->id,
            'first_name' => 'Второй',
        ]);
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $secondTree->id,
            'user_id' => $user->id,
            'person_id' => $second->id,
            'role' => 'member',
            'status' => 'approved',
        ]);

        $this->withSession([
            'family_user_id' => $user->id,
            'family_tree_id' => $secondTree->id,
        ])->withHeader('X-Family-Tree-ID', $secondTree->id)
            ->getJson('/api/family/tree?scope=all')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.id', (string) $second->id);

        $this->assertNotSame($first->tree_id, $second->tree_id);
    }

    public function test_invitation_approves_member_without_telegram_id(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $owner = User::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));
        TreeInvitation::query()->create([
            'tree_id' => $tree->id,
            'created_by_user_id' => $owner->id,
            'token_hash' => hash('sha256', $token),
            'role' => 'member',
            'max_uses' => 1,
        ]);

        $membership = app(TreeAccessService::class)->acceptInvitation($user, $token);

        $this->assertSame('approved', $membership->status);
        $this->assertSame('member', $membership->role);
    }

    public function test_issue_button_creates_single_tree_issue(): void
    {
        $person = Person::factory()->create();
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $person->tree_id,
            'user_id' => $user->id,
            'person_id' => $person->id,
            'role' => 'member',
            'status' => 'approved',
        ]);

        $this->withSession(['family_user_id' => $user->id, 'family_tree_id' => $person->tree_id])
            ->postJson('/api/family/issues', [
                'subject' => 'Неверная дата',
                'description' => 'Проверьте год рождения.',
                'person_id' => $person->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas(DataIssue::class, [
            'tree_id' => $person->tree_id,
            'person_id' => $person->id,
            'subject' => 'Неверная дата',
        ]);
    }

    public function test_backup_restores_tree_database_and_media(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $tree = FamilyTree::query()->firstOrFail();
        app(CurrentTree::class)->set($tree);
        $person = Person::factory()->create(['first_name' => 'До копии']);
        Storage::disk('public')->put('trees/1/portrait.jpg', 'photo');
        $person->photos()->create([
            'path' => 'trees/1/portrait.jpg',
            'file_size' => 5,
            'is_primary' => true,
        ]);

        $backup = app(TreeArchiveService::class)->create($tree);
        $person->update(['first_name' => 'После копии']);
        app(TreeArchiveService::class)->restore($backup);

        $this->assertSame('До копии', Person::query()->findOrFail($person->id)->first_name);
        Storage::disk('public')->assertExists('trees/1/portrait.jpg');
    }

    public function test_vk_launch_signature_is_verified(): void
    {
        $secret = 'vk-test-secret';
        $values = [
            'vk_app_id' => '100',
            'vk_user_id' => '42',
            'vk_language' => 'ru',
            'vk_ts' => (string) time(),
        ];
        ksort($values);
        $signed = http_build_query($values, '', '&', PHP_QUERY_RFC3986);
        $values['sign'] = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signed, $secret, true),
        ), '+/', '-_'), '=');

        $validated = app(VkLaunchParams::class)->validate(
            http_build_query($values),
            $secret,
        );

        $this->assertSame('42', $validated['vk_user_id']);
    }

    public function test_public_home_uses_new_brand(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Я и дом мой')
            ->assertSee('Семейная история');
    }

    public function test_super_admin_can_open_all_new_management_sections(): void
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'is_active' => true,
        ]);

        foreach ([
            '/admin/family-trees',
            '/admin/change-logs',
            '/admin/cms-pages',
            '/admin/plans',
            '/admin/subscriptions',
            '/admin/payments',
            '/admin/users',
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $tree = FamilyTree::query()->firstOrFail();
        foreach ([
            "/manage/{$tree->slug}/people",
            "/manage/{$tree->slug}/tree-memberships",
            "/manage/{$tree->slug}/tree-invitations",
            "/manage/{$tree->slug}/data-issues",
            "/manage/{$tree->slug}/tree-backups",
            "/manage/{$tree->slug}/tree-imports",
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_guest_can_view_but_cannot_edit_family(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $person = Person::factory()->create();
        $guest = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $guest->id,
            'person_id' => $person->id,
            'role' => 'guest',
            'status' => 'approved',
        ]);

        $session = ['family_user_id' => $guest->id, 'family_tree_id' => $tree->id];
        $this->withSession($session)->getJson('/api/family/me')
            ->assertOk()
            ->assertJsonPath('can_edit', false);
        $this->withSession($session)->putJson('/api/family/me', ['current_city' => 'Москва'])
            ->assertForbidden();
    }

    public function test_duplicate_merge_keeps_relationships_and_deletes_source(): void
    {
        $source = Person::factory()->create(['first_name' => 'Дубль']);
        $target = Person::factory()->create(['first_name' => 'Основной']);
        $child = Person::factory()->create();
        ParentChild::query()->create([
            'parent_id' => $source->id,
            'child_id' => $child->id,
            'type' => 'biological',
        ]);

        app(PersonMergeService::class)->merge($source, $target);

        $this->assertSoftDeleted($source);
        $this->assertDatabaseHas('parent_children', [
            'parent_id' => $target->id,
            'child_id' => $child->id,
        ]);
    }

    public function test_csv_import_adds_people_to_selected_tree(): void
    {
        Storage::fake('local');
        $tree = FamilyTree::query()->firstOrFail();
        app(CurrentTree::class)->set($tree);
        Storage::disk('local')->put(
            'tree-imports/1/family.csv',
            "first_name,last_name,birth_date\nАнна,Иванова,1990-05-10\n",
        );
        $import = TreeImport::query()->create([
            'tree_id' => $tree->id,
            'format' => 'csv',
            'status' => 'pending',
            'path' => 'tree-imports/1/family.csv',
            'original_name' => 'family.csv',
        ]);

        $result = app(TreeImportService::class)->process($import);

        $this->assertSame('completed', $result->status);
        $this->assertDatabaseHas('people', [
            'tree_id' => $tree->id,
            'first_name' => 'Анна',
            'last_name' => 'Иванова',
        ]);
    }

    public function test_moderator_cannot_open_owner_only_import_section(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $owner = User::factory()->create(['is_active' => true]);
        $tree->update(['owner_user_id' => $owner->id]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);
        $moderator = User::factory()->create(['is_active' => true]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $moderator->id,
            'role' => 'moderator',
            'status' => 'approved',
        ]);

        $this->assertTrue($owner->fresh()->memberships()->where('role', 'owner')->exists());
        $this->assertFalse($owner->fresh()->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($owner->fresh()->canAccessPanel(Filament::getPanel('tree')));
        $this->actingAs($owner);
        app(CurrentTree::class)->set($tree);
        $this->assertTrue(TreeImportResource::canViewAny());
        $this->get("/manage/{$tree->slug}/people")->assertOk();
        $this->get("/manage/{$tree->slug}/tree-imports")->assertOk();
        $this->actingAs($moderator)->get("/manage/{$tree->slug}/people")->assertOk();
        $this->actingAs($moderator)->get("/manage/{$tree->slug}/tree-imports")->assertForbidden();
    }
}
