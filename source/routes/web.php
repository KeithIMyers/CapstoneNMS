<?php

use Illuminate\Support\Facades\Route;


Route::group(['namespace' => 'App\Http\Controllers'], function () {    

    Route::get('/', 'IndexController@index')->name('home');

    Route::get('category/{slug}', 'NewsController@category_news')->name('category_news');

    Route::get('tags/{slug}', 'NewsController@tags_news')->name('tags_news');

    Route::get('news/{slug}', 'NewsController@details')->name('news.details');

    // Localized article detail: /{locale}/news/{slug}. The `where` clause
    // constrains the locale to the configured supported list minus the
    // default, so /en/news/foo doesn't duplicate the canonical URL.
    Route::get('{locale}/news/{slug}', 'NewsController@details')
        ->middleware(\App\Http\Middleware\SetLocaleFromRoute::class)
        ->where('locale', implode('|', array_filter(
            array_keys(config('locales.supported', [])),
            fn ($l) => $l !== config('locales.default', 'en'),
        )) ?: 'xx')
        ->name('news.details.localized');

    Route::get('author/{slug}', 'AuthorController@show')->name('author');

    Route::get('live/{slug}', 'LiveBlogController@show')->name('live');

    // Real-time-ish reader updates: page-side poll fetches entries
    // newer than `?since=<unix-seconds>` so new posts land without a
    // full reload. Cheap, indexed query; no SSE / WebSocket required
    // for the volume a typical newsroom live blog hits.
    Route::get('live/{slug}/entries.json', 'LiveBlogController@entriesJson')
        ->name('live.entries_json');

    // Live-blog poll voting + state. The vote endpoint is not in the
    // CSRF exemption list — site.js sends X-CSRF-TOKEN.
    Route::post('live/polls/{poll}/vote', 'LiveBlogPollController@vote')
        ->middleware('throttle:10,1')
        ->name('live.polls.vote');
    Route::get('live/polls/{poll}/state', 'LiveBlogPollController@state')
        ->name('live.polls.state');

    Route::get('search', 'NewsController@search')->name('search');
    // Search-as-you-type endpoint for the header search box.
    Route::get('search/autocomplete', 'NewsController@autocomplete')->name('search.autocomplete');

    Route::post('report_news', 'NewsController@report_news')->name('report_news');
    Route::post('comment_send', 'NewsController@comment_send')->name('comment_send');

    Route::get('pages/{slug}', 'PagesController@details')->name('page_details');
    Route::get('pages/contact-us', 'PagesController@contact')->name('contact_us');
    Route::post('pages/contact-us', 'PagesController@contact_send')->name('contact_send');


    Route::get('login', 'IndexController@login')->name('user_login');
    Route::post('login', 'IndexController@postLogin')
        ->middleware('throttle:auth')
        ->name('user_login_check');

    Route::get('auth/google', 'Auth\GoogleController@redirectToGoogle')->name('google_login');
    Route::get('auth/google/callback', 'Auth\GoogleController@handleGoogleCallback');

    Route::get('signup', 'IndexController@signup')->name('signup');
    Route::post('signup', 'IndexController@postSignup')
        ->middleware('throttle:auth')
        ->name('user_signup');

    Route::get('logout', 'IndexController@logout')->name('user_logout');

    Route::get('profile', 'UserController@profile')->name('user_profile');
    Route::post('profile', 'UserController@editprofile')->name('user_profile_update');
    Route::get('favorites', 'UserController@user_favorite')->name('user_favorites');

    // Reading lists + reading history. Reader-facing per-user
    // surfaces; the AJAX add/remove endpoints are JSON-only and the
    // controller short-circuits to 401 when the visitor isn't signed
    // in (so the front-end can prompt-to-sign-in cleanly).
    Route::get('profile/lists', 'ReadingListController@index')->name('reading_lists.index');
    Route::post('profile/lists', 'ReadingListController@store')->name('reading_lists.store');
    Route::get('profile/lists/{slug}', 'ReadingListController@show')
        ->where('slug', '[A-Za-z0-9-]+')
        ->name('reading_lists.show');
    Route::post('profile/lists/{slug}/add', 'ReadingListController@add')
        ->where('slug', '[A-Za-z0-9-]+')
        ->name('reading_lists.add');
    Route::post('profile/lists/{slug}/remove', 'ReadingListController@remove')
        ->where('slug', '[A-Za-z0-9-]+')
        ->name('reading_lists.remove');
    Route::get('profile/history', 'ReadingListController@history')->name('reading_history');
    Route::post('profile/history/clear', 'ReadingListController@clearHistory')->name('reading_history.clear');

    // Comment likes (toggle). Authenticated readers only. Throttle
    // protects the toggle from one-user spam; row uniqueness handles
    // the data integrity side.
    Route::post('comments/{comment}/like', 'CommentLikeController@toggle')
        ->middleware('throttle:60,1')
        ->name('comments.like.toggle');

    // Refer-a-friend surface: visitor's unique code, share URL,
    // tally of conversions + earned rewards. Code is lazily
    // generated on first visit.
    Route::get('profile/referrals', 'ReferralController@show')->name('referrals.show');

    // Reader-side accessibility preferences. The CSS toggles read
    // html[data-pref-*] attributes set by an inline head script
    // from localStorage; authenticated users get server-side
    // persistence + cross-device sync via /sync.
    Route::get('profile/accessibility', 'AccessibilityController@show')->name('accessibility.show');
    Route::post('profile/accessibility', 'AccessibilityController@update')->name('accessibility.update');
    Route::get('profile/accessibility/sync', 'AccessibilityController@sync')->name('accessibility.sync');

    // GDPR / CCPA self-service. Export streams a JSON dump of every
    // row tied to the user; deletion schedules a 7-day grace window
    // before the purge command hard-deletes + cascades.
    Route::get('profile/privacy', 'PrivacyController@show')->name('privacy.show');
    Route::post('profile/privacy', 'PrivacyController@update')->name('privacy.update');
    // Cap GDPR exports at a few per hour so a compromised account
    // can't be used to repeatedly dump full account data on demand.
    Route::post('profile/privacy/export', 'PrivacyController@export')
        ->middleware('throttle:5,60')
        ->name('privacy.export');
    Route::post('profile/privacy/delete', 'PrivacyController@requestDeletion')->name('privacy.delete');
    Route::post('profile/privacy/cancel-deletion', 'PrivacyController@cancelDeletion')->name('privacy.cancel_deletion');

    Route::post('ajax_actions', 'ActionsController@ajax_actions')->name('ajax_actions');

    // Tracking beacons: per-session dedup blocks honest reloads from
    // skewing counters but doesn't stop a script that rotates cookies.
    // Layer a hard per-IP throttle in front so a counter can't be
    // inflated arbitrarily.
    Route::post('track/scroll/{article}', 'TrackingController@scroll')
        ->middleware('throttle:120,1')->name('track.scroll');
    Route::post('track/headline-click/{headline}', 'TrackingController@headlineClick')
        ->middleware('throttle:120,1')->name('track.headline_click');
    Route::post('track/ad-click/{slot}', 'TrackingController@adClick')
        ->middleware('throttle:120,1')->name('track.ad_click');
    Route::get('ad/serve/{placement}', 'TrackingController@adServe')->name('ad.serve');

    Route::get('topics/{slug}', 'TopicController@show')->name('topics.show');
    Route::get('series/{slug}', 'SeriesController@show')->name('series.show');

    // Public "Ask the newsroom" — research agent answers visitor questions.
    // Rate-limited per-IP and dedup-cached per-question; falls open to a
    // disabled-state view when no AI provider / agent is configured.
    Route::get('ask', 'AskController@show')->name('ask.show');
    // Belt-and-braces: the controller already enforces a per-IP limit
    // inside `ask()`, but applying middleware here means Laravel will
    // 429 abusive clients before the handler executes (no DB writes,
    // no session reads, no LLM calls).
    Route::post('ask', 'AskController@ask')
        ->middleware('throttle:30,60')
        ->name('ask.ask');

    // External-image proxy. Tokens are encrypted with APP_KEY so the
    // route can't be used as a generic SSRF surface; the controller
    // also re-runs the SSRF / DNS-pinning check on every miss.
    Route::get('img-proxy', 'ImageProxyController@proxy')
        ->middleware('throttle:120,1')
        ->name('image.proxy');

    // Offline fallback rendered by the service worker when both network
    // and precache miss.
    Route::view('offline', 'pages.offline')->name('offline');

    // Set the visitor's preferred locale via cookie. Used by the
    // public language nudge banner + the footer language switcher.
    // The actual locale value is validated against config('locales.supported')
    // so an attacker can't poison the cookie with arbitrary strings.
    Route::get('set-locale/{locale}', function (string $locale) {
        $supported = array_keys(config('locales.supported', []));
        if (! in_array($locale, $supported, true)) abort(404);
        // 1-year persistence; the cookie is read by preferred_locale()
        // and by the locale-prefix routing in P2-G3.
        cookie()->queue('usnt_locale', $locale, 60 * 24 * 365);
        return redirect(request()->header('referer') ?: url('/'));
    })->name('set_locale');

    // Web Push subscription endpoints. Public — VAPID signing serves as
    // the auth, so they're CSRF-exempt in bootstrap/app.php.
    Route::get('push/key', 'PushController@key')->name('push.key');
    Route::post('push/subscribe', 'PushController@subscribe')->name('push.subscribe');
    Route::post('push/unsubscribe', 'PushController@unsubscribe')->name('push.unsubscribe');

    // Stripe-backed subscription. Cashier auto-registers POST
    // /stripe/webhook for billing events; routes here cover the
    // public-facing subscribe + success pages.
    Route::get('subscribe', 'SubscribeController@show')->name('subscribe.show');
    Route::post('subscribe/checkout', 'SubscribeController@checkout')->name('subscribe.checkout');
    Route::get('subscribe/success', 'SubscribeController@success')->name('subscribe.success');
    Route::get('subscribe/cancel', 'SubscribeController@cancelShow')->name('subscribe.cancel.show');
    Route::post('subscribe/cancel', 'SubscribeController@cancelSubmit')->name('subscribe.cancel.submit');

    // Team / group subscriptions. Owner manages seats from
    // /profile/team; recipients accept via the tokenized
    // /team/accept/{token} URL the inviter sends.
    Route::get('profile/team', 'TeamSubscriptionController@show')->name('team.show');
    Route::post('profile/team/{team}/invite', 'TeamSubscriptionController@invite')->name('team.invite');
    Route::post('profile/team/seats/{seat}/remove', 'TeamSubscriptionController@removeSeat')->name('team.seat.remove');
    Route::get('team/accept/{token}', 'TeamSubscriptionController@accept')
        ->where('token', '[A-Za-z0-9]+')
        ->name('team.accept');

    // One-time donations. Stripe Checkout in payment mode; the
    // listener in AppServiceProvider claims the webhook event and
    // flips the donations row succeeded once the charge clears.
    Route::get('donate', 'DonateController@show')->name('donate.show');
    Route::post('donate/checkout', 'DonateController@checkout')->name('donate.checkout');
    Route::get('donate/success', 'DonateController@success')->name('donate.success');

    // Pay-per-article: a non-subscriber buys access to one premium
    // article via Stripe Checkout. Paywall::canRead consults the
    // article_purchases table after gift redemption.
    Route::post('articles/{news}/buy', 'ArticlePurchaseController@checkout')->name('articles.buy');

    // SSE-streamed in-editor AI assistants. Auth is checked inside the
    // controller; the streaming routes live outside /admin so the
    // Filament panel's middleware chain doesn't capture the response.
    Route::get('admin-ai/stream/copyedit/{news}', 'StreamingAiController@copyedit')
        ->name('ai.stream.copyedit');
    Route::post('admin-ai/stream/copyedit/{news}/apply', 'StreamingAiController@applyCopyedit')
        ->name('ai.stream.copyedit.apply');

    // Podcasts: index, per-show landing, single episode, and iTunes feed.
    Route::get('podcasts', 'PodcastController@index')->name('podcasts.index');
    Route::get('podcasts/{slug}', 'PodcastController@show')->name('podcasts.show');
    Route::get('podcasts/{slug}/feed.xml', 'PodcastController@feed')->name('podcasts.feed');
    Route::get('podcasts/{showSlug}/{episodeSlug}', 'PodcastController@episode')->name('podcasts.episode');

    // Newsletter (double opt-in)
    Route::post('newsletter/subscribe', 'NewsletterController@subscribe')->name('newsletter.subscribe');
    Route::get('newsletter/confirm/{token}', 'NewsletterController@confirm')->name('newsletter.confirm');
    Route::get('newsletter/unsubscribe/{token}', 'NewsletterController@unsubscribe')->name('newsletter.unsubscribe');

    // Mail-provider webhook receivers. Auth is per-provider:
    //  - Postmark: HTTP Basic credentials (POSTMARK_WEBHOOK_USER/PWD)
    //  - Mailgun: HMAC-SHA256 signature in body (MAILGUN_WEBHOOK_SIGNING_KEY)
    // Both are CSRF-exempt below in bootstrap/app.php.
    Route::post('webhooks/mail/postmark', 'Webhooks\\PostmarkWebhookController')
        ->name('webhooks.mail.postmark');
    Route::post('webhooks/mail/mailgun', 'Webhooks\\MailgunWebhookController')
        ->name('webhooks.mail.mailgun');

    // Passwordless sign-in. The request + send routes are throttled
    // through the same `auth` named limiter that protects the login
    // form so brute-force enumeration shares one bucket per IP/email.
    // Public 2FA challenge for sign-in flows that don't run through
    // the password form (Google OAuth, magic-link). The Filament panel
    // has its own challenge page; this one fires before the user even
    // hits an admin route.
    Route::get('two-factor/challenge', 'Auth\TwoFactorChallengeController@show')
        ->middleware('throttle:auth')
        ->name('two_factor.challenge');
    Route::post('two-factor/challenge', 'Auth\TwoFactorChallengeController@verify')
        ->middleware('throttle:auth')
        ->name('two_factor.verify');

    Route::get('auth/magic-link', 'Auth\MagicLinkController@show')->name('magic_link.show');
    Route::post('auth/magic-link', 'Auth\MagicLinkController@send')
        ->middleware('throttle:auth')
        ->name('magic_link.send');
    Route::get('auth/magic-link/{token}', 'Auth\MagicLinkController@consume')
        ->middleware('throttle:auth')
        ->where('token', '[A-Za-z0-9]+')
        ->name('magic_link.consume');

    Route::get('password/email', 'Auth\ForgotPasswordController@forget_password')->name('forget_password');
    Route::post('password/email', 'Auth\ForgotPasswordController@forget_password_submit')
        ->middleware('throttle:auth')
        ->name('forget_password_submit');
    Route::get('password/reset/{token}', 'Auth\ForgotPasswordController@reset_password');
    Route::post('password/reset', 'Auth\ForgotPasswordController@reset_password_submit')
        ->middleware('throttle:auth')
        ->name('password_reset');
  
    Route::post('/articles/{article}/reactions', 'ReactionController@store')
        ->middleware('throttle:60,1')
        ->name('articles.reactions.store');
    Route::get('/articles/{article}/reactions', 'ReactionController@show')
        ->name('articles.reactions.show');

    // Gift articles. Subscribers issue single-use links granting a
    // non-subscriber full-body access to one premium article. The
    // service enforces an active subscription + monthly cap.
    Route::get('/articles/{news}/gift', 'GiftController@show')->name('articles.gift.show');
    Route::post('/articles/{news}/gift', 'GiftController@send')->name('articles.gift.send');
 
   Route::get('sitemap.xml', 'IndexController@sitemap');
   Route::get('sitemap-static.xml', 'IndexController@sitemap_static');
   Route::get('sitemap-news.xml', 'IndexController@sitemap_news');

});

// RSS / Atom / JSON feeds via spatie/laravel-feed.
Route::feeds();