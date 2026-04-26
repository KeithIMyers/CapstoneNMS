<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Normalize app.url so a trailing-slash value in .env doesn't
        // cascade into "https://example.com//storage/..." URLs from
        // Storage::url(). We strip once at boot rather than guarding at
        // every callsite.
        $url = (string) config('app.url');
        if ($url !== '' && str_ends_with($url, '/')) {
            config(['app.url' => rtrim($url, '/')]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Social auto-poster fires on every News transition into the
        // published state. Fail-soft per channel: missing config = no-op.
        \App\Models\News::observe(\App\Observers\NewsSocialPostObserver::class);

        // Breaking-news Web Push: when is_breaking flips to true on a
        // published article, broadcast to every subscribed browser.
        // Once-per-article dedup via the activity log.
        \App\Models\News::observe(\App\Observers\NewsBreakingPushObserver::class);

        // Auto-translate on publish: walks the article's
        // auto_translate_locales JSON and creates sibling translations
        // through the existing translation_group plumbing. Idempotent:
        // already-translated locales are skipped.
        \App\Models\News::observe(\App\Observers\NewsAutoTranslateObserver::class);

        // Brute-force / enumeration throttle for the auth surface
        // (login, signup, password reset request, password reset
        // submit). Two limits per request — the first to trip wins.
        // Per-email cap blocks credential stuffing against a single
        // account; per-IP cap blocks horizontal sweeps across many
        // accounts from one source.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            return [
                Limit::perMinute(5)->by('auth_email:'.$email),
                Limit::perMinute(20)->by('auth_ip:'.$request->ip()),
            ];
        });

        // Hard guard: Cashier silently skips webhook signature checks
        // when the secret is empty, which would let an attacker forge
        // subscription events. Refuse to boot in production unless the
        // secret is set. In local/dev we still log a warning but allow
        // the app to come up so a fresh checkout doesn't require Stripe
        // configuration.
        if (config('cashier.key') && empty(config('cashier.webhook.secret'))) {
            $msg = 'Cashier is configured (STRIPE_KEY present) but STRIPE_WEBHOOK_SECRET is empty — webhook signature checks would not run.';
            if (app()->environment('production')) {
                throw new \RuntimeException($msg);
            }
            \Illuminate\Support\Facades\Log::warning($msg);
        }

        // Handle one-time Stripe charges (donations + pay-per-article)
        // by listening on Cashier's verified webhook event. Cashier
        // already validates the signature; our listener just claims
        // the checkout.session.completed events whose metadata says
        // they're ours.
        Event::listen(WebhookReceived::class, \App\Listeners\HandleStripeOneTimeCharges::class);
    }
}
