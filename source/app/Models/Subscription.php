<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Newsletter subscriber. Double-opt-in: created in `pending` state
 * (confirmed_at = null) and only flipped to active once the user
 * clicks the link in the confirmation email.
 *
 * Each row is one (email, product_id) tuple — a single email can
 * sit in multiple rows when subscribed to multiple newsletter
 * products, each with its own confirm + unsubscribe lifecycle and
 * its own token.
 */
class Subscription extends Model
{
    protected $table = 'newsletter_subscriptions';

    protected $fillable = [
        'email', 'product_id', 'token', 'source',
        'confirmed_at', 'unsubscribed_at', 'signup_ip',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if (empty($row->token)) {
                $row->token = self::newToken();
            }
        });
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public function isActive(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at')->whereNull('unsubscribed_at');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(NewsletterProduct::class, 'product_id');
    }
}
