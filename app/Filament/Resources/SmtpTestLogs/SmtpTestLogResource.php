<?php

namespace App\Filament\Resources\SmtpTestLogs;

use App\Filament\Resources\SmtpTestLogs\Pages\ListSmtpTestLogs;
use App\Models\SmtpTestLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SmtpTestLogResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 20;

    protected static ?string $model = SmtpTestLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Проверки SMTP';

    protected static ?string $modelLabel = 'проверка SMTP';

    protected static ?string $pluralModelLabel = 'Проверки SMTP';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('recipient')->label('Получатель')->searchable(),
                TextColumn::make('status')->label('Результат')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'accepted' ? 'Принято SMTP' : 'Ошибка'),
                TextColumn::make('stage')->label('Этап')->badge(),
                TextColumn::make('message_id')->label('Message-ID')->copyable()->limit(35),
                TextColumn::make('error')->label('Ошибка')->wrap()->limit(100),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSmtpTestLogs::route('/')];
    }
}
