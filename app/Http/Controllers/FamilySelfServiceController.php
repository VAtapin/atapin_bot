<?php

namespace App\Http\Controllers;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\PhotoAlbum;
use App\Services\TreeStorageService;
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
        $children = $person->children()->get();
        $childIds = $children->pluck('id');
        $grandchildIds = ParentChild::query()
            ->whereIn('parent_id', $childIds)
            ->pluck('child_id')
            ->unique();
        $grandchildren = Person::query()
            ->whereIn('id', $grandchildIds)
            ->whereKeyNot($person->id)
            ->whereNotIn('id', $childIds)
            ->get();
        $childSpouses = Person::query()
            ->whereIn('id', Partnership::query()
                ->where(fn ($query) => $query
                    ->whereIn('partner_one_id', $childIds)
                    ->orWhereIn('partner_two_id', $childIds))
                ->get()
                ->flatMap(fn (Partnership $link): array => [$link->partner_one_id, $link->partner_two_id])
                ->reject(fn (int $id): bool => $childIds->contains($id))
                ->unique())
            ->get();

        return response()->json([
            'can_edit' => ! $request->attributes->get('treeMembership')
                || $request->attributes->get('treeMembership')->canEditFamily(),
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
                'children' => $children->map(fn (Person $relative) => $this->personPayload($relative)),
                'grandchildren' => $grandchildren->map(fn (Person $relative) => $this->personPayload($relative)),
                'child_spouses' => $childSpouses->map(fn (Person $relative) => $this->personPayload($relative)),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $person = $this->editablePerson($request);
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
        $person = $this->editablePerson($request);

        DB::transaction(function () use ($person): void {
            $person->telegramUsers()->update(['person_id' => null]);
            DB::table('tree_memberships')->where('person_id', $person->id)->update(['person_id' => null]);
            $person->delete();
        });

        $request->session()->forget(['family_person_id', 'family_telegram_user_id']);

        return response()->json(['message' => 'Карточка удалена.']);
    }

    public function storeRelative(Request $request): JsonResponse
    {
        $owner = $this->editablePerson($request);
        $base = $request->validate([
            'kind' => ['required', Rule::in(['spouse', 'child', 'grandchild', 'child_spouse'])],
            'person_id' => ['nullable', 'integer', 'exists:people,id', Rule::notIn([$owner->id])],
            'related_person_id' => ['nullable', 'integer', 'exists:people,id'],
        ]);
        if (in_array($base['kind'], ['grandchild', 'child_spouse'], true)) {
            abort_unless(
                isset($base['related_person_id'])
                && $owner->children()->whereKey($base['related_person_id'])->exists(),
                403,
            );
        }
        $relative = isset($base['person_id'])
            ? Person::query()->findOrFail($base['person_id'])
            : Person::query()->create($request->validate($this->personRules(true)));

        if (in_array($base['kind'], ['spouse', 'child_spouse'], true)) {
            $partnerId = $base['kind'] === 'spouse' ? $owner->id : $base['related_person_id'];
            Partnership::query()->firstOrCreate([
                'partner_one_id' => min($partnerId, $relative->id),
                'partner_two_id' => max($partnerId, $relative->id),
            ], ['status' => 'married']);
        } else {
            $parentId = $base['kind'] === 'child' ? $owner->id : $base['related_person_id'];
            ParentChild::query()->firstOrCreate([
                'parent_id' => $parentId,
                'child_id' => $relative->id,
            ], ['type' => 'biological']);
        }

        return response()->json([
            'message' => match ($base['kind']) {
                'spouse' => 'Супруг добавлен.',
                'child' => 'Ребёнок добавлен.',
                'grandchild' => 'Внук или внучка добавлены.',
                'child_spouse' => 'Зять или невестка добавлены.',
            },
            'person' => $this->personPayload($relative),
        ], 201);
    }

    public function updateRelative(Request $request, int|string $person): JsonResponse
    {
        $owner = $this->editablePerson($request);
        $person = Person::query()->findOrFail((int) $person);
        abort_unless($this->isEditableRelative($owner, $person), 403);
        $person->update($request->validate($this->personRules(false)));

        return response()->json([
            'message' => 'Данные родственника сохранены.',
            'person' => $this->personPayload($person->fresh()),
        ]);
    }

    public function destroyRelative(Request $request, int|string $person): JsonResponse
    {
        $owner = $this->editablePerson($request);
        $person = Person::query()->findOrFail((int) $person);
        abort_unless($this->isEditableRelative($owner, $person), 403);

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
        $childIds = $owner->children()->pluck('people.id');
        ParentChild::query()
            ->whereIn('parent_id', $childIds)
            ->where('child_id', $person->id)
            ->delete();
        Partnership::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereIn('partner_one_id', $childIds)
                    ->where('partner_two_id', $person->id))
                ->orWhere(fn ($query) => $query
                    ->whereIn('partner_two_id', $childIds)
                    ->where('partner_one_id', $person->id)))
            ->delete();

        return response()->json(['message' => 'Связь с родственником удалена.']);
    }

    public function storeAlbum(Request $request): JsonResponse
    {
        $person = $this->editablePerson($request);
        $album = $person->albums()->create([
            ...$request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:3000'],
            ]),
            'created_by_telegram_user_id' => $request->attributes->get('telegramUser')?->id,
        ]);

        return response()->json(['message' => 'Альбом создан.', 'album' => $album], 201);
    }

    public function updateAlbum(Request $request, int|string $album): JsonResponse
    {
        $person = $this->editablePerson($request);
        $album = PhotoAlbum::query()->findOrFail((int) $album);
        abort_unless($album->person_id === $person->id, 403);
        $album->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
        ]));

        return response()->json(['message' => 'Альбом сохранён.', 'album' => $album]);
    }

    public function destroyAlbum(Request $request, int|string $album): JsonResponse
    {
        $person = $this->editablePerson($request);
        $album = PhotoAlbum::query()->findOrFail((int) $album);
        abort_unless($album->person_id === $person->id, 403);
        $album->delete();

        return response()->json(['message' => 'Альбом удалён. Фотографии сохранены без альбома.']);
    }

    public function storePhoto(Request $request): JsonResponse
    {
        $owner = $this->editablePerson($request);
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
        abort_unless($target->is($owner) || $this->isEditableRelative($owner, $target), 403);

        if (! empty($data['photo_album_id'])) {
            abort_unless(
                PhotoAlbum::query()
                    ->whereKey($data['photo_album_id'])
                    ->where('person_id', $target->id)
                    ->exists(),
                422,
            );
        }

        $tree = $request->attributes->get('familyTree');
        $fileSize = (int) $request->file('photo')->getSize();
        app(TreeStorageService::class)->ensureCanStore($tree, $fileSize);
        $path = $request->file('photo')->store("trees/{$tree->id}/people/gallery", 'public');
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
            'file_size' => $fileSize,
        ]);
        app(TreeStorageService::class)->recalculate($tree);

        return response()->json([
            'message' => 'Фотография загружена.',
            'photo' => ['id' => (string) $photo->id, 'url' => $photo->url],
        ], 201);
    }

    public function destroyPhoto(Request $request, int|string $photo): JsonResponse
    {
        $owner = $this->editablePerson($request);
        $photo = PersonPhoto::query()->findOrFail((int) $photo);
        $target = $photo->person;
        abort_unless($target->is($owner) || $this->isEditableRelative($owner, $target), 403);

        if ($photo->path) {
            Storage::disk('public')->delete($photo->path);
        }

        $wasPrimary = $photo->is_primary;
        $photo->delete();
        app(TreeStorageService::class)->recalculate($request->attributes->get('familyTree'));

        if ($wasPrimary) {
            $target->photos()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['message' => 'Фотография удалена.']);
    }

    private function person(Request $request): Person
    {
        $person = $request->attributes->get('familyPerson')
            ?: $request->attributes->get('treeMembership')?->person
            ?: $request->attributes->get('telegramUser')?->person;

        abort_unless($person, 403, 'Администратор должен сначала привязать ваш Telegram к человеку в древе.');

        return $person;
    }

    private function editablePerson(Request $request): Person
    {
        $membership = $request->attributes->get('treeMembership');
        if ($membership) {
            abort_unless($membership->canEditFamily(), 403, 'У вас есть доступ только для просмотра.');
        }

        return $this->person($request);
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
            'birth_date_precision' => $person->birth_date_precision,
            'death_date' => $person->death_date?->toDateString(),
            'death_date_precision' => $person->death_date_precision,
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

    private function isEditableRelative(Person $owner, Person $relative): bool
    {
        if ($this->isDirectRelative($owner, $relative)) {
            return true;
        }

        $childIds = $owner->children()->pluck('people.id');

        return ParentChild::query()
            ->whereIn('parent_id', $childIds)
            ->where('child_id', $relative->id)
            ->exists()
            || Partnership::query()
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query
                        ->whereIn('partner_one_id', $childIds)
                        ->where('partner_two_id', $relative->id))
                    ->orWhere(fn ($query) => $query
                        ->whereIn('partner_two_id', $childIds)
                        ->where('partner_one_id', $relative->id)))
                ->exists();
    }
}
