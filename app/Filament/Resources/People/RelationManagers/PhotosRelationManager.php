<?php

namespace App\Filament\Resources\People\RelationManagers;

use App\Support\CurrentTree;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    protected static ?string $title = 'Фотографии';

    protected static ?string $modelLabel = 'фотография';

    protected static ?string $pluralModelLabel = 'фотографии';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Файл')
                ->image()
                ->imageEditor()
                ->directory(fn (): string => 'trees/'.app(CurrentTree::class)->id().'/people/gallery')
                ->disk('public')
                ->visibility('public'),
            TextInput::make('source_url')
                ->label('Ссылка на оригинал')
                ->url(),
            Select::make('photo_album_id')
                ->label('Альбом')
                ->relationship(
                    'album',
                    'title',
                    modifyQueryUsing: fn ($query) => $query->where(
                        'person_id',
                        $this->getOwnerRecord()->getKey(),
                    ),
                )
                ->searchable()
                ->preload(),
            TextInput::make('title')->label('Название'),
            DatePicker::make('taken_at')
                ->label('Дата съёмки')
                ->native(false),
            Textarea::make('description')
                ->label('Описание')
                ->columnSpanFull(),
            Toggle::make('is_primary')
                ->label('Основная фотография'),
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
                ImageColumn::make('url')
                    ->label('Фото')
                    ->square(),
                TextColumn::make('title')->label('Название'),
                TextColumn::make('album.title')->label('Альбом'),
                TextColumn::make('taken_at')->label('Дата')->date('d.m.Y'),
                IconColumn::make('is_primary')->label('Основная')->boolean(),
                TextColumn::make('source_url')
                    ->label('Источник')
                    ->limit(45)
                    ->url(fn ($record): ?string => $record->source_url),
            ])
            ->headerActions([
                CreateAction::make()->label('Добавить фотографию'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
