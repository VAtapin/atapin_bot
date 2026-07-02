<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ChangeLogs\ChangeLogResource;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\CmsPages\CmsPageResource;
use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PlatformSettings\PlatformSettingResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\PlatformStats;
use App\Http\Middleware\RequireOwnerTwoFactor;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(fn () => redirect()->route('login'))
            ->passwordReset()
            ->brandName('Я и дом мой')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->resources([
                FamilyTreeResource::class,
                UserResource::class,
                PlanResource::class,
                SubscriptionResource::class,
                PaymentResource::class,
                PlatformSettingResource::class,
                CmsPageResource::class,
                TelegramUpdateResource::class,
                ChangeLogResource::class,
            ])
            ->pages([
                Dashboard::class,
                SystemHealth::class,
            ])
            ->widgets([
                AccountWidget::class,
                PlatformStats::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.platform-context'),
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
            ->authMiddleware([
                Authenticate::class,
                RequireOwnerTwoFactor::class,
            ]);
    }
}
