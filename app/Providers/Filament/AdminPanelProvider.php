<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\CasinoOverview;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Tables with a lot of filters/columns (Users, Game Rounds, …) would
        // otherwise render a filters/column-manager dropdown with no height
        // cap of its own — it just grows past the viewport with nothing to
        // scroll, so a wheel scroll over it falls through to whatever's
        // underneath (the table). Capping both gives them their own
        // scrollbar, same as Filament's other panels/modals.
        Table::configureUsing(function (Table $table) {
            $table->filtersFormMaxHeight('60vh');
            $table->columnManagerMaxHeight('60vh');

            // Below `sm` there's no room for a real grid — Filament's stacked
            // mode turns each row into a card (label above value) with
            // actions wrapping full-width at the bottom, instead of a
            // horizontally-scrolled table whose sticky actions column (see
            // theme.css) would otherwise cover the data columns entirely.
            $table->stackedOnMobile();
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Casino Control')
            ->favicon(asset('favicon.ico'))
            ->defaultThemeMode(ThemeMode::Dark)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => [
                    50 => '#fbf7ec', 100 => '#f5ead0', 200 => '#ecd5a0',
                    300 => '#e2bf70', 400 => '#d9ac4a', 500 => '#d4af37',
                    600 => '#b4902b', 700 => '#8f6f23', 800 => '#6b5220',
                    900 => '#4d3b1c', 950 => '#2a2010',
                ],
                'success' => Color::Emerald,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'info' => Color::Sky,
                'gray' => Color::Zinc,
            ])
            ->navigationGroups([
                NavigationGroup::make('Operations'),
                NavigationGroup::make('Games'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Access'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                CasinoOverview::class,
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.shop-switcher-topbar'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                // Filament's sidebar open/collapsed state is Alpine.$persist-ed
                // to localStorage, defaulting to *open* the first time a browser
                // ever visits. Seeding it closed here — before Alpine reads it
                // on `alpine:init` — makes "collapsed" the default for a fresh
                // browser, while leaving it alone (the `=== null` check) once
                // someone has actually toggled it, so their choice still sticks.
                fn () => new HtmlString(<<<'HTML'
                    <script>
                        try {
                            if (localStorage.getItem('isOpenDesktop') === null) {
                                localStorage.setItem('isOpenDesktop', 'false');
                            }
                            if (localStorage.getItem('isOpen') === null) {
                                localStorage.setItem('isOpen', 'false');
                            }
                        } catch (e) {}
                    </script>
                    HTML),
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
            ]);
    }
}
