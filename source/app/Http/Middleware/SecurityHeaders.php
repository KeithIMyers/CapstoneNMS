<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds defensive HTTP headers to every response. The Content-Security-
 * Policy is built dynamically from site settings so admins enabling /
 * disabling an analytics provider, paywall, or captcha automatically
 * tightens the allowlist without an env edit.
 *
 *  - Public site: strict CSP — `default-src 'self'`, every inline
 *    `<script>` / `<style>` requires the per-request nonce, third-party
 *    hosts are explicitly allowlisted per directive.
 *  - Admin panel (Filament), Livewire, Stripe webhook, push/track JSON
 *    endpoints: minimal CSP — Filament owns its own asset pipeline and
 *    the JSON endpoints have no scripts at all, so the strict policy
 *    would only false-positive there.
 *
 * The nonce comes from the `csp_nonce()` helper which lazily binds a
 * single value into the container per request; the Blade layout reads
 * the same value via `nonce="{{ csp_nonce() }}"`.
 */
class SecurityHeaders
{
    /**
     * Sentinel that `csp_nonce()` returns during Blade render. After
     * the response is built (and possibly served from the page cache),
     * this middleware swaps every occurrence in the body for a freshly-
     * generated per-response nonce and sets the CSP header to match.
     * That way one cached HTML body works for every visitor without
     * the cached nonce drifting from the live CSP header.
     */
    public const NONCE_PLACEHOLDER = '__CSP_NONCE_27a5c3b1__';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($this->skipStrictCsp($request)) {
            $h->set('Content-Security-Policy', $this->buildAdminCsp($request));
            // Strip the placeholder if it somehow leaked into a non-strict
            // response — better to lose a `nonce=""` attribute than to
            // show the literal sentinel to the user.
            $this->stripPlaceholder($response);
            return $response;
        }

        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $this->substituteNonce($response, $nonce);
        $h->set('Content-Security-Policy', $this->buildCsp($nonce));

        return $response;
    }

    /**
     * Replace every occurrence of the placeholder in the response body
     * with the provided per-response nonce. We only touch text-y bodies
     * that actually contain the placeholder, so JSON / binary / file
     * downloads pass through untouched.
     */
    private function substituteNonce(Response $response, string $nonce): void
    {
        $body = $response->getContent();
        if (! is_string($body) || $body === '') return;
        if (! str_contains($body, self::NONCE_PLACEHOLDER)) return;
        $response->setContent(str_replace(self::NONCE_PLACEHOLDER, $nonce, $body));
    }

    private function stripPlaceholder(Response $response): void
    {
        $body = $response->getContent();
        if (! is_string($body) || $body === '') return;
        if (! str_contains($body, self::NONCE_PLACEHOLDER)) return;
        $response->setContent(str_replace(self::NONCE_PLACEHOLDER, '', $body));
    }

    /**
     * Routes that get the relaxed CSP. Filament v3 manages its own asset
     * lifecycle (Vite, Livewire, Alpine bundles) and would need a chunk
     * of work to thread our nonce through; until that's done, the panel
     * keeps the lighter base-uri / frame-ancestors set. Stripe webhook
     * and the JSON tracker endpoints emit no markup, so a strict CSP
     * does nothing useful for them.
     */
    private function skipStrictCsp(Request $request): bool
    {
        return $request->is('admin')
            || $request->is('admin/*')
            || $request->is('admin-ai/*')
            || $request->is('livewire/*')
            || $request->is('stripe/*')
            || $request->is('filament/*');
    }

    /**
     * Tightened CSP for the admin / Livewire / Stripe / track surfaces
     * that opt out of the strict public policy.
     *
     * Filament 4.x ships:
     *   • Inline `<script>` (4 small bootstraps: dark-mode, collapsed
     *     groups, window.filamentData, loadDarkMode()) without nonces
     *   • Alpine.js + Livewire (require 'unsafe-eval' to compile x-*
     *     directive expressions at runtime)
     *
     * So script-src has to keep `'unsafe-inline' 'unsafe-eval'` until
     * Filament threads our nonce through its asset pipeline. That
     * means we can't outright block an inline-script XSS payload.
     * What we CAN block — and what this CSP does — is everything an
     * exfiltrating XSS payload depends on:
     *   • `connect-src 'self'`   — fetch / XHR / WebSocket can only
     *                              talk to our own origin, so a
     *                              compromised script can't POST
     *                              cookies / drafts to attacker.com
     *   • `default-src 'self'`   — `<script src="https://evil.com">`
     *                              and `<iframe src="https://evil">`
     *                              are blocked
     *   • `form-action 'self'`   — credential-form-submit exfil
     *                              is blocked
     *   • `object-src 'none'`    — Flash / plugin XSS is gone
     *   • `frame-ancestors 'self'` — clickjacking is gone
     *   • `base-uri 'self'`      — `<base href>` redirect attacks
     *                              are blocked
     *
     * Net: an inline-script XSS in admin still RUNS but can't
     * silently leak the editor's session, drafts, or 2FA secret to
     * an outside server.
     */
    private function buildAdminCsp(Request $request): string
    {
        $self = "'self'";
        $script  = [$self, "'unsafe-inline'", "'unsafe-eval'"];
        $style   = [$self, "'unsafe-inline'", 'https://fonts.bunny.net'];
        $font    = [$self, 'data:', 'https://fonts.bunny.net'];
        $img     = [$self, 'data:', 'blob:', 'https:'];
        $connect = [$self];
        $frame   = [$self];
        $media   = [$self, 'data:', 'blob:'];
        $worker  = [$self, 'blob:'];

        // Stripe lives under /stripe/* and Filament's billing UI may
        // reach into Stripe iframes / api.stripe.com. Allow the same
        // origins we open up on the public site.
        if (config('paywall.default_price_id') || env('STRIPE_KEY')) {
            $script[]  = 'https://js.stripe.com';
            $frame[]   = 'https://js.stripe.com';
            $frame[]   = 'https://hooks.stripe.com';
            $frame[]   = 'https://checkout.stripe.com';
            $connect[] = 'https://api.stripe.com';
        }

        $directives = [
            "default-src {$self}",
            'script-src '.implode(' ', array_values(array_unique($script))),
            'style-src '.implode(' ', array_values(array_unique($style))),
            'img-src '.implode(' ', array_values(array_unique($img))),
            'font-src '.implode(' ', array_values(array_unique($font))),
            'connect-src '.implode(' ', array_values(array_unique($connect))),
            'frame-src '.implode(' ', array_values(array_unique($frame))),
            'media-src '.implode(' ', array_values(array_unique($media))),
            'worker-src '.implode(' ', array_values(array_unique($worker))),
            "manifest-src {$self}",
            "frame-ancestors {$self}",
            "base-uri {$self}",
            "form-action {$self}",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ];

        return implode('; ', $directives);
    }

    private function buildCsp(string $nonce): string
    {
        $self = "'self'";
        $nonceTok = "'nonce-{$nonce}'";

        $script  = [$self, $nonceTok];
        $style   = [$self, "'unsafe-inline'"]; // inline style="…" is widespread; not an XSS vector on its own
        $img     = [$self, 'data:', 'https:']; // remote article hero images come from many origins
        $font    = [$self, 'data:', 'https://fonts.bunny.net'];
        $connect = [$self];
        $frame   = [$self];
        $media   = [$self, 'https:'];
        $worker  = [$self];

        // ---------- Analytics provider ------------------------------
        $provider = function_exists('getcong') ? trim((string) getcong('analytics_provider')) : '';
        switch ($provider) {
            case 'ga4':
                $script[]  = 'https://www.googletagmanager.com';
                // GA4 calls out to multiple regional collection endpoints
                // (region1.google-analytics.com etc) — wildcard both
                // google-analytics.com and analytics.google.com so any
                // current or future subdomain works.
                $connect[] = 'https://*.google-analytics.com';
                $connect[] = 'https://*.analytics.google.com';
                $connect[] = 'https://www.googletagmanager.com';
                $img[]     = 'https://*.google-analytics.com';
                break;
            case 'plausible':
                $host = trim((string) getcong('analytics_plausible_host')) ?: 'plausible.io';
                $script[]  = 'https://'.$host;
                $connect[] = 'https://'.$host;
                break;
            case 'fathom':
                $script[]  = 'https://cdn.usefathom.com';
                $connect[] = 'https://cdn.usefathom.com';
                break;
            case 'cloudflare':
                $script[]  = 'https://static.cloudflareinsights.com';
                $connect[] = 'https://cloudflareinsights.com';
                break;
            case 'umami':
                $url = trim((string) getcong('analytics_umami_script_url'));
                $h2  = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
                if ($h2) {
                    $script[]  = 'https://'.$h2;
                    $connect[] = 'https://'.$h2;
                }
                break;
        }

        // ---------- reCAPTCHA / Turnstile ---------------------------
        if ($this->captchaEnabled()) {
            $script[]  = 'https://www.google.com';
            $script[]  = 'https://www.gstatic.com';
            $script[]  = 'https://challenges.cloudflare.com';
            $frame[]   = 'https://www.google.com';
            $frame[]   = 'https://challenges.cloudflare.com';
            $connect[] = 'https://www.google.com';
        }

        // ---------- Stripe (Cashier paywall) ------------------------
        if (config('paywall.default_price_id') || env('STRIPE_KEY')) {
            $script[]  = 'https://js.stripe.com';
            $frame[]   = 'https://js.stripe.com';
            $frame[]   = 'https://hooks.stripe.com';
            $frame[]   = 'https://checkout.stripe.com';
            $connect[] = 'https://api.stripe.com';
        }

        // ---------- Embed allowlist (mirrors sanitize_embed_html) ---
        foreach ([
            'https://www.youtube.com',
            'https://www.youtube-nocookie.com',
            'https://player.vimeo.com',
            'https://player.twitch.tv',
            'https://www.dailymotion.com',
            'https://www.facebook.com',
        ] as $f) {
            $frame[] = $f;
        }

        $directives = [
            "default-src {$self}",
            'script-src '.implode(' ', array_values(array_unique($script))),
            'style-src '.implode(' ', array_values(array_unique($style))),
            'img-src '.implode(' ', array_values(array_unique($img))),
            'font-src '.implode(' ', array_values(array_unique($font))),
            'connect-src '.implode(' ', array_values(array_unique($connect))),
            'frame-src '.implode(' ', array_values(array_unique($frame))),
            'media-src '.implode(' ', array_values(array_unique($media))),
            'worker-src '.implode(' ', array_values(array_unique($worker))),
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ];

        return implode('; ', $directives);
    }

    private function captchaEnabled(): bool
    {
        if (! function_exists('getcong')) return false;
        foreach ([
            'recaptcha_on_login',
            'recaptcha_on_signup',
            'recaptcha_on_forgot_pass',
            'recaptcha_on_contact',
            'recaptcha_on_comment',
        ] as $key) {
            if (getcong($key)) return true;
        }
        return false;
    }
}
