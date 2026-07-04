<?php

namespace App\Observers;

use App\Models\PersonPhoto;
use App\Services\AnalyticsService;
use App\Services\ImageThumbnailService;
use Illuminate\Support\Facades\Storage;

class PersonPhotoObserver
{
    public function created(PersonPhoto $photo): void
    {
        app(AnalyticsService::class)->record(
            'photo_uploaded',
            tree: $photo->tree,
            parameters: ['tree_id' => $photo->tree_id, 'photo_id' => $photo->id],
            deduplicationKey: "photo_uploaded:{$photo->id}",
            queueForBrowser: true,
        );
    }

    public function saved(PersonPhoto $photo): void
    {
        if ($photo->path && (! $photo->thumbnail_path || $photo->wasChanged('path'))) {
            app(ImageThumbnailService::class)->ensureForPhoto($photo);
        }
    }

    public function deleting(PersonPhoto $photo): void
    {
        if ($photo->thumbnail_path) {
            Storage::disk('public')->delete($photo->thumbnail_path);
        }
    }
}
