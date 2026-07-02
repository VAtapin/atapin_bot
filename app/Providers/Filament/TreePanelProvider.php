<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ChangeLogs\ChangeLogResource;
use App\Filament\Pages\EditTreeProfile;
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
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->sidebarCollapsibleOnDesktop()
            ->tenant(FamilyTree::class, slugAttribute: 'slug', ownershipRelationship: 'tree')
            ->tenantProfile(EditTreeProfile::class)
            ->searchableTenantMenu()
            ->tenantMenuItems([
                Action::make('open_family')
                    ->label('Открыть семейное дерево')
                    ->url(fn (): string => route('family.tree', filament()->getTenant()))
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
