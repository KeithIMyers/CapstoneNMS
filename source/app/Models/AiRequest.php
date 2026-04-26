<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record of every completion this app issued. Created
 * via AiRequest::log() from the AiClient service; not fillable by form
 * requests. updated_at is disabled since these rows never change.
 */
class AiRequest extends Model
{
    public $timestamps = false;
    protected $table = 'ai_requests';

    protected $fillable = [
        'provider_id', 'user_id', 'model', 'purpose', 'status',
        'tokens_in', 'tokens_out', 'duration_ms', 'cost_microusd',
        'error_message', 'created_at',
    ];

    protected $casts = [
        'created_at'    => 'datetime',
        'tokens_in'     => 'integer',
        'tokens_out'    => 'integer',
        'duration_ms'   => 'integer',
        'cost_microusd' => 'integer',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function costUsd(): ?float
    {
        return $this->cost_microusd === null
            ? null
            : $this->cost_microusd / 1_000_000;
    }
}
