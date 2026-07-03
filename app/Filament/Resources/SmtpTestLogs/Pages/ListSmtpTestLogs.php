<?php

namespace App\Filament\Resources\SmtpTestLogs\Pages;

use App\Filament\Resources\SmtpTestLogs\SmtpTestLogResource;
use Filament\Resources\Pages\ListRecords;

class ListSmtpTestLogs extends ListRecords
{
    protected static string $resource = SmtpTestLogResource::class;
}
