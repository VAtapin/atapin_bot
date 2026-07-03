<?php

namespace App\Observers;

use App\Models\Person;
use App\Services\ImageThumbnailService;
use App\Services\TreeStorageService;
use Illuminate\Support\Facades\Storage;

class PersonObserver
{
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
