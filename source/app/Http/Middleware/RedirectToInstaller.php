<?php

namespace App\Http\Middleware;

use App\Services\Install\InstallerService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces every browser request to /install when the install lock
 * file is missing — i.e. when the customer has just unzipped the
 * dist and pointed their web server at public/.
 *
 * Stays out of the way for:
 *   - the installer itself (/install*)
 *   - asset URLs that need to keep working during install
 *     (favicon, /storage, vite-built assets in /build, /js, /css)
 *   - the health endpoint (/up) so monitoring still works
 *
 * Once InstallerService reports `installed`, this middleware is a
 * pass-through. The install lock removal is a documented "rerun
 * the installer" path; we don't try to detect a half-installed
 * state automatically because that can be a destructive false
 * positive on a freshly-restored backup.
 */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(InstallerService::class)->isInstalled()) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        // Only redirect HTML requests; for JSON / API hits we 503 so
        // a probing bot doesn't think the app is healthy yet.
        if ($request->expectsJson()) {
            return response()->json(['error' => 'CapstoneNMS not installed yet'], 503);
        }

        return redirect('/install');
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        if ($path === 'up') return true;
        if ($path === 'favicon.ico' || $path === 'favicon.gif') return true;
        if (str_starts_with($path, 'install')) return true;
        if (str_starts_with($path, 'build/')) return true;
        if (str_starts_with($path, 'js/')) return true;
        if (str_starts_with($path, 'css/')) return true;
        if (str_starts_with($path, 'fonts/')) return true;
        if (str_starts_with($path, 'site/')) return true;
        if (str_starts_with($path, 'storage/')) return true;
        return false;
    }
}
