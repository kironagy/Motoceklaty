<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Navigation\MenuItem;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;
use Filament\Support\Facades\FilamentView;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
   
            ->default()
            ->id('admin')
            ->path('admin')
            // ?v= changes with every build, so browsers never keep an old theme
            ->theme(asset('css/filament/admin/theme.css').'?v='.@filemtime(public_path('css/filament/admin/theme.css')))
            ->login()
            ->authGuard('filament')
            // On a merchant's subdomain the panel wears its showroom's name and logo
            ->brandName(fn () => \App\Support\Merchants\MerchantTenancy::current()?->showroom_name ?? config('app.name'))
            ->brandLogo(fn () => \App\Support\Merchants\MerchantTenancy::current()
                ? \App\Support\Merchants\MerchantTenancy::current()->logoUrl()
                : asset('images/logolog.png'))
            ->brandLogoHeight('3.5rem')
            ->colors([
                'primary' => Color::Teal,
                'warning' => Color::Amber,
            ])
            // Sidebar order: daily work first, settings last.
            ->navigationGroups([
                'الطلبات والعملاء',
                'إدارة المعرض',
                'إدارة الموظفين',
                'الموتوسيكلات والفروع',
                'إعدادات الطلبات',
                'البوت الذكي',
                'الموقع الإلكتروني',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
              \App\Filament\Widgets\StaffRequestsOverview::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])

        ->renderHook(
            'panels::topbar.start',
            fn () => view('filament.components.notifications-bell')
        )
        // Owner 2026-11-22: the stats cards' look (plain CSS, after the theme)
        ->renderHook(
            'panels::head.end',
            fn () => collect(['stats-cards', 'staff-ranking', 'sidebar', 'deliveries-table', 'deliveries-form'])->map(fn ($f) => '<link rel="stylesheet" href="'.asset("css/filament/admin/{$f}.css").'?v='.@filemtime(public_path("css/filament/admin/{$f}.css")).'">')->implode('')
        )
        // A showroom's own logo keeps its colours (the main logo is whitened in the sidebar)
        ->renderHook(
            'panels::head.end',
            fn () => \App\Support\Merchants\MerchantTenancy::current()
                ? '<style>.fi-sidebar-header .fi-logo{filter:drop-shadow(0 6px 16px rgba(45,212,191,.35))}</style>'
                : ''
        )
        // Owner 2026-11-23: a request's edit page opens with its summary card and the sections rail
        ->renderHook(
            'panels::page.header-widgets.before',
            function () {
                $record = \Livewire\Livewire::current()?->record ?? null;

                return $record instanceof \App\Models\InstallmentRequest
                    ? view('filament.deliveries.request-hero', ['record' => $record])
                    : '';
            },
            \App\Filament\Resources\DeliveryResource\Pages\EditDelivery::class,
        )
        ->renderHook(
            'panels::body.end',
            fn () => view('filament.components.form-submit-guard')
        )
        // Owner 2026-11-22: only one request is lit (mg-tk-on): the last one tapped, or under the mouse.
        // A tap (click, so scrolling does not count) or a mouse pointing at another moves the light;
        // the touch listener also lets iPhone Safari show :active
        ->renderHook(
            'panels::body.end',
            fn () => '<script>(function () { document.addEventListener("touchstart", function () {}, { passive: true });'
                .' function lightUp(e) { var r = e.target.closest && e.target.closest(".fi-resource-deliveries .fi-ta-row"); if (! r || r.classList.contains("mg-tk-on")) return;'
                .' document.querySelectorAll(".fi-ta-row.mg-tk-on").forEach(function (x) { x.classList.remove("mg-tk-on"); }); r.classList.add("mg-tk-on"); }'
                .' document.addEventListener("click", lightUp, { passive: true });'
                .' if (window.matchMedia("(hover: hover) and (pointer: fine)").matches) document.addEventListener("mouseover", lightUp, { passive: true }); })();</script>'
        )
                    ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
