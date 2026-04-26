<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Web Push endpoint a visitor's browser registered with us. Stored
 * with the keys (p256dh + auth) needed for VAPID-encrypted delivery.
 *
 * One row per browser per site. Endpoints rotate on browser update —
 * the WebPusher service prunes stale rows on 410/404 responses.
 */
class PushSubscription extends Model
{
    protected $fillable = [
        'user_id', 'endpoint', 'p256dh', 'auth', 'user_agent', 'last_used_at',
    ];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
