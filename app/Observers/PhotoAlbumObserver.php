<?php

namespace App\Observers;

use App\Models\PhotoAlbum;
use App\Services\AnalyticsService;

class PhotoAlbumObserver
{
    public function created(PhotoAlbum $album): void
    {
        app(AnalyticsService::class)->record(
            'album_created',
            tree: $album->tree,
            parameters: ['tree_id' => $album->tree_id, 'album_id' => $album->id],
            deduplicationKey: "album_created:{$album->id}",
        );
    }
}
