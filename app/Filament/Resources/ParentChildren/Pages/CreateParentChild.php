<?php

namespace App\Filament\Resources\ParentChildren\Pages;

use App\Filament\Resources\ParentChildren\ParentChildResource;
use Filament\Resources\Pages\CreateRecord;

class CreateParentChild extends CreateRecord
{
    protected static string $resource = ParentChildResource::class;
}
