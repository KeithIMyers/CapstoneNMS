<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A newsletter product readers can subscribe to independently.
 *
 *   "Daily brief"      cadence=daily      sent every morning
 *   "Weekly roundup"   cadence=weekly     sent Friday
 *   "Breaking news"    cadence=on-publish dispatched per article
 *   "Topic: Politics"  cadence=topical    dispatched per matching article
 *
 * Subscribers join via newsletter_subscriptions rows tying their
 * email + product_id. Each (email, product) tuple has its own
 * confirm-at and unsubscribe-at lifecycle.
 */
class NewsletterProduct extends Model
{
    protected $table = 'newsletter_products';

    protected $fillable = [
        'slug', 'name', 'description', 'cadence',
        'is_default', 'active', 'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'product_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** Convenience: the "default" product, used when a UI doesn't pick one. */
    public static function default(): ?self
    {
        return static::where('is_default', true)->first()
            ?? static::orderBy('sort_order')->first();
    }
}
