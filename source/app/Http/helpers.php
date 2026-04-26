<?php

use App\Models\Favorite;
use App\Models\News;
use App\Models\Settings;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

if (!function_exists('most_used_tags')) {
    /**
     * Top N most-used tags across published articles. Prefers the new
     * news_tag pivot when available; falls back to the legacy CSV column
     * for any articles that haven't been migrated through news:split-tags
     * yet. Result keyed by tag name → count.
     */
    function most_used_tags(int $limit = 10): \Illuminate\Support\Collection
    {
        // Pivot-driven counts.
        $pivot = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('tags')) {
            $pivot = Tag::query()
                ->select('tags.name', DB::raw('COUNT(news_tag.news_id) as count'))
                ->join('news_tag', 'news_tag.tag_id', '=', 'tags.id')
                ->join('news', 'news.id', '=', 'news_tag.news_id')
                ->where('news.editorial_status', News::STATUS_PUBLISHED)
                ->groupBy('tags.name')
                ->pluck('count', 'name');
        }

        // Fallback for any rows still relying on the CSV column.
        $csv = News::query()
            ->where('editorial_status', News::STATUS_PUBLISHED)
            ->whereNotNull('tags')
            ->where('tags', '!=', '')
            ->pluck('tags')
            ->flatMap(fn ($t) => array_map('trim', explode(',', $t)))
            ->filter()
            ->countBy();

        return $pivot->merge($csv->diffKeys($pivot))->sortDesc()->take($limit);
    }
}

if (!function_exists('check_favorite')) {
    function check_favorite($post_id, $user_id = null): bool
    {
        if (! $user_id) {
            return false;
        }
        return Favorite::where('post_id', $post_id)
            ->where('user_id', $user_id)
            ->exists();
    }
}

if (!function_exists('isActiveRoute')) {
    /**
     * Return $class when the current route matches $routeName (and any
     * passed parameters), otherwise an empty string. Used in nav menus.
     */
    function isActiveRoute(string $routeName, array $parameters = [], string $class = 'active'): string
    {
        $routeIs = request()->routeIs($routeName) || request()->routeIs("{$routeName}.*");
        if (! $routeIs) {
            return '';
        }

        foreach ($parameters as $key => $value) {
            if (request()->route($key) != $value) {
                return '';
            }
        }
        return $class;
    }
}

if (!function_exists('putPermanentEnv')) {
    /**
     * Persist a key=value pair into the .env file. Used by the admin to
     * store OAuth client secrets and similar without requiring shell
     * access. Values are quoted and addslashes-escaped so embedded
     * newlines or quotes don't break the file.
     */
    function putPermanentEnv(string $key, ?string $value): void
    {
        $path = app()->environmentFilePath();
        $escapedValue = '"'.addslashes((string) $value).'"';

        $envContents = file_get_contents($path);

        if (preg_match("/^{$key}=/m", $envContents)) {
            $envContents = preg_replace(
                "/^{$key}=.*/m",
                "{$key}={$escapedValue}",
                $envContents
            );
            file_put_contents($path, $envContents);
        } else {
            file_put_contents($path, PHP_EOL."{$key}={$escapedValue}".PHP_EOL, FILE_APPEND);
        }
    }
}

if (!function_exists('getcong')) {
    /**
     * Read a CMS setting from the `settings` key/value table. Cached for
     * the duration of the request so a single page render with multiple
     * lookups doesn't re-query.
     */
    function getcong(string $key): ?string
    {
        static $cache = null;
        if ($cache === null) {
            try {
                $cache = Settings::query()->pluck('value', 'key')->toArray();
            } catch (\Throwable $e) {
                $cache = [];
            }
        }
        return $cache[$key] ?? null;
    }
}

if (!function_exists('number_format_short')) {
    /**
     * Compact display of large integers — 1.2K / 4.5M / 3.1B.
     */
    function number_format_short(int|float $n, int $precision = 1): string
    {
        if ($n < 900) {
            $n_format = number_format($n, $precision);
            $suffix = '';
        } elseif ($n < 900_000) {
            $n_format = number_format($n / 1_000, $precision);
            $suffix = 'K';
        } elseif ($n < 900_000_000) {
            $n_format = number_format($n / 1_000_000, $precision);
            $suffix = 'M';
        } elseif ($n < 900_000_000_000) {
            $n_format = number_format($n / 1_000_000_000, $precision);
            $suffix = 'B';
        } else {
            $n_format = number_format($n / 1_000_000_000_000, $precision);
            $suffix = 'T';
        }

        // Trim "1.0" → "1" but leave "1.50" alone.
        if ($precision > 0) {
            $dotzero = '.'.str_repeat('0', $precision);
            $n_format = str_replace($dotzero, '', $n_format);
        }

        return $n_format.$suffix;
    }
}

if (!function_exists('generate_timezone_list')) {
    /**
     * Build a UTC-offset-prefixed timezone list for select inputs.
     */
    function generate_timezone_list(): array
    {
        static $regions = [
            DateTimeZone::AFRICA, DateTimeZone::AMERICA, DateTimeZone::ANTARCTICA,
            DateTimeZone::ASIA, DateTimeZone::ATLANTIC, DateTimeZone::AUSTRALIA,
            DateTimeZone::EUROPE, DateTimeZone::INDIAN, DateTimeZone::PACIFIC,
        ];

        $timezones = [];
        foreach ($regions as $region) {
            $timezones = array_merge($timezones, DateTimeZone::listIdentifiers($region));
        }

        $offsets = [];
        $now = new DateTime;
        foreach ($timezones as $tzName) {
            $offsets[$tzName] = (new DateTimeZone($tzName))->getOffset($now);
        }
        ksort($offsets);

        $list = [];
        foreach ($offsets as $tzName => $offset) {
            $sign = $offset < 0 ? '-' : '+';
            $hm = gmdate('H:i', abs($offset));
            $list[$tzName] = "(UTC{$sign}{$hm}) {$tzName}";
        }
        return $list;
    }
}

if (!function_exists('image_src')) {
    /**
     * Resolve a stored image path to a URL the browser can fetch.
     *
     * Handles three cases:
     *   - empty / null  → returns null (caller should @if-guard the result)
     *   - absolute URL  → returns as-is
     *   - leading slash → treats as a site-root-relative path (e.g. seed
     *                     images bundled under /public)
     *   - everything else → resolved through the configured storage disk
     *                       (so admin uploads under storage/app/public
     *                       work via the public/storage symlink)
     */
    function image_src(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            // Same-origin URLs (admin pasted the full APP_URL by
            // mistake) pass through. External hosts get rewritten
            // through the proxy so the upstream never sees reader
            // IPs and a http:// URL doesn't trigger a mixed-content
            // block on an https:// page.
            $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
            $imgHost = strtolower((string) parse_url($path, PHP_URL_HOST));
            if ($imgHost === '' || $imgHost === $appHost) {
                return $path;
            }
            try {
                $token = rtrim(strtr(base64_encode(
                    \Illuminate\Support\Facades\Crypt::encryptString($path)
                ), '+/', '-_'), '=');
                return route('image.proxy', ['t' => $token]);
            } catch (\Throwable $e) {
                // Crypt failure (no APP_KEY?) — drop back to the raw
                // URL rather than breaking the article render.
                return $path;
            }
        }
        if (str_starts_with($path, '/')) {
            return url($path);
        }
        try {
            // Storage::url() prefixes with /storage/ already; we strip
            // any leading slash from the supplied path so a value like
            // "/settings/foo.png" doesn't combine with a trailing-slash
            // APP_URL into a "//storage//settings/..." URL.
            return \Illuminate\Support\Facades\Storage::disk(getcong('site_storage') ?: 'public')
                ->url(ltrim($path, '/'));
        } catch (\Throwable $e) {
            return url('/storage/'.ltrim($path, '/'));
        }
    }
}

if (!function_exists('cdn_image_src')) {
    /**
     * Like image_src() but routes through the configured ImageCdn
     * provider so the URL benefits from on-the-fly resizing /
     * format negotiation. Optional $opts: width, height, format,
     * quality, fit. Drops back to the plain URL when no provider
     * is configured.
     *
     * Use this directly only when a `<picture>` element would be
     * overkill (e.g. inline icons). For hero / card images, prefer
     * the partials.site.responsive-picture partial.
     */
    function cdn_image_src(?string $path, array $opts = []): ?string
    {
        $resolved = image_src($path);
        if (! $resolved) return null;
        return app(\App\Services\Images\ImageCdn::class)->url($resolved, $opts);
    }
}

if (!function_exists('preferred_locale')) {
    /**
     * Pick the visitor's most-preferred locale from the request's
     * Accept-Language header that is also configured in the supported
     * list. A user's explicit cookie pref ("usnt_locale") wins over
     * the browser header so manual switches stick.
     *
     * Returns null when the visitor's preferred language already
     * matches the active locale or no supported locale matched.
     */
    function preferred_locale(): ?string
    {
        $supported = array_keys(config('locales.supported', []));
        $active    = app()->getLocale();

        // Explicit cookie pref wins.
        $cookie = request()->cookie('usnt_locale');
        if ($cookie && in_array($cookie, $supported, true)) {
            return $cookie === $active ? null : $cookie;
        }

        $header = (string) request()->header('Accept-Language', '');
        if ($header === '') return null;

        // Parse "en-US,en;q=0.9,fr;q=0.7" into a sorted preference list.
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^q=([0-9.]+)$/', trim($param), $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($tag === '') continue;
            $candidates[] = ['tag' => $tag, 'q' => $q];
        }
        usort($candidates, fn ($a, $b) => $b['q'] <=> $a['q']);

        foreach ($candidates as $c) {
            $tag = $c['tag'];
            foreach ($supported as $sup) {
                if (strtolower($sup) === $tag && $sup !== $active) {
                    return $sup;
                }
            }
            $primary = explode('-', $tag)[0];
            foreach ($supported as $sup) {
                if (strtolower(explode('-', $sup)[0]) === $primary && $sup !== $active) {
                    return $sup;
                }
            }
        }

        return null;
    }
}

if (!function_exists('logo_src')) {
    /**
     * Resolve the site logo URL — but return null when the file is
     * missing on disk, so Blade can fall back to brand text instead of
     * rendering a broken IMG. Unlike image_src(), this checks existence
     * before returning a URL.
     */
    function logo_src(?string $path): ?string
    {
        if ($path === null || $path === '') return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk(getcong('site_storage') ?: 'public');
            $rel  = ltrim($path, '/');
            if (! $disk->exists($rel)) {
                return null;
            }
            return $disk->url($rel);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('verify_recaptcha')) {
    /**
     * Verify a Google reCAPTCHA v2 response. Pulls the client IP from the
     * Laravel Request rather than $_SERVER so the trusted-proxy
     * configuration is honored. Returns false on any error so callers
     * can fail closed.
     */
    function verify_recaptcha(\Illuminate\Http\Request $request): bool
    {
        $secret = getcong('recaptcha_secret_key');
        if (empty($secret)) {
            return false;
        }

        try {
            $response = \Illuminate\Support\Facades\Http::asForm()
                ->timeout(10)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => (string) $request->input('g-recaptcha-response'),
                    'remoteip' => (string) $request->ip(),
                ]);

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('csp_nonce')) {
    /**
     * Returns a fixed placeholder that the SecurityHeaders middleware
     * substitutes for a freshly-generated per-RESPONSE nonce. Using a
     * placeholder (rather than a random value baked into the rendered
     * HTML) lets the full-page response cache store the body once and
     * still have nonces line up with the CSP header on every cache hit.
     */
    function csp_nonce(): string
    {
        return \App\Http\Middleware\SecurityHeaders::NONCE_PLACEHOLDER;
    }
}

if (!function_exists('nonce_inject_html')) {
    /**
     * Decorate every <script> and <style> tag in the given HTML with the
     * current CSP nonce. Used to make admin-pasted analytics / header /
     * footer HTML survive the strict CSP without us having to make
     * admins paste a templated `nonce="{{ csp_nonce() }}"` attribute.
     * Admin trust is assumed — this only adds a nonce, never strips
     * anything (sanitize_rich_html / sanitize_embed_html still run on
     * editor-supplied HTML).
     */
    function nonce_inject_html(?string $html): string
    {
        if ($html === null || $html === '') return '';
        $nonce = csp_nonce();
        return preg_replace_callback(
            '/<(script|style)\b([^>]*)>/i',
            function ($m) use ($nonce) {
                $tag   = $m[1];
                $attrs = $m[2];
                if (preg_match('/\bnonce\s*=/i', $attrs)) return $m[0];
                return "<{$tag}{$attrs} nonce=\"{$nonce}\">";
            },
            $html
        ) ?? $html;
    }
}

if (!function_exists('sanitize_rich_html')) {
    /**
     * Minimal XSS sanitizer for rich-text HTML submitted by content editors
     * (sub_admins). Strips <script>/<style>/<iframe>/<object>/<embed>/<svg>
     * and friends, neutralizes "javascript:"/"data:" URIs (including entity-
     * encoded and whitespace-bypass variants), and removes inline event
     * handlers (onclick= etc).
     *
     * Defense-in-depth, not a full HTML allowlist — for stricter output
     * install mews/purifier on production and replace callers with
     * Purifier::clean().
     */
    function sanitize_rich_html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Decode HTML entities so scheme/event-handler regexes see the
        // canonical form. Without this, payloads like java&#x09;script:
        // and on&#x6C;oad= slip past the literal regexes below.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Drop the dangerous element families entirely — both block
        // (with content) and self-closing forms. svg/math are XSS
        // vectors via animate/onload/use[xlink:href]; details/marquee
        // are rarely needed in editorial content and add surface area.
        $danger = 'script|style|iframe|object|embed|link|meta|base|form|svg|math|template|portal';
        $html = preg_replace("#<($danger)\\b[^>]*>.*?</\\1>#is", '', $html);
        $html = preg_replace("#<($danger)\\b[^>]*/?>#i",        '', $html);

        // Strip every inline event handler: on{anything}= … in ", ', or bare form.
        $html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html);
        $html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $html);
        $html = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html);

        // Block dangerous URI schemes on every linkable attribute.
        // The pattern allows whitespace/control chars between the scheme
        // letters and the colon (browsers tolerate them).
        $linkAttrs = 'href|src|xlink:href|formaction|action|background|poster|srcset';
        $html = preg_replace(
            '#('.$linkAttrs.')\s*=\s*(["\']?)\s*(?:j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t|v\s*b\s*s\s*c\s*r\s*i\s*p\s*t|d\s*a\s*t\s*a)\s*:[^"\'>]*\2#i',
            '$1=$2#$2',
            $html
        );

        return $html;
    }
}

if (!function_exists('sanitize_embed_html')) {
    /**
     * Sanitizer for video-embed fields. Strips everything except a minimal
     * iframe allowlist (YouTube, Vimeo, Twitch, Dailymotion, Facebook).
     */
    function sanitize_embed_html(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $allowedHosts = [
            'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com',
            'player.vimeo.com', 'vimeo.com',
            'player.twitch.tv', 'clips.twitch.tv',
            'www.dailymotion.com', 'geo.dailymotion.com',
            'www.facebook.com', 'web.facebook.com',
        ];

        $out = '';
        if (preg_match_all('#<iframe\b[^>]*src\s*=\s*["\']([^"\']+)["\'][^>]*></iframe>#i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $src = $m[1];
                $host = parse_url($src, PHP_URL_HOST);
                if ($host !== null && in_array(strtolower($host), $allowedHosts, true)) {
                    $safeSrc = htmlspecialchars($src, ENT_QUOTES, 'UTF-8');
                    $out .= '<iframe src="'.$safeSrc.'" width="640" height="360" frameborder="0" allowfullscreen></iframe>';
                }
            }
        }

        return $out;
    }
}
