<?php

namespace Tests\Feature;

use App\Models\ExternalIdentity;
use App\Models\FamilyEvent;
use App\Models\TreeInvitation;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\ExternalIdentityService;
use App\Services\GedcomFileReader;
use App\Services\ImportFileValidator;
use App\Services\TreeDeletionService;
use App\Support\CurrentTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodoCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_errors_are_human_readable_and_field_specific(): void
    {
        User::factory()->create(['email' => 'used@example.test']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Тест',
            'email' => 'used@example.test',
            'password' => 'long-password',
            'password_confirmation' => 'different-password',
            'tree_name' => 'Семья',
            'tree_slug' => 'admin',
            'privacy_consent' => '1',
        ]);

        $response->assertRedirect('/register')
            ->assertSessionHasErrors(['email', 'password', 'tree_slug']);
        $errors = session('errors')->getBag('default');
        $this->assertStringNotContainsString('validation.', implode(' ', $errors->all()));
    }

    public function test_gedcom_reader_accepts_utf16_and_split_utf8_concatenation(): void
    {
        $utf16 = tempnam(sys_get_temp_dir(), 'ged');
        file_put_contents($utf16, "\xFF\xFE".mb_convert_encoding(
            "0 HEAD\r\n1 CHAR UNICODE\r\n0 @I1@ INDI\r\n1 NAME Иван /Иванов/\r\n0 TRLR\r\n",
            'UTF-16LE',
            'UTF-8',
        ));
        app(ImportFileValidator::class)->validate('gedcom', $utf16, 'family.ged');
        $this->assertSame('UTF-16LE', app(GedcomFileReader::class)->read($utf16)['encoding']);
        unlink($utf16);

        $split = tempnam(sys_get_temp_dir(), 'ged');
        file_put_contents(
            $split,
            "\xEF\xBB\xBF0 HEAD\r\n1 CHAR UTF-8\r\n0 @I1@ INDI\r\n"
            ."1 NAME Иван /Иванов/\r\n1 NOTE У семьи D1".chr(0xD1)."\r\n2 CONC ".chr(0x8F)." история\r\n0 TRLR\r\n",
        );
        app(ImportFileValidator::class)->validate('gedcom', $split, 'family.ged');
        unlink($split);
        $this->addToAssertionCount(1);
    }

    public function test_identity_is_linked_to_existing_account_instead_of_creating_duplicate(): void
    {
        $user = User::factory()->create();
        $resolved = app(ExternalIdentityService::class)->resolve(
            'telegram',
            '123456',
            ['first_name' => 'Иван', 'username' => 'ivan'],
            $user,
        );

        $this->assertTrue($resolved->is($user));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas(ExternalIdentity::class, [
            'user_id' => $user->id,
            'provider' => 'telegram',
            'provider_user_id' => '123456',
        ]);
    }

    public function test_owner_can_schedule_and_cancel_tree_deletion(): void
    {
        $tree = app(CurrentTree::class)->get();
        $owner = User::factory()->create();
        $tree->update(['owner_user_id' => $owner->id]);
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        app(TreeDeletionService::class)->schedule($tree, $owner, 'Проверка');
        $this->assertSame('deleting', $tree->fresh()->status);
        $this->assertNotNull($tree->fresh()->deletion_scheduled_at);

        app(TreeDeletionService::class)->cancel($tree->fresh(), $owner);
        $this->assertSame('active', $tree->fresh()->status);
        $this->assertNull($tree->fresh()->deletion_scheduled_at);
    }

    public function test_family_events_endpoint_returns_upcoming_and_archive(): void
    {
        $tree = app(CurrentTree::class)->get();
        $user = User::factory()->create();
        TreeMembership::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $user->id,
            'role' => 'guest',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        FamilyEvent::query()->create([
            'title' => 'Встреча семьи',
            'type' => 'reunion',
            'event_date' => now()->addWeek(),
            'is_annual' => false,
            'is_published' => true,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['family_tree_id' => $tree->id, 'family_user_id' => $user->id])
            ->getJson('/api/family/events', ['X-Family-Tree-ID' => $tree->id]);

        $response->assertOk()->assertJsonPath('upcoming.0.title', 'Встреча семьи');
    }

    public function test_invitation_keeps_an_encrypted_recoverable_link(): void
    {
        $plain = bin2hex(random_bytes(32));
        $invitation = TreeInvitation::query()->create([
            'tree_id' => app(CurrentTree::class)->id(),
            'token_hash' => hash('sha256', $plain),
            'token_ciphertext' => $plain,
            'role' => 'guest',
            'max_uses' => 1,
        ]);

        $this->assertStringContainsString('/invite/'.$plain, $invitation->invitation_url);
        $this->assertNotSame($plain, $invitation->getRawOriginal('token_ciphertext'));
    }
}
