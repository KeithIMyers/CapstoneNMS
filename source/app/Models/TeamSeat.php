<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamSeat extends Model
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_INVITED  = 'invited';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REMOVED  = 'removed';

    protected $table = 'team_seats';

    protected $fillable = [
        'team_subscription_id', 'member_user_id',
        'invited_email', 'invitation_token_hash', 'status',
        'invited_at', 'accepted_at', 'removed_at',
    ];

    protected $casts = [
        'invited_at'  => 'datetime',
        'accepted_at' => 'datetime',
        'removed_at'  => 'datetime',
    ];

    public function teamSubscription(): BelongsTo
    {
        return $this->belongsTo(TeamSubscription::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_user_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }
}
