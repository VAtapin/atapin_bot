<?php

namespace App\Providers\Filament;

use App\Filament\Pages\EditTreeProfile;
use App\Filament\Pages\TreeIntegrity;
use App\Filament\Resources\ChangeLogs\ChangeLogResource;
use App\Filament\Resources\DataIssues\DataIssueResource;
use App\Filament\Resources\FamilyEvents\FamilyEventResource;
use App\Filament\Resources\ParentChildren\ParentChildResource;
use App\Filament\Resources\Partnerships\PartnershipResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\Settings\SettingResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\TelegramGroups\TelegramGroupResource;
use App\Filament\Resources\TreeBackups\TreeBackupResource;
use App\Filament\Resources\TreeImports\TreeImportResource;
use App\Filament\Resources\TreeInvitations\TreeInvitationResource;
use App\Filament\Resources\TreeMemberships\TreeMembershipResource;
use App\Filament\Widgets\FamilyStats;
use App\Filament\Widgets\UpcomingBirthdays;
use App\Http\Middleware\ApplyTreePanelContext;
use App\Http\Middleware\RequireOwnerTwoFactor;
use App\Models\FamilyTree;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TreePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tree')
            ->path('manage')
            ->login(fn () => redirect()->route('login'))
            ->passwordReset()
            ->brandName('Я и дом мой — управление деревом')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->maxContentWidth(Width::Full)
            ->sidebarCollapsibleOnDesktop()
            ->tenant(FamilyTree::class, slugAttribute: 'slug', ownershipRelationship: 'tree')
            ->tenantProfile(EditTreeProfile::class)
            ->searchableTenantMenu()
            ->navigationItems([
                NavigationItem::make('Открыть семейное дерево')
                    ->icon('heroicon-o-share')
                    ->sort(-100)
                    ->url(fn (): string => route('tree.preview', [
                        'tree' => Filament::getTenant(),
                        'mode' => 'normal',
                    ]))
                    ->openUrlInNewTab(),
                NavigationItem::make('Смотреть как участник')
                    ->icon('heroicon-o-eye')
                    ->sort(-99)
                    ->url(fn (): string => route('tree.preview', [
                        'tree' => Filament::getTenant(),
                        'mode' => 'member',
                    ])),
                NavigationItem::make('Смотреть как гость')
                    ->icon('heroicon-o-eye-slash')
                    ->sort(-98)
                    ->url(fn (): string => route('tree.preview', [
                        'tree' => Filament::getTenant(),
                        'mode' => 'guest',
                    ])),
                NavigationItem::make('Основные настройки')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->sort(80)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    ).'#basic'),
                NavigationItem::make('Оформление и герб')
                    ->icon('heroicon-o-photo')
                    ->sort(81)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    ).'#appearance'),
                NavigationItem::make('Приватность')
                    ->icon('heroicon-o-lock-closed')
                    ->sort(82)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    ).'#privacy'),
                NavigationItem::make('Уведомления')
                    ->icon('heroicon-o-bell')
                    ->sort(83)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    ).'#privacy'),
                NavigationItem::make('Мессенджеры и бот')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->sort(84)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    ).'#messengers'),
                NavigationItem::make('Собственный домен')
                    ->icon('heroicon-o-globe-alt')
                    ->sort(85)
                    ->visible(fn (): bool => (bool) (
                        auth()->user()?->ownsTree(Filament::getTenant())
                        && Filament::getTenant()?->plan?->custom_domain
                    ))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    ).'#domain'),
                NavigationItem::make('Выгрузить данные дерева')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->sort(86)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => route('trees.export', Filament::getTenant())),
                NavigationItem::make('Удаление дерева')
                    ->icon('heroicon-o-trash')
                    ->sort(87)
                    ->visible(fn (): bool => (bool) auth()->user()?->ownsTree(Filament::getTenant()))
                    ->url(fn (): string => EditTreeProfile::getUrl(
                        panel: 'tree',
                        tenant: Filament::getTenant(),
                    )),
            ])
            ->tenantMenuItems([
                Action::make('open_family')
                    ->label('Открыть семейное дерево')
                    ->url(fn (): string => route('tree.preview', [
                        'tree' => filament()->getTenant(),
                        'mode' => 'normal',
                    ]))
                    ->openUrlInNewTab(),
                Action::make('export_tree')
                    ->label('Выгрузить данные дерева')
                    ->url(fn (): string => route('trees.export', filament()->getTenant()))
                    ->visible(fn (): bool => (bool) (
                        filament()->getTenant()
                        && auth()->user()?->ownsTree(filament()->getTenant())
                    )),
                Action::make('platform')
                    ->label('Панель платформы')
                    ->url(fn (): string => route('tree.management.leave'))
                    ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
            ])
            ->resources([
                PersonResource::class,
                ParentChildResource::class,
                PartnershipResource::class,
                FamilyEventResource::class,
                TelegramGroupResource::class,
                TreeMembershipResource::class,
                TreeInvitationResource::class,
                DataIssueResource::class,
                ChangeLogResource::class,
                TreeBackupResource::class,
                TreeImportResource::class,
                SubscriptionResource::class,
                PaymentResource::class,
                SettingResource::class,
            ])
            ->pages([
                Dashboard::class,
                TreeIntegrity::class,
            ])
            ->widgets([
                AccountWidget::class,
                FamilyStats::class,
                UpcomingBirthdays::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.tree-context'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->tenantMiddleware([
                ApplyTreePanelContext::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
                RequireOwnerTwoFactor::class,
            ]);
    }
}
