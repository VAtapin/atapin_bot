<?php

namespace App\Filament\Resources\People\RelationManagers;

use App\Models\Person;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChildLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'childLinks';

    protected static ?string $title = 'Дети';

    protected static ?string $modelLabel = 'ребёнка';

    protected static ?string $pluralModelLabel = 'дети';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('child_id')
                    ->label('Ребёнок')
                    ->relationship(
                        'child',
                        'last_name',
                        modifyQueryUsing: fn ($query) => $query->whereKeyNot($this->getOwnerRecord()->getKey()),
                    )
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('child.full_name')
            ->columns([
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
            ->headerActions([
                CreateAction::make()->label('Добавить ребёнка'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
