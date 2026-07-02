<?php

namespace App\Filament\Pages;

use App\Models\FamilyTree;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class EditTreeProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Основные настройки дерева';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Название семьи')
                ->required()
                ->maxLength(150),
            TextInput::make('slug')
                ->label('Адрес дерева')
                ->prefix(config('app.url').'/family/')
                ->alphaDash()
                ->rules(['ascii', 'not_in:person,login,register,admin,manage,api'])
                ->unique(FamilyTree::class, 'slug', ignoreRecord: true)
                ->required()
                ->maxLength(80),
            TextInput::make('subtitle')
                ->label('Подзаголовок')
                ->maxLength(255),
            Select::make('timezone')
                ->label('Часовой пояс')
                ->options([
                    'Europe/Berlin' => 'Europe/Berlin',
                    'Europe/Moscow' => 'Europe/Moscow',
                    'UTC' => 'UTC',
                ])
                ->required(),
            ColorPicker::make('accent_color')
                ->label('Цвет оформления')
                ->required(),
            TextInput::make('primary_domain')
                ->label('Собственный домен')
                ->helperText('Доступно на тарифе с поддержкой собственного домена.')
                ->disabled(fn (): bool => ! $this->tenant->plan?->custom_domain),
        ]);
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof FamilyTree
            && (bool) auth()->user()?->ownsTree($tenant);
    }
}
