<?php

namespace App\Providers\Filament;

use App\Http\Middleware\VerifyTwoFactor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use App\Filament\Widgets\AiActivityOverview;
use App\Filament\Widgets\EditorialOverview;
use App\Filament\Widgets\MostFlaggedArticles;
use App\Filament\Widgets\RecentArticles;
use App\Filament\Widgets\TopCountriesWidget;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Support\HtmlString;
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
            // Brand chrome on the admin panel reflects the customer's
            // own site_name (admin-editable) rather than the product
            // name. The admin still sees "Powered by CapstoneNMS" on
            // the public footer per the license terms; the admin
            // chrome is theirs to white-label.
            ->brandName(fn () => (string) (function_exists('getcong') ? (getcong('site_name') ?: config('app.name')) : config('app.name')))
            ->brandLogo(function () {
                $logo = function_exists('getcong') ? (string) getcong('site_logo') : '';
                if ($logo !== '') {
                    return new HtmlString('<img src="'.e(image_src($logo)).'" alt="" style="height:2rem;">');
                }
                $name = (string) (function_exists('getcong') ? (getcong('site_name') ?: config('app.name')) : config('app.name'));
                $initial = mb_strtoupper(mb_substr($name, 0, 1));
                return new HtmlString(
                    '<span style="display:inline-flex;align-items:center;gap:0.55rem;font-weight:800;letter-spacing:-0.02em;font-size:1.25rem;">'
                    .'<span style="display:inline-flex;width:1.6rem;height:1.6rem;border-radius:0.4rem;background:#0ea5e9;color:#fff;align-items:center;justify-content:center;font-size:0.95rem;">'.e($initial).'</span>'
                    .'<span>'.e($name).'</span>'
                    .'</span>'
                );
            })
            ->favicon(asset('site/img/favicon.svg'))
            ->login()
            ->passwordReset()
            ->profile(isSimple: false)
            ->colors([
                'primary' => Color::Sky,
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->darkMode(true)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                EditorialOverview::class,
                AiActivityOverview::class,
                TopCountriesWidget::class,
                MostFlaggedArticles::class,
                RecentArticles::class,
                AccountWidget::class,
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
                VerifyTwoFactor::class,
                \App\Http\Middleware\EnforceLicense::class,
            ])
            // Top-of-panel dev banner. Same trigger conditions as the
            // public-side partial (license missing OR kind != production)
            // so admins of unlicensed / dev installs can't miss it.
            ->renderHook(
                \Filament\View\PanelsRenderHook::BODY_START,
                fn (): string => view('partials.site.dev-banner')->render(),
            );
    }
}
