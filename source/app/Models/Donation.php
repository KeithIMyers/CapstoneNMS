<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    protected $table = 'donations';

    protected $fillable = [
        'user_id', 'email', 'name', 'amount_cents', 'currency',
        'stripe_session_id', 'stripe_charge_id', 'status',
        'message', 'anonymous',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'anonymous'    => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeSucceeded(Builder $q): Builder
    {
        return $q->where('status', 'succeeded');
    }

    public function amountUsd(): float
    {
        return $this->amount_cents / 100;
    }
}
