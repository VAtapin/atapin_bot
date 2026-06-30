<?php

namespace App\Filament\Resources\FamilyEvents;

use App\Filament\Resources\FamilyEvents\Pages\CreateFamilyEvent;
use App\Filament\Resources\FamilyEvents\Pages\EditFamilyEvent;
use App\Filament\Resources\FamilyEvents\Pages\ListFamilyEvents;
use App\Models\FamilyEvent;
use App\Models\Person;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FamilyEventResource extends Resource
{
    protected static ?string $model = FamilyEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'событие';

    protected static ?string $pluralModelLabel = 'Семейные события';

    protected static ?string $navigationLabel = 'События';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('person_id')
                    ->label('Связанный человек')
                    ->relationship('person', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->preload(),
                Select::make('type')
                    ->label('Тип')
                    ->options([
                        'birthday' => 'День рождения',
                        'anniversary' => 'Годовщина',
                        'wedding' => 'Свадьба',
                        'reunion' => 'Семейная встреча',
                        'memorial' => 'Памятная дата',
                        'other' => 'Другое',
                    ])
                    ->required()
                    ->default('other'),
                TextInput::make('title')
                    ->label('Название')
                    ->required(),
                Textarea::make('description')
                    ->label('Описание')
                    ->columnSpanFull(),
                DatePicker::make('event_date')
                    ->label('Дата')
                    ->native(false)
                    ->required(),
                TimePicker::make('event_time')
                    ->label('Время')
                    ->seconds(false),
                TextInput::make('place')
                    ->label('Место'),
                Toggle::make('is_annual')
                    ->label('Повторять ежегодно')
                    ->required(),
                Toggle::make('is_published')
                    ->label('Показывать семье')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('person.full_name')
                    ->label('Человек'),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable(),
                TextColumn::make('event_date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('event_time')
                    ->label('Время')
                    ->time()
                    ->sortable(),
                TextColumn::make('place')
                    ->label('Место')
                    ->searchable(),
                IconColumn::make('is_annual')
                    ->label('Ежегодно')
                    ->boolean(),
                IconColumn::make('is_published')
                    ->label('Опубликовано')
                    ->boolean(),
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
            'index' => ListFamilyEvents::route('/'),
            'create' => CreateFamilyEvent::route('/create'),
            'edit' => EditFamilyEvent::route('/{record}/edit'),
        ];
    }
}
