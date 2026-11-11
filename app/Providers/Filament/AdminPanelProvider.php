<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Support\PersonnelNavigation;
use App\Http\Middleware\ApplyUserLocale;
use App\Http\Middleware\AuthorizePanelPage;
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
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
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
            ->strictAuthorization()
            ->path('')
            ->login(Login::class)
            ->brandName('')
            ->globalSearch(false)
            ->topbar(false)
            ->favicon(asset('renome-favicon.svg'))
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            ->collapsedSidebarWidth('4rem')
            ->maxContentWidth(Width::Full)
            ->homeUrl(fn () => auth()->user()?->isLabTechnician() ? LabCaseResource::getUrl() : Dashboard::getUrl())
            ->profile()
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->breadcrumbs(false)
            ->navigationGroups([
                NavigationGroup::make('კლინიკა')->icon(Heroicon::OutlinedBuildingOffice2)->collapsible(),
                NavigationGroup::make('ლაბორატორია')->icon(Heroicon::OutlinedBeaker)->collapsible(),
                NavigationGroup::make('HR / პერსონალი')->icon(Heroicon::OutlinedUserGroup)->collapsible(),
                NavigationGroup::make('ფინანსები')->icon(Heroicon::OutlinedBanknotes)->collapsible(),
                NavigationGroup::make('ანალიტიკა')->icon(Heroicon::OutlinedChartPie)->collapsible(),
                NavigationGroup::make('ადმინისტრირება')->icon(Heroicon::OutlinedCog6Tooth)->collapsible(),
            ])
            ->renderHook(PanelsRenderHook::PAGE_START,
                fn (array $scopes) => view('filament.navigation.personnel', compact('scopes')),
                scopes: PersonnelNavigation::RESOURCES)
            ->renderHook(PanelsRenderHook::PAGE_START,
                fn () => view('filament.resources.purchases.navigation'),
                scopes: [PurchaseResource::class])
            ->renderHook(PanelsRenderHook::SIDEBAR_START,
                fn () => view('filament.navigation.hover-sidebar'))
            ->renderHook(PanelsRenderHook::HEAD_END,
                fn () => view('filament.security-meta'))
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
            ])
            ->middleware([ApplyUserLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
                RestrictLabTechnicianAccess::class,
                AuthorizePanelPage::class,
            ], isPersistent: true);
    }
}
