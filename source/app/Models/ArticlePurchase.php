<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ArticlePurchase extends Model
{
    protected $table = 'article_purchases';

    protected $fillable = [
        'user_id', 'news_id', 'amount_cents', 'currency',
        'stripe_session_id', 'stripe_charge_id', 'status', 'expires_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'expires_at'   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'succeeded')
            ->where(function ($qq) {
                $qq->whereNull('expires_at')
                   ->orWhere('expires_at', '>', Carbon::now());
            });
    }

    public function isActive(): bool
    {
        if ($this->status !== 'succeeded') return false;
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
