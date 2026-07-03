<?php

namespace App\Filament\Resources\FaqItems;

use App\Filament\Resources\FaqItems\Pages\CreateFaqItem;
use App\Filament\Resources\FaqItems\Pages\EditFaqItem;
use App\Filament\Resources\FaqItems\Pages\ListFaqItems;
use App\Models\FaqItem;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class FaqItemResource extends Resource
{
    protected static ?string $model = FaqItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?string $navigationLabel = 'Вопросы и ответы';

    protected static ?string $modelLabel = 'вопрос';

    protected static ?string $pluralModelLabel = 'Вопросы и ответы';

    protected static string|UnitEnum|null $navigationGroup = 'Сайт и справка';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('faq_category_id')
                ->label('Раздел')
                ->relationship('category', 'title')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('question')->label('Вопрос')->required()->columnSpanFull(),
            RichEditor::make('answer')
                ->label('Ответ')
                ->required()
                ->helperText('Начните с короткого ответа, затем перечислите действия по порядку.')
                ->columnSpanFull(),
            TextInput::make('keywords')
                ->label('Ключевые слова для поиска')
                ->placeholder('например: GEDCOM, импорт, файл')
                ->columnSpanFull(),
            TextInput::make('sort_order')->label('Порядок')->numeric()->default(0)->required(),
            Toggle::make('is_published')->label('Показывать на сайте')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')->label('Вопрос')->searchable()->wrap(),
                TextColumn::make('category.title')->label('Раздел')->badge()->sortable(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
                IconColumn::make('is_published')->label('Опубликован')->boolean(),
                TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('faq_category_id')
                    ->label('Раздел')
                    ->relationship('category', 'title'),
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
            'index' => ListFaqItems::route('/'),
            'create' => CreateFaqItem::route('/create'),
            'edit' => EditFaqItem::route('/{record}/edit'),
        ];
    }
}
