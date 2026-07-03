<?php

namespace App\Filament\Resources\FamilyEvents\Pages;

use App\Filament\Resources\FamilyEvents\FamilyEventResource;
use App\Support\CurrentTree;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateFamilyEvent extends CreateRecord
{
    protected static string $resource = FamilyEventResource::class;

    protected function afterCreate(): void
    {
        $tree = app(CurrentTree::class)->get();
        Notification::make()
            ->title('Событие создано')
            ->body($tree ? route('family.tree', ['tree' => $tree, 'tab' => 'events']) : null)
            ->success()
            ->send();
    }
}
