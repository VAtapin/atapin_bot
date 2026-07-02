<?php

namespace App\Filament\Resources\DataIssues\Pages;

use App\Filament\Resources\DataIssues\DataIssueResource;
use Filament\Resources\Pages\EditRecord;

class EditDataIssue extends EditRecord
{
    protected static string $resource = DataIssueResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (in_array($data['status'], ['resolved', 'rejected'], true)) {
            $data['resolved_by_user_id'] = auth()->id();
            $data['resolved_at'] = now();
        }

        return $data;
    }
}
