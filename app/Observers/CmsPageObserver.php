<?php

namespace App\Observers;

use App\Models\CmsPage;
use App\Models\CmsPageVersion;

class CmsPageObserver
{
    public function updating(CmsPage $page): void
    {
        if (! $page->isDirty(['title', 'meta_title', 'meta_description', 'content', 'status'])) {
            return;
        }

        CmsPageVersion::query()->create([
            'cms_page_id' => $page->id,
            'user_id' => auth()->id(),
            'title' => $page->getOriginal('title'),
            'meta_title' => $page->getOriginal('meta_title'),
            'meta_description' => $page->getOriginal('meta_description'),
            'content' => $page->getOriginal('content'),
            'status' => $page->getOriginal('status') ?: 'published',
        ]);
    }
}
