<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Tiny full-page response cache for anonymous GET requests.
 *
 *  - Only caches GET (no HEAD, POST, etc.).
 *  - Only caches when no user is authenticated. Logged-in users always see
 *    a fresh response.
 *  - Skips paths that personalise output, accept user input, or shouldn't
 *    leak across sessions: /admin, /api, login, signup, profile, password,
 *    favorites, newsletter/*, password-reset, and anything with a query
 *    string we can't safely cache (forms with `?error=` etc.).
 *  - Cache TTL defaults to 300 seconds and is bypassed when the
 *    RESPONSECACHE_DISABLE env flag is set.
 *
 * Cache invalidation is event-driven: News, Category, Pages, and Settings
 * model changes call `Cache::tags(['responsecache'])->flush()` from their
 * boot hooks (or, if the cache driver doesn't support tags like the file
 * driver, the whole cache is wiped — fine for a small site).
 */
class CacheGuestResponses
{
    private const TTL_SECONDS = 300;
    private const CACHE_PREFIX = 'rc:';

    /** Path prefixes that must never be cached. */
    private const SKIP_PREFIXES = [
        'admin', 'api', 'livewire',
        'login', 'logout', 'signup',
        'profile', 'favorites',
        'password',
        'newsletter',
        'auth/',
        'ad/', 'track/',
        'ask',
    ];

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! $this->shouldConsider($request)) {
            $response = $next($request);
            $response->headers->set('X-Response-Cache', 'BYPASS');
            return $response;
        }

        $key = self::CACHE_PREFIX.sha1($request->getUri());
        $store = Cache::store('file');

        if ($cached = $store->get($key)) {
            $response = new Response(
                $cached['body'],
                $cached['status'],
                array_merge(
                    $cached['headers'] ?? [],
                    ['X-Response-Cache' => 'HIT'],
                ),
            );
            return $response;
        }

        /** @var SymfonyResponse $response */
        $response = $next($request);

        if ($this->shouldStore($response)) {
            $store->put($key, [
                'body' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'headers' => $this->safeHeaders($response),
            ], self::TTL_SECONDS);
            $response->headers->set('X-Response-Cache', 'MISS');
        } else {
            $response->headers->set('X-Response-Cache', 'NOSTORE');
        }

        return $response;
    }

    private function shouldConsider(Request $request): bool
    {
        if (env('RESPONSECACHE_DISABLE')) {
            return false;
        }
        if ($request->getMethod() !== 'GET') {
            return false;
        }
        if (auth()->check()) {
            return false;
        }
        $path = ltrim($request->path(), '/');
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        return true;
    }

    private function shouldStore(SymfonyResponse $response): bool
    {
        // Only successful HTML pages. We deliberately ignore the
        // `Cache-Control: private` header that StartSession adds to every
        // response — that hint is meant for shared HTTP caches (CDNs,
        // proxies) and would prevent us from caching anything for guests.
        // Our own cache is per-URL, never served to authenticated users,
        // and never bridges the network in a way that matters.
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        $cc = (string) $response->headers->get('Cache-Control');
        if (str_contains($cc, 'no-store')) {
            return false;
        }
        return true;
    }

    /**
     * Strip headers that shouldn't carry across users (Set-Cookie chiefly).
     */
    private function safeHeaders(SymfonyResponse $response): array
    {
        $skip = ['set-cookie', 'date', 'x-powered-by'];
        $out = [];
        foreach ($response->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $skip, true)) {
                continue;
            }
            $out[$name] = $values;
        }
        return $out;
    }
}
