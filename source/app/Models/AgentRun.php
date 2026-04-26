<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent record of one agent invocation. The `transcript` JSON
 * captures the full message exchange between the model, the tools, and
 * the runner — the source of truth for debugging an agent that misbehaved.
 *
 * timestamps() is replaced by explicit started_at / finished_at since
 * runs are intentionally point-in-time; no created_at / updated_at.
 */
class AgentRun extends Model
{
    public $timestamps = false;
    protected $table = 'agent_runs';

    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_ERROR   = 'error';

    protected $fillable = [
        'agent_key', 'user_id', 'status', 'input_message',
        'final_output', 'transcript',
        'iterations', 'tokens_in_total', 'tokens_out_total',
        'cost_microusd', 'duration_ms',
        'error_message', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'transcript'  => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'agent_key', 'key');
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
