<?php

namespace App\Filament\Resources\Partnerships;

use App\Filament\Resources\Partnerships\Pages\CreatePartnership;
use App\Filament\Resources\Partnerships\Pages\EditPartnership;
use App\Filament\Resources\Partnerships\Pages\ListPartnerships;
use App\Models\Partnership;
use App\Models\Person;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartnershipResource extends Resource
{
    protected static ?string $model = Partnership::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $modelLabel = 'союз';

    protected static ?string $pluralModelLabel = 'Пары и браки';

    protected static ?string $navigationLabel = 'Пары и браки';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('partner_one_id')
                    ->label('Первый партнёр')
                    ->relationship('partnerOne', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->preload()
                    ->required(),
                Select::make('partner_two_id')
                    ->label('Второй партнёр')
                    ->relationship('partnerTwo', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->preload()
                    ->required(),
                Select::make('status')
                    ->label('Статус')
                    ->options([
                        'married' => 'В браке',
                        'partners' => 'Партнёры',
                        'divorced' => 'Разведены',
                        'widowed' => 'Вдовство',
                    ])
                    ->required()
                    ->default('married'),
                DatePicker::make('started_at')
                    ->label('Дата начала / свадьбы')
                    ->native(false),
                DatePicker::make('ended_at')
                    ->label('Дата окончания')
                    ->native(false),
                TextInput::make('place')
                    ->label('Место'),
                Textarea::make('notes')
                    ->label('Примечание')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('partnerOne.full_name')
                    ->label('Первый партнёр'),
                TextColumn::make('partnerTwo.full_name')
                    ->label('Второй партнёр'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'married' => 'В браке',
                        'partners' => 'Партнёры',
                        'divorced' => 'Разведены',
                        'widowed' => 'Вдовство',
                        default => $state,
                    }),
                TextColumn::make('started_at')
                    ->label('Начало')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label('Окончание')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('place')
                    ->label('Место')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerships::route('/'),
            'create' => CreatePartnership::route('/create'),
            'edit' => EditPartnership::route('/{record}/edit'),
        ];
    }
}
