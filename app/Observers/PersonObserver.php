<?php

namespace App\Observers;

use App\Models\Person;
use App\Services\AnalyticsService;
use App\Services\ImageThumbnailService;
use App\Services\TreeStorageService;
use Illuminate\Support\Facades\Storage;

class PersonObserver
{
    public function created(Person $person): void
    {
        $analytics = app(AnalyticsService::class);
        $tree = $person->tree;
        $analytics->record(
            'person_added',
            tree: $tree,
            parameters: ['tree_id' => $person->tree_id, 'person_id' => $person->id],
            deduplicationKey: "person_added:{$person->id}",
        );
        $count = Person::query()
            ->withoutGlobalScope('family_tree')
            ->where('tree_id', $person->tree_id)
            ->count();
        if ($count === 1) {
            $analytics->record(
                'first_person_added',
                tree: $tree,
                parameters: ['tree_id' => $person->tree_id],
                deduplicationKey: "first_person_added:tree:{$person->tree_id}",
            );
        }
        if ($count >= 5) {
            $analytics->record(
                'first_5_people_added',
                tree: $tree,
                parameters: ['tree_id' => $person->tree_id, 'people_count' => $count],
                deduplicationKey: "first_5_people_added:tree:{$person->tree_id}",
                queueForBrowser: true,
            );
        }
    }

    public function saved(Person $person): void
    {
        if ($person->photo_path && (! $person->photo_thumbnail_path || $person->wasChanged('photo_path'))) {
            app(ImageThumbnailService::class)->ensureForPerson($person);
        }
    }

    public function deleting(Person $person): void
    {
        if (! $person->isForceDeleting()) {
            return;
        }

        $paths = $person->photos()->pluck('path')
            ->merge($person->photos()->pluck('thumbnail_path'))
            ->push($person->photo_path)
            ->push($person->photo_thumbnail_path)
            ->filter()
            ->unique();
        $paths->each(fn (string $path) => Storage::disk('public')->delete($path));
        if ($person->tree) {
            app(TreeStorageService::class)->recalculate($person->tree);
        }
    }
}
