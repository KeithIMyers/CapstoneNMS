<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One active team plan. The owner pays via Cashier (subscription
 * name = $tier_slug, quantity = $seat_count); each row in
 * team_seats fills one slot.
 */
class TeamSubscription extends Model
{
    protected $table = 'team_subscriptions';

    protected $fillable = [
        'owner_user_id', 'tier_slug', 'seat_count', 'team_name',
    ];

    protected $casts = [
        'seat_count' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(TeamSeat::class);
    }

    public function tier(): ?SubscriptionTier
    {
        return SubscriptionTier::where('slug', $this->tier_slug)->first();
    }

    /** Active when the owner's Cashier subscription on this tier is current. */
    public function isActive(): bool
    {
        $owner = $this->owner;
        return $owner && method_exists($owner, 'subscribed') && $owner->subscribed($this->tier_slug);
    }

    public function activeSeatsCount(): int
    {
        return $this->seats()->where('status', 'active')->count();
    }

    public function pendingInvitesCount(): int
    {
        return $this->seats()->where('status', 'invited')->count();
    }

    public function openSeatsCount(): int
    {
        return max(0, $this->seat_count - $this->activeSeatsCount() - $this->pendingInvitesCount());
    }
}
