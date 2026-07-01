<?php

namespace App\Filament\Resources\People\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlbumsRelationManager extends RelationManager
{
    protected static string $relationship = 'albums';

    protected static ?string $title = 'Фотоальбомы';

    protected static ?string $modelLabel = 'альбом';

    protected static ?string $pluralModelLabel = 'альбомы';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label('Описание')
                ->columnSpanFull(),
            TextInput::make('sort_order')
                ->label('Порядок')
                ->numeric()
                ->default(0)
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Название')->searchable(),
                TextColumn::make('photos_count')
                    ->label('Фотографий')
                    ->counts('photos'),
                TextColumn::make('description')
                    ->label('Описание')
                    ->limit(80),
            ])
            ->headerActions([
                CreateAction::make()->label('Создать альбом'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
