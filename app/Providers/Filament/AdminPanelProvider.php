<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ApplyUserLocale;
use App\Http\Middleware\RestrictLabTechnicianAccess;
use Filament\Enums\UserMenuPosition;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
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
        DatePicker::configureUsing(fn (DatePicker $component): DatePicker => $component->native(false));
        DateTimePicker::configureUsing(fn (DateTimePicker $component): DateTimePicker => $component->native(false));

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->breadcrumbs(false)
            ->navigationGroups([
                NavigationGroup::make('კლინიკა')->icon(Heroicon::OutlinedBuildingOffice2)->collapsible(),
                NavigationGroup::make('ისრაელი')->icon(Heroicon::OutlinedGlobeAlt)->collapsible(),
                NavigationGroup::make(fn (): string => __('lab.navigation.group'))->icon(Heroicon::OutlinedBeaker)->collapsible(),
                NavigationGroup::make('ადმინისტრირება')->icon(Heroicon::OutlinedShieldCheck)->collapsible(),
                NavigationGroup::make('ფინანსები')->icon(Heroicon::OutlinedBanknotes)->collapsible(),
            ])
            ->colors([
                'primary' => Color::hex('#0F9F8F'),
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
                ApplyUserLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RestrictLabTechnicianAccess::class,
            ]);
    }
}
