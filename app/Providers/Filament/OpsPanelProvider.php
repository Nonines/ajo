<?php

namespace App\Providers\Filament;

use App\Support\Auth\StaffAppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Configures the ops panel with separate staff authentication and mandatory recoverable MFA.
 */
class OpsPanelProvider extends PanelProvider
{
    /**
     * Configures operational resources with isolated staff sessions and mandatory app authentication.
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ops')
            ->path('ops')
            ->login()
            ->authGuard('staff')
            ->authPasswordBroker('staff')
            ->passwordReset()
            ->profile()
            ->multiFactorAuthentication([
                StaffAppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->brandName('Ajo')
            ->brandLogo(asset('ajo-logo.svg'))
            ->darkModeBrandLogo(asset('ajo-logo-dark.svg'))
            ->font('Instrument Sans')
            ->colors([
                'primary' => Color::hex('#D7F75B'),
            ])
            ->discoverResources(in: app_path('Filament/Ops/Resources'), for: 'App\Filament\Ops\Resources')
            ->discoverPages(in: app_path('Filament/Ops/Pages'), for: 'App\Filament\Ops\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Ops/Widgets'), for: 'App\Filament\Ops\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
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
            ]);
    }
}
