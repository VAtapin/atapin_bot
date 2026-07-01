<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FamilyAuthAndSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_can_log_in_with_login_and_password(): void
    {
        $person = Person::factory()->create([
            'login' => 'family-member',
            'password' => 'secret-password',
            'web_login_enabled' => true,
        ]);

        $this->post('/family/login', [
            'login' => 'family-member',
            'password' => 'secret-password',
        ])
            ->assertRedirect('/family')
            ->assertSessionHas('family_person_id', $person->id);

        $this->withSession(['family_person_id' => $person->id])
            ->getJson('/api/family/me')
            ->assertOk()
            ->assertJsonPath('person.name', $person->full_name);
    }

    public function test_living_person_does_not_have_life_years_label(): void
    {
        $living = Person::factory()->create([
            'birth_date' => '2004-03-12',
            'death_date' => null,
        ]);
        $deceased = Person::factory()->create([
            'birth_date' => '1929-12-25',
            'death_date' => '1997-12-29',
        ]);

        $this->assertNull($living->life_years);
        $this->assertSame('1929 — 1997', $deceased->life_years);
    }

    public function test_person_can_edit_profile_add_child_album_and_photo(): void
    {
        Storage::fake('public');
        $person = Person::factory()->create(['web_login_enabled' => true]);
        $session = ['family_person_id' => $person->id];

        $this->withSession($session)
            ->putJson('/api/family/me', ['current_city' => 'Берлин'])
            ->assertOk();
        $this->assertSame('Берлин', $person->fresh()->current_city);

        $childResponse = $this->withSession($session)->postJson('/api/family/me/relatives', [
            'kind' => 'child',
            'first_name' => 'Анна',
            'last_name' => 'Атапина',
            'gender' => 'female',
        ])->assertCreated();
        $childId = $childResponse->json('person.id');
        $this->assertTrue($person->children()->whereKey($childId)->exists());

        $albumResponse = $this->withSession($session)->postJson('/api/family/me/albums', [
            'title' => 'Детство',
        ])->assertCreated();

        $this->withSession($session)->post('/api/family/me/photos', [
            'photo' => UploadedFile::fake()->create('portrait.jpg', 10, 'image/jpeg'),
            'photo_album_id' => $albumResponse->json('album.id'),
            'is_primary' => '1',
        ], ['Accept' => 'application/json'])->assertCreated();

        $photo = PersonPhoto::query()->firstOrFail();
        Storage::disk('public')->assertExists($photo->path);
        $this->assertTrue($photo->is_primary);
    }
}
