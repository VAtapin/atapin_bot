<?php

namespace App\Filament\Resources\TreeImports;

use App\Filament\Components\ImportFileUpload;
use App\Filament\Resources\TreeImports\Pages\CreateTreeImport;
use App\Filament\Resources\TreeImports\Pages\ListTreeImports;
use App\Models\TreeImport;
use App\Rules\SafeImportFile;
use App\Services\ImportFileValidator;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreeImportResource extends Resource
{
    protected static ?string $model = TreeImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Импорт данных';

    protected static ?string $modelLabel = 'импорт';

    protected static ?string $pluralModelLabel = 'Импорт данных';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('format')->label('Формат')->options([
                'gedcom' => 'GEDCOM',
                'gramps' => 'Gramps (.gramps или XML)',
                'csv' => 'CSV',
            ])->required()->live(),
            ImportFileUpload::make('path')
                ->label('Файл')
                ->disk('local')
                ->directory(fn (): string => 'tree-imports/'.app(CurrentTree::class)->id())
                ->acceptedExtensions(fn (Get $get): array => array_map(
                    fn (string $extension): string => '.'.$extension,
                    ImportFileValidator::EXTENSIONS[$get('format')] ?? [],
                ))
                ->rule(fn (Get $get): SafeImportFile => new SafeImportFile((string) $get('format')))
                ->storeFileNamesIn('original_name')
                ->helperText(fn (Get $get): string => match ($get('format')) {
                    'gedcom' => 'Разрешены только текстовые файлы .ged и .gedcom',
                    'gramps' => 'Разрешены архив Gramps .gramps (gzip) и несжатый .xml',
                    'csv' => 'Разрешены только текстовые файлы .csv',
                    default => 'Сначала выберите формат.',
                })
                ->maxSize(102400)
                ->required(),
            Toggle::make('replace_existing')
                ->label('Заменить существующих людей и связи')
                ->helperText('Перед заменой рекомендуется создать резервную копию.'),
            Toggle::make('download_photos')->label('Загрузить фотографии из GEDCOM'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Запущен')->dateTime('d.m.Y H:i'),
            TextColumn::make('format')->label('Формат')->badge(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('statistics')->label('Результат')->formatStateUsing(
                fn ($state): string => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : '',
            )->limit(80),
            TextColumn::make('error')->label('Ошибка')->limit(80),
        ])->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTreeImports::route('/'),
            'create' => CreateTreeImport::route('/create'),
        ];
    }

    public static function canViewAny(): bool
    {
        $tree = app(CurrentTree::class)->get();

        return (bool) ($tree && auth()->user()?->ownsTree($tree));
    }
}
