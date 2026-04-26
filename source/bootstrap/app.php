<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        // Promote scheduled articles whose published_at has arrived, and
        // unpublish articles whose unpublished_at has passed.
        $schedule->command('news:publish-due')->everyMinute()->withoutOverlapping();

        // Hard-delete accounts whose 7-day grace window has lapsed.
        // Daily cadence is fine — granularity finer than a day adds
        // nothing for users who already opted in to deletion.
        $schedule->command('accounts:purge')->dailyAt('03:15');

        // Drip-campaign worker. Runs every 15 minutes so step
        // dispatch granularity feels close to real-time without
        // hammering the DB. Each run walks active sequence runs and
        // sends any step whose delay_hours window has elapsed.
        $schedule->command('newsletter:drip-step')->everyFifteenMinutes()->withoutOverlapping();

        // Drain cache-resident A/B headline impression counters into
        // news_headlines. The per-impression DB write would otherwise
        // contend on hot rows during a viral event; we batch the
        // updates instead. One-minute cadence keeps CTR statistics
        // close enough to real-time for the weighting algorithm.
        $schedule->command('news:flush-headline-impressions')->everyMinute()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);

        // Trust X-Forwarded-* headers ONLY from explicitly listed proxies.
        // Trusting `*` lets any client forge X-Forwarded-For to bypass IP-
        // based rate limits, the AskQuota anonymous counter, and reCAPTCHA's
        // remoteip check, and forge X-Forwarded-Proto to weaken HTTPS-only
        // cookies. Configure via TRUSTED_PROXIES env (comma-separated). For
        // a Cloudflare front-door use Cloudflare's published IPv4/IPv6
        // ranges; for vanilla DreamHost (Apache → PHP-FPM on the same host)
        // 127.0.0.1 is enough.
        $trustedProxies = (string) env('TRUSTED_PROXIES', '127.0.0.1');
        $middleware->trustProxies(
            at: $trustedProxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            headers:
                \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );

        // Forces every visitor to /install until the customer
        // completes the web installer. Once installed.lock is present
        // this is a pass-through.
        $middleware->prepend(\App\Http\Middleware\RedirectToInstaller::class);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Captures `?ref=CODE` from any landing-page URL and stashes
        // it in a cookie. The signup flow reads the cookie via
        // ReferralService::capture so the new user gets linked to
        // their referrer regardless of how many pages they bounced
        // through before creating an account.
        $middleware->append(\App\Http\Middleware\CaptureReferralCode::class);

        // Full-page response cache for anonymous GETs (5-minute TTL,
        // skips admin/api/auth/profile/newsletter paths, bypassed for
        // logged-in users). Bust via the Cache::flush() event in News
        // / Category / Pages / Settings model boot hooks.
        $middleware->prependToGroup('web', \App\Http\Middleware\CacheGuestResponses::class);

        // Allow newsletter signup from cached pages — the cached HTML
        // would otherwise carry a stale CSRF token. Double-opt-in via
        // email is what actually verifies the subscriber.
        $middleware->validateCsrfTokens(except: [
            // The web installer runs on a fresh dist where the first
            // GET bootstrapped APP_KEY and the same browser may not
            // have a session yet. The InstallController::perform path
            // does its own input validation; CSRF on the very first
            // POST would force the customer to refresh and start over.
            'install',
            // newsletter/subscribe stays exempt because it can be POSTed
            // from response-cached pages (the cached HTML carries a
            // stale token); double-opt-in by email is the real verifier.
            'newsletter/subscribe',
            // track/* endpoints are first-party telemetry only — they
            // increment counters and do not authenticate, mutate user
            // state, or accept HTML; CSRF here just blocks legitimate
            // beacons from cached pages.
            'track/scroll/*',
            'track/headline-click/*',
            'track/ad-click/*',
            // Mail-provider webhooks. CSRF would fail (no browser
            // session); each handler verifies provider-specific auth
            // (Basic for Postmark, HMAC for Mailgun) before trust.
            'webhooks/mail/*',
            // Note: push/subscribe and push/unsubscribe are NOT exempt.
            // The site JS reads the CSRF token from <meta name="csrf-
            // token"> and sends it as X-CSRF-TOKEN. VAPID secures
            // server-to-browser delivery, not the registration call.
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
