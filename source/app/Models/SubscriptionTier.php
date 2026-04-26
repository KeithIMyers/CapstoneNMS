<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One reader-facing subscription offering. The tier `slug` doubles
 * as the Cashier subscription name — so an active row in
 * `subscriptions` (Cashier's table) with `name = 'gold'` means the
 * user is subscribed to the "gold" tier.
 *
 * Tiers expose two billing intervals via Stripe Price IDs (monthly
 * + optional annual). A tier with neither price configured won't
 * appear on the public picker.
 */
class SubscriptionTier extends Model
{
    protected $table = 'subscription_tiers';

    protected $fillable = [
        'slug', 'name', 'description',
        'stripe_price_monthly', 'stripe_price_annual',
        'monthly_price_cents', 'annual_price_cents',
        'currency', 'entitlements', 'active',
        'is_default', 'sort_order',
        'is_team', 'min_seats', 'max_seats',
    ];

    protected $casts = [
        'entitlements'        => 'array',
        'active'              => 'boolean',
        'is_default'          => 'boolean',
        'sort_order'          => 'integer',
        'monthly_price_cents' => 'integer',
        'annual_price_cents'  => 'integer',
        'is_team'             => 'boolean',
        'min_seats'           => 'integer',
        'max_seats'           => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** Tiers configured with at least one Stripe Price ID. */
    public function scopePurchasable(Builder $q): Builder
    {
        return $q->active()->where(function ($q2) {
            $q2->whereNotNull('stripe_price_monthly')->where('stripe_price_monthly', '!=', '')
               ->orWhere(function ($q3) {
                   $q3->whereNotNull('stripe_price_annual')->where('stripe_price_annual', '!=', '');
               });
        });
    }

    /**
     * Cached list of active tier slugs. Used by User::isPaidSubscriber
     * to ask Cashier whether ANY tier is active without hitting the
     * subscription_tiers table on every paywall check.
     *
     * @return array<int, string>
     */
    public static function cachedActiveSlugs(): array
    {
        return Cache::remember('subscription_tier_slugs', 300, function () {
            return static::active()->pluck('slug')->all();
        });
    }

    public static function clearSlugCache(): void
    {
        Cache::forget('subscription_tier_slugs');
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::clearSlugCache());
        static::deleted(fn () => static::clearSlugCache());
    }

    /** Display-only formatted prices. */
    public function monthlyPriceLabel(): ?string
    {
        return $this->monthly_price_cents === null
            ? null
            : '$'.number_format($this->monthly_price_cents / 100, 2);
    }

    public function annualPriceLabel(): ?string
    {
        return $this->annual_price_cents === null
            ? null
            : '$'.number_format($this->annual_price_cents / 100, 2);
    }
}
