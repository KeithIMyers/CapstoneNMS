<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pulls the {locale} route parameter (when present) and applies it as the
 * active app locale for the rest of the request. Routes without the
 * parameter fall back to the configured default.
 */
class SetLocaleFromRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $default   = config('locales.default', 'en');
        $supported = array_keys(config('locales.supported', [$default => 'English']));

        $locale = $request->route('locale');

        if ($locale && in_array($locale, $supported, true)) {
            app()->setLocale($locale);
            app()->instance('active_locale', $locale);
        } else {
            app()->setLocale($default);
            app()->instance('active_locale', $default);
        }

        return $next($request);
    }
}
