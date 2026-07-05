<?php

namespace App\Filament\Resources\CmsPages;

use App\Filament\Resources\CmsPages\Pages\CreateCmsPage;
use App\Filament\Resources\CmsPages\Pages\EditCmsPage;
use App\Filament\Resources\CmsPages\Pages\ListCmsPages;
use App\Models\CmsPage;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class CmsPageResource extends Resource
{
    protected static ?string $model = CmsPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Страницы сайта';

    protected static ?string $modelLabel = 'страница';

    protected static ?string $pluralModelLabel = 'Страницы сайта';

    protected static string|UnitEnum|null $navigationGroup = 'Сайт и справка';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Редактирование страницы')
                ->tabs([
                    Tab::make('Основное')
                        ->schema([
                            Select::make('locale')
                                ->label('Язык')
                                ->options(['ru' => 'Русский', 'de' => 'Deutsch', 'en' => 'English', 'uk' => 'Українська'])
                                ->required()
                                ->helperText('Эта запись публикуется только на выбранном языке. Для другой языковой ссылки создайте отдельную страницу/перевод.'),
                            TextInput::make('slug')
                                ->label('Адрес')
                                ->required()
                                ->unique(
                                    ignoreRecord: true,
                                    modifyRuleUsing: fn (Unique $rule, callable $get) => $rule->where('locale', $get('locale')),
                                )
                                ->helperText('Часть адреса без / и без домена. Для разных языков лучше использовать понятные отдельные адреса.'),
                            TextInput::make('title')->label('Заголовок')->required(),
                            Select::make('status')->label('Состояние')->options([
                                'draft' => 'Черновик',
                                'published' => 'Опубликована',
                            ])->default('published')->required(),
                            TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
                        ]),
                    Tab::make('Контент')
                        ->schema([
                            RichEditor::make('content')
                                ->label('Визуальный редактор')
                                ->fileAttachments(true)
                                ->fileAttachmentsDisk('public')
                                ->fileAttachmentsDirectory('cms/content')
                                ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->fileAttachmentsMaxSize(5120)
                                ->toolbarButtons([
                                    ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link'],
                                    ['h2', 'h3'],
                                    ['alignStart', 'alignCenter', 'alignEnd'],
                                    ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                                    ['table', 'attachFiles'],
                                    ['undo', 'redo'],
                                ])
                                ->helperText('Кнопка со скрепкой загружает изображение на наш сервер и вставляет его в текст. JPEG, PNG, WebP до 5 MB. Опасный HTML удаляется автоматически.')
                                ->required()
                                ->columnSpanFull(),
                            CodeEditor::make('content_html')
                                ->label('HTML-код')
                                ->language(Language::Html)
                                ->afterStateHydrated(fn (CodeEditor $component, Get $get) => $component->state($get('content')))
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, Set $set) => $set('content', $state))
                                ->dehydrated(false)
                                ->helperText('Для ручной правки разметки. После сохранения код очищается от опасных тегов и атрибутов.')
                                ->columnSpanFull(),
                        ]),
                    Tab::make('SEO и соцсети')
                        ->schema([
                            TextInput::make('meta_title')->label('SEO-заголовок'),
                            Textarea::make('meta_description')->label('SEO-описание')->rows(2),
                            FileUpload::make('og_image_path')
                                ->label('Изображение OpenGraph')
                                ->image()
                                ->disk('public')
                                ->directory('cms/og')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(5120)
                                ->helperText('Рекомендуемый размер 1200×630. Если не указано, используется общее изображение сайта.'),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Страница')->searchable(),
            TextColumn::make('slug')->label('Адрес'),
            TextColumn::make('locale')->label('Язык')->badge(),
            TextColumn::make('status')->label('Состояние')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'published' ? 'Опубликована' : 'Черновик'),
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
