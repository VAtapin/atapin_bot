<?php

namespace App\Filament\Resources\FaqCategories;

use App\Filament\Resources\FaqCategories\Pages\CreateFaqCategory;
use App\Filament\Resources\FaqCategories\Pages\EditFaqCategory;
use App\Filament\Resources\FaqCategories\Pages\ListFaqCategories;
use App\Models\FaqCategory;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class FaqCategoryResource extends Resource
{
    protected static ?string $model = FaqCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $navigationLabel = 'Разделы FAQ';

    protected static ?string $modelLabel = 'раздел FAQ';

    protected static ?string $pluralModelLabel = 'Разделы FAQ';

    protected static string|UnitEnum|null $navigationGroup = 'Сайт и справка';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('locale')
                ->label('Язык')
                ->options(['ru' => 'Русский', 'de' => 'Deutsch', 'en' => 'English', 'uk' => 'Українська'])
                ->default('ru')
                ->required(),
            TextInput::make('title')
                ->label('Название раздела')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')
                ->label('Адрес раздела')
                ->required()
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('locale', $get('locale')),
                ),
            Textarea::make('description')
                ->label('Короткое описание')
                ->rows(3)
                ->columnSpanFull(),
            TextInput::make('sort_order')->label('Порядок')->numeric()->default(0)->required(),
            Toggle::make('is_published')->label('Показывать на сайте')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Раздел')->searchable()->sortable(),
                TextColumn::make('slug')->label('Адрес')->searchable(),
                TextColumn::make('locale')->label('Язык')->badge(),
                TextColumn::make('items_count')->counts('items')->label('Вопросов'),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
                IconColumn::make('is_published')->label('Опубликован')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqCategories::route('/'),
            'create' => CreateFaqCategory::route('/create'),
            'edit' => EditFaqCategory::route('/{record}/edit'),
        ];
    }
}
