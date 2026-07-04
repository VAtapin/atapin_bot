<?php

namespace Tests\Feature;

use App\Models\FamilyTree;
use App\Models\ParentChild;
use App\Models\Payment;
use App\Models\Person;
use App\Models\Plan;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\ImportFileValidator;
use App\Support\CurrentTree;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class PlatformSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_install_has_no_family_trees(): void
    {
        $this->assertDatabaseCount('family_trees', 0);
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('tree_memberships', 0);
    }

    public function test_panels_and_tree_routes_follow_role_matrix(): void
    {
        $tree = app(CurrentTree::class)->get();
        $owner = $this->treeUser($tree, 'owner');
        $moderator = $this->treeUser($tree, 'moderator');
        $member = $this->treeUser($tree, 'member');
        $guest = $this->treeUser($tree, 'guest');
        $super = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

        $this->assertTrue($super->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($super->canAccessPanel(Filament::getPanel('tree')));
        $this->assertFalse($owner->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($owner->canAccessPanel(Filament::getPanel('tree')));
        $this->assertTrue($moderator->canAccessPanel(Filament::getPanel('tree')));
        $this->assertFalse($member->canAccessPanel(Filament::getPanel('tree')));
        $this->assertFalse($guest->canAccessPanel(Filament::getPanel('tree')));

        $this->actingAs($member)->get("/manage/{$tree->slug}/people")->assertForbidden();
        $this->actingAs($guest)->get('/admin')->assertForbidden();
        $this->actingAs($super)->get('/admin')->assertOk();
        $this->actingAs($moderator)->get("/manage/{$tree->slug}/people")->assertOk();
        $this->actingAs($moderator)->get("/manage/{$tree->slug}/settings")->assertForbidden();
    }

    public function test_filament_login_routes_use_common_login_page(): void
    {
        $this->get('/admin/login')->assertRedirect('/ru/login');
        $this->get('/manage/login')->assertRedirect('/ru/login');
    }

    public function test_family_records_cannot_be_created_without_or_moved_between_trees(): void
    {
        $firstTree = app(CurrentTree::class)->get();
        $secondTree = FamilyTree::query()->create([
            'name' => 'Второе дерево',
            'slug' => 'second-security-tree',
            'plan_id' => Plan::query()->firstOrFail()->id,
            'status' => 'active',
        ]);
        $firstPerson = Person::factory()->create();
        app(CurrentTree::class)->set($secondTree);
        $secondPerson = Person::factory()->create(['tree_id' => $secondTree->id]);
        app(CurrentTree::class)->set($firstTree);

        $this->expectException(ValidationException::class);
        ParentChild::query()->create([
            'parent_id' => $firstPerson->id,
            'child_id' => $secondPerson->id,
            'type' => 'biological',
        ]);

        app(CurrentTree::class)->set($firstTree);
    }

    public function test_person_creation_without_tree_is_rejected(): void
    {
        app(CurrentTree::class)->clear();

        $this->expectException(LogicException::class);
        Person::factory()->create(['tree_id' => null]);
    }

    public function test_login_for_another_tree_does_not_grant_access(): void
    {
        $firstTree = app(CurrentTree::class)->get();
        $secondTree = FamilyTree::query()->create([
            'name' => 'Чужое дерево',
            'slug' => 'foreign-tree',
            'plan_id' => Plan::query()->firstOrFail()->id,
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'login' => 'tree-member',
            'password' => 'correct-password',
        ]);
        TreeMembership::query()->create([
            'tree_id' => $firstTree->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'approved',
        ]);

        $this->post('/login', [
            'login' => 'tree-member',
            'password' => 'correct-password',
            'tree_slug' => $secondTree->slug,
        ])->assertRedirect('/access/pending?tree=foreign-tree');
    }

    public function test_import_validator_accepts_real_gedcom_and_rejects_executables(): void
    {
        $validator = app(ImportFileValidator::class);
        $valid = tempnam(sys_get_temp_dir(), 'ged');
        $binary = tempnam(sys_get_temp_dir(), 'bin');
        file_put_contents($valid, "0 HEAD\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Иван /Иванов/\n0 TRLR\n");
        file_put_contents($binary, "MZ\x00\x01executable");

        try {
            $validator->validate('gedcom', $valid, 'family.ged');
            $this->expectException(RuntimeException::class);
            $validator->validate('gedcom', $binary, 'family.ged');
        } finally {
            @unlink($valid);
            @unlink($binary);
        }
    }

    public function test_signed_payment_webhook_is_idempotent(): void
    {
        config()->set('services.billing.webhook_secret', 'billing-secret');
        $tree = app(CurrentTree::class)->get();
        $plan = Plan::query()->firstOrFail();
        $payload = [
            'reference' => 'pay-100',
            'tree_id' => $tree->id,
            'plan_id' => $plan->id,
            'status' => 'paid',
            'amount' => 9.90,
            'currency' => 'EUR',
        ];
        $signature = hash_hmac('sha256', json_encode($payload), 'billing-secret');

        $this->withHeader('X-Idommoy-Signature', $signature)
            ->postJson('/api/payments/webhook/test-provider', $payload)
            ->assertOk();
        $this->withHeader('X-Idommoy-Signature', $signature)
            ->postJson('/api/payments/webhook/test-provider', $payload)
            ->assertOk();

        $this->assertSame(1, Payment::query()->count());
        $this->assertDatabaseHas('subscriptions', [
            'tree_id' => $tree->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    private function treeUser(FamilyTree $tree, string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        if ($role === 'owner') {
            $tree->update(['owner_user_id' => $user->id]);
        }
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'approved',
        ]);

        return $user;
    }
}
