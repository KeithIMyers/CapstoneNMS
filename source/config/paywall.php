<?php

/*
 * Paywall + Stripe configuration. The whole pipeline is opt-in:
 * leaving STRIPE_KEY blank means is_premium articles still render
 * the preview + CTA, and Cashier just doesn't have keys to call —
 * no errors, just no checkouts.
 *
 *   STRIPE_KEY              public publishable key
 *   STRIPE_SECRET           secret key (server-side calls)
 *   STRIPE_WEBHOOK_SECRET   verifies inbound webhook signatures
 *   STRIPE_DEFAULT_PRICE    Stripe Price ID for the monthly plan
 *   STRIPE_ANNUAL_PRICE     Optional Stripe Price ID for an annual plan
 *   STRIPE_GIFT_PRICE       Optional Stripe Price ID for a gift purchase
 *   STRIPE_DEFAULT_PLAN     Cashier "name" for the subscription row
 *                           (default: "default")
 *
 * Metered paywall:
 *   PAYWALL_METER_LIMIT       free premium-article reads per visitor
 *                             per window (default 5). Set to 0 to make
 *                             the gate fire on the first read.
 *   PAYWALL_METER_WINDOW_DAYS rolling window the meter resets on.
 */
return [
    'subscription_name' => env('STRIPE_DEFAULT_PLAN', 'default'),
    'default_price_id'  => env('STRIPE_DEFAULT_PRICE'),
    'annual_price_id'   => env('STRIPE_ANNUAL_PRICE'),
    'gift_price_id'     => env('STRIPE_GIFT_PRICE'),

    'meter_limit'       => (int) env('PAYWALL_METER_LIMIT', 5),
    'meter_window_days' => (int) env('PAYWALL_METER_WINDOW_DAYS', 30),

    /*
     * Ask-the-newsroom paywall + monthly quota.
     *
     * `enabled`            master switch; off = legacy IP rate-limit only.
     * `*_monthly_quota`    questions per calendar month for each tier.
     *                      0 = blocked entirely; null = unlimited.
     *
     * Tiers, in escalation order:
     *  - anonymous          no Laravel auth session
     *  - registered         logged in but no active subscription
     *  - subscriber         logged in with active Cashier subscription
     *
     * The SiteSettings → "Ask paywall" tab overrides each value at
     * runtime via getcong() — these are the env-driven defaults.
     */
    'ask' => [
        'enabled'                    => (bool) env('PAYWALL_ASK_ENABLED', false),
        'anonymous_monthly_quota'    => (int)  env('PAYWALL_ASK_ANON_MONTHLY', 3),
        'registered_monthly_quota'   => (int)  env('PAYWALL_ASK_USER_MONTHLY', 10),
        'subscriber_monthly_quota'   => (int)  env('PAYWALL_ASK_SUB_MONTHLY', 100),
    ],

    /*
     * Gift articles. Active subscribers can mint single-use links
     * that grant a non-subscriber full access to one premium article.
     * Each subscriber gets `monthly_cap` gifts per calendar month.
     * Redeemed gifts grant the recipient access for `link_ttl_days`
     * (the entitlement persists in the recipient's session/cookie
     * after redemption).
     */
    'gifts' => [
        'enabled'        => (bool) env('PAYWALL_GIFTS_ENABLED', true),
        'monthly_cap'    => (int)  env('PAYWALL_GIFTS_MONTHLY_CAP', 5),
        'link_ttl_days'  => (int)  env('PAYWALL_GIFTS_TTL_DAYS', 14),
    ],

    /*
     * Pay-per-article. A non-subscriber can buy single-article access
     * via Stripe Checkout. ttl_days = 0 means perpetual access for
     * that user; >0 expires N days after purchase.
     */
    'per_article' => [
        'enabled'      => (bool) env('PAYWALL_PER_ARTICLE_ENABLED', false),
        'price_cents'  => (int)  env('PAYWALL_PER_ARTICLE_PRICE_CENTS', 199),
        'ttl_days'     => (int)  env('PAYWALL_PER_ARTICLE_TTL_DAYS', 0),
    ],
];
