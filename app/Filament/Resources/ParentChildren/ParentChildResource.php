<?php

namespace App\Filament\Resources\ParentChildren;

use App\Filament\Resources\ParentChildren\Pages\CreateParentChild;
use App\Filament\Resources\ParentChildren\Pages\EditParentChild;
use App\Filament\Resources\ParentChildren\Pages\ListParentChildren;
use App\Models\ParentChild;
use App\Models\Person;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ParentChildResource extends Resource
{
    protected static ?string $model = ParentChild::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowLongDown;

    protected static ?string $modelLabel = 'связь родитель — ребёнок';

    protected static ?string $pluralModelLabel = 'Родители и дети';

    protected static ?string $navigationLabel = 'Родители и дети';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('parent_id')
                    ->label('Родитель')
                    ->relationship('parent', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->preload()
                    ->required(),
                Select::make('child_id')
                    ->label('Ребёнок')
                    ->relationship('child', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->preload()
                    ->required(),
                Select::make('type')
                    ->label('Тип родства')
                    ->options([
                        'biological' => 'Биологическое',
                        'adoptive' => 'Усыновление / удочерение',
                        'step' => 'Приёмное',
                        'guardian' => 'Опека',
                    ])
                    ->required()
                    ->default('biological'),
                Textarea::make('notes')
                    ->label('Примечание')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('parent.full_name')
                    ->label('Родитель')
                    ->searchable(['first_name', 'middle_name', 'last_name']),
                TextColumn::make('child.full_name')
                    ->label('Ребёнок')
                    ->searchable(['first_name', 'middle_name', 'last_name']),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'biological' => 'Биологическое',
                        'adoptive' => 'Усыновление',
                        'step' => 'Приёмное',
                        'guardian' => 'Опека',
                        default => $state,
                    }),
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
            'index' => ListParentChildren::route('/'),
            'create' => CreateParentChild::route('/create'),
            'edit' => EditParentChild::route('/{record}/edit'),
        ];
    }
}
