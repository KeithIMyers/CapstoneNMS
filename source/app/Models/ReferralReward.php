<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralReward extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_GRANTED   = 'granted';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_REVOKED   = 'revoked';

    public const TYPE_GIFT_ARTICLES = 'gift_articles';
    public const TYPE_FREE_MONTHS   = 'free_months';
    public const TYPE_CREDIT        = 'credit';

    protected $table = 'referral_rewards';

    protected $fillable = [
        'referrer_user_id', 'referee_user_id', 'referee_email',
        'reward_type', 'reward_amount', 'status',
        'triggered_at', 'granted_at', 'fulfilled_at', 'notes',
    ];

    protected $casts = [
        'reward_amount' => 'integer',
        'triggered_at'  => 'datetime',
        'granted_at'    => 'datetime',
        'fulfilled_at'  => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_user_id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }
}
