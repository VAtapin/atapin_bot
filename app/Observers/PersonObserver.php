<?php

namespace App\Observers;

use App\Models\Person;
use App\Services\TreeStorageService;
use Illuminate\Support\Facades\Storage;

class PersonObserver
{
    public function deleting(Person $person): void
    {
        if (! $person->isForceDeleting()) {
            return;
        }

        $paths = $person->photos()->pluck('path')
            ->push($person->photo_path)
            ->filter()
            ->unique();
        $paths->each(fn (string $path) => Storage::disk('public')->delete($path));
        if ($person->tree) {
            app(TreeStorageService::class)->recalculate($person->tree);
        }
    }
}
