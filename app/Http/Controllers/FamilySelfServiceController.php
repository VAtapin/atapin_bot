<?php

namespace App\Http\Controllers;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\PhotoAlbum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FamilySelfServiceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $person = $this->person($request);

        return response()->json([
            'person' => $this->personPayload($person),
            'albums' => $person->albums()->withCount('photos')->get(),
            'photos' => $person->photos()->with('album')->get()->map(fn (PersonPhoto $photo): array => [
                'id' => (string) $photo->id,
                'url' => $photo->url,
                'title' => $photo->title,
                'description' => $photo->description,
                'taken_at' => $photo->taken_at?->toDateString(),
                'album_id' => $photo->photo_album_id ? (string) $photo->photo_album_id : null,
                'is_primary' => $photo->is_primary,
            ]),
            'relatives' => [
                'spouses' => $this->spouses($person)->map(fn (Person $relative) => $this->personPayload($relative)),
                'children' => $person->children()->get()->map(fn (Person $relative) => $this->personPayload($relative)),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $person = $this->person($request);
        $data = $request->validate($this->personRules(false));
        $person->update($data);

        return response()->json([
            'message' => 'Данные сохранены.',
            'person' => $this->personPayload($person->fresh()),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['confirmation' => ['required', Rule::in(['УДАЛИТЬ'])]]);
        $person = $this->person($request);

        DB::transaction(function () use ($person): void {
            $person->telegramUsers()->update(['person_id' => null]);
            $person->delete();
        });

        $request->session()->forget(['family_person_id', 'family_telegram_user_id']);

        return response()->json(['message' => 'Карточка удалена.']);
    }

    public function storeRelative(Request $request): JsonResponse
    {
        $owner = $this->person($request);
        $base = $request->validate([
            'kind' => ['required', Rule::in(['spouse', 'child'])],
            'person_id' => ['nullable', 'integer', 'exists:people,id', Rule::notIn([$owner->id])],
        ]);
        $relative = isset($base['person_id'])
            ? Person::query()->findOrFail($base['person_id'])
            : Person::query()->create($request->validate($this->personRules(true)));

        if ($base['kind'] === 'spouse') {
            Partnership::query()->firstOrCreate([
                'partner_one_id' => min($owner->id, $relative->id),
                'partner_two_id' => max($owner->id, $relative->id),
            ], ['status' => 'married']);
        } else {
            ParentChild::query()->firstOrCreate([
                'parent_id' => $owner->id,
                'child_id' => $relative->id,
            ], ['type' => 'biological']);
        }

        return response()->json([
            'message' => $base['kind'] === 'spouse' ? 'Супруг добавлен.' : 'Ребёнок добавлен.',
            'person' => $this->personPayload($relative),
        ], 201);
    }

    public function updateRelative(Request $request, Person $person): JsonResponse
    {
        $owner = $this->person($request);
        abort_unless($this->isDirectRelative($owner, $person), 403);
        $person->update($request->validate($this->personRules(false)));

        return response()->json([
            'message' => 'Данные родственника сохранены.',
            'person' => $this->personPayload($person->fresh()),
        ]);
    }

    public function destroyRelative(Request $request, Person $person): JsonResponse
    {
        $owner = $this->person($request);
        abort_unless($this->isDirectRelative($owner, $person), 403);

        ParentChild::query()
            ->where(fn ($query) => $query
                ->where('parent_id', $owner->id)
                ->where('child_id', $person->id))
            ->delete();
        Partnership::query()
            ->where(fn ($query) => $query
                ->where('partner_one_id', min($owner->id, $person->id))
                ->where('partner_two_id', max($owner->id, $person->id)))
            ->delete();

        return response()->json(['message' => 'Связь с родственником удалена.']);
    }

    public function storeAlbum(Request $request): JsonResponse
    {
        $person = $this->person($request);
        $album = $person->albums()->create([
            ...$request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:3000'],
            ]),
            'created_by_telegram_user_id' => $request->attributes->get('telegramUser')?->id,
        ]);

        return response()->json(['message' => 'Альбом создан.', 'album' => $album], 201);
    }

    public function updateAlbum(Request $request, PhotoAlbum $album): JsonResponse
    {
        $person = $this->person($request);
        abort_unless($album->person_id === $person->id, 403);
        $album->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
        ]));

        return response()->json(['message' => 'Альбом сохранён.', 'album' => $album]);
    }

    public function destroyAlbum(Request $request, PhotoAlbum $album): JsonResponse
    {
        $person = $this->person($request);
        abort_unless($album->person_id === $person->id, 403);
        $album->delete();

        return response()->json(['message' => 'Альбом удалён. Фотографии сохранены без альбома.']);
    }

    public function storePhoto(Request $request): JsonResponse
    {
        $owner = $this->person($request);
        $data = $request->validate([
            'photo' => ['required', 'image', 'max:15360'],
            'person_id' => ['nullable', 'integer', 'exists:people,id'],
            'photo_album_id' => ['nullable', 'integer', 'exists:photo_albums,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'taken_at' => ['nullable', 'date'],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        $target = isset($data['person_id'])
            ? Person::query()->findOrFail($data['person_id'])
            : $owner;
        abort_unless($target->is($owner) || $this->isDirectRelative($owner, $target), 403);

        if (! empty($data['photo_album_id'])) {
            abort_unless(
                PhotoAlbum::query()
                    ->whereKey($data['photo_album_id'])
                    ->where('person_id', $target->id)
                    ->exists(),
                422,
            );
        }

        $path = $request->file('photo')->store('people/gallery', 'public');
        $isPrimary = $request->boolean('is_primary') || ! $target->photos()->exists();

        if ($isPrimary) {
            $target->photos()->update(['is_primary' => false]);
        }

        $photo = $target->photos()->create([
            'path' => $path,
            'photo_album_id' => $data['photo_album_id'] ?? null,
            'uploaded_by_telegram_user_id' => $request->attributes->get('telegramUser')?->id,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'taken_at' => $data['taken_at'] ?? null,
            'is_primary' => $isPrimary,
        ]);

        return response()->json([
            'message' => 'Фотография загружена.',
            'photo' => ['id' => (string) $photo->id, 'url' => $photo->url],
        ], 201);
    }

    public function destroyPhoto(Request $request, PersonPhoto $photo): JsonResponse
    {
        $owner = $this->person($request);
        $target = $photo->person;
        abort_unless($target->is($owner) || $this->isDirectRelative($owner, $target), 403);

        if ($photo->path) {
            Storage::disk('public')->delete($photo->path);
        }

        $wasPrimary = $photo->is_primary;
        $photo->delete();

        if ($wasPrimary) {
            $target->photos()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['message' => 'Фотография удалена.']);
    }

    private function person(Request $request): Person
    {
        $person = $request->attributes->get('familyPerson')
            ?: $request->attributes->get('telegramUser')?->person;

        abort_unless($person, 403, 'Администратор должен сначала привязать ваш Telegram к человеку в древе.');

        return $person;
    }

    private function personRules(bool $creating): array
    {
        return [
            'first_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'maiden_name' => ['nullable', 'string', 'max:255'],
            'gender' => [$creating ? 'required' : 'sometimes', Rule::in(['male', 'female', 'unknown'])],
            'birth_date' => ['nullable', 'date'],
            'death_date' => ['nullable', 'date', 'after_or_equal:birth_date'],
            'birth_place' => ['nullable', 'string', 'max:1000'],
            'death_place' => ['nullable', 'string', 'max:1000'],
            'burial_place' => ['nullable', 'string', 'max:1000'],
            'current_city' => ['nullable', 'string', 'max:255'],
            'current_address' => ['nullable', 'string', 'max:1000'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:10000'],
        ];
    }

    private function personPayload(Person $person): array
    {
        return [
            'id' => (string) $person->id,
            'first_name' => $person->first_name,
            'middle_name' => $person->middle_name,
            'last_name' => $person->last_name,
            'maiden_name' => $person->maiden_name,
            'name' => $person->full_name,
            'gender' => $person->gender,
            'birth_date' => $person->birth_date?->toDateString(),
            'death_date' => $person->death_date?->toDateString(),
            'birth_place' => $person->birth_place,
            'death_place' => $person->death_place,
            'burial_place' => $person->burial_place,
            'current_city' => $person->current_city,
            'current_address' => $person->current_address,
            'occupation' => $person->occupation,
            'bio' => $person->bio,
            'photo_url' => $person->photo_url,
        ];
    }

    private function spouses(Person $person)
    {
        $ids = Partnership::query()
            ->where('partner_one_id', $person->id)
            ->orWhere('partner_two_id', $person->id)
            ->get()
            ->map(fn (Partnership $link): int => $link->partner_one_id === $person->id
                ? $link->partner_two_id
                : $link->partner_one_id);

        return Person::query()->whereIn('id', $ids)->get();
    }

    private function isDirectRelative(Person $owner, Person $relative): bool
    {
        return ParentChild::query()
            ->where('parent_id', $owner->id)
            ->where('child_id', $relative->id)
            ->exists()
            || Partnership::query()
                ->where('partner_one_id', min($owner->id, $relative->id))
                ->where('partner_two_id', max($owner->id, $relative->id))
                ->exists();
    }
}
