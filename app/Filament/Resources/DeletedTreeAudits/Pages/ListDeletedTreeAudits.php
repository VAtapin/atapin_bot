<?php

namespace App\Filament\Resources\DeletedTreeAudits\Pages;

use App\Filament\Resources\DeletedTreeAudits\DeletedTreeAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListDeletedTreeAudits extends ListRecords
{
    protected static string $resource = DeletedTreeAuditResource::class;
}
