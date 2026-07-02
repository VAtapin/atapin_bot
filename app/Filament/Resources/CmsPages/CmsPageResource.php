<?php

namespace App\Filament\Resources\CmsPages;

use App\Filament\Resources\CmsPages\Pages\CreateCmsPage;
use App\Filament\Resources\CmsPages\Pages\EditCmsPage;
use App\Filament\Resources\CmsPages\Pages\ListCmsPages;
use App\Models\CmsPage;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CmsPageResource extends Resource
{
    protected static ?string $model = CmsPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Страницы сайта';

    protected static ?string $modelLabel = 'страница';

    protected static ?string $pluralModelLabel = 'Страницы сайта';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('locale')->label('Язык')->options(['ru' => 'Русский', 'de' => 'Deutsch', 'en' => 'English'])->required(),
            TextInput::make('slug')->label('Адрес')->required()->unique(ignoreRecord: true),
            TextInput::make('title')->label('Заголовок')->required(),
            TextInput::make('meta_title')->label('SEO-заголовок'),
            Textarea::make('meta_description')->label('SEO-описание')->rows(2),
            Textarea::make('content')->label('Содержимое')->rows(18)->required()->columnSpanFull(),
            Toggle::make('is_published')->label('Опубликована')->default(true),
            TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Страница')->searchable(),
            TextColumn::make('slug')->label('Адрес'),
            TextColumn::make('locale')->label('Язык')->badge(),
            IconColumn::make('is_published')->label('Опубликована')->boolean(),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCmsPages::route('/'),
            'create' => CreateCmsPage::route('/create'),
            'edit' => EditCmsPage::route('/{record}/edit'),
        ];
    }
}
