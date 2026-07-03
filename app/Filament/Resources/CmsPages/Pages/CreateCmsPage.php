<?php

namespace App\Filament\Resources\CmsPages\Pages;

use App\Filament\Resources\CmsPages\CmsPageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCmsPage extends CreateRecord
{
    protected static string $resource = CmsPageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $published = ($data['status'] ?? 'draft') === 'published';

        return [...$data, 'is_published' => $published, 'published_at' => $published ? now() : null];
    }
}
