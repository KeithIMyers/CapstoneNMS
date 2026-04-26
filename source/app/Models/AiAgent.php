<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Editable agent definition. Identified by a stable `key`, parameterized
 * with a system prompt + an explicit allow-list of tool keys + an
 * iteration cap. Admins can author custom agents in Filament; the runner
 * looks up the row by key at execution time so prompt + tool changes
 * apply on the next invocation without a deploy.
 */
class AiAgent extends Model
{
    protected $table = 'ai_agents';

    public const FREQ_HOURLY = 'hourly';
    public const FREQ_DAILY  = 'daily';
    public const FREQ_WEEKLY = 'weekly';

    public const FREQUENCIES = [
        self::FREQ_HOURLY => 'Every hour',
        self::FREQ_DAILY  => 'Once a day',
        self::FREQ_WEEKLY => 'Once a week (Mondays)',
    ];

    protected $fillable = [
        'key', 'name', 'description', 'system_prompt',
        'tool_keys', 'max_iterations', 'temperature', 'max_tokens_per_step',
        'provider_id', 'model_override',
        'schedule_frequency', 'schedule_hour_utc', 'standing_input', 'last_run_at',
        'is_active', 'updated_by',
    ];

    protected $casts = [
        'tool_keys'           => 'array',
        'is_active'           => 'boolean',
        'temperature'         => 'float',
        'max_iterations'      => 'integer',
        'max_tokens_per_step' => 'integer',
        'schedule_hour_utc'   => 'integer',
        'last_run_at'         => 'datetime',
    ];

    /**
     * Decide whether this agent is overdue to run, given the configured
     * schedule and the last successful run timestamp. Tolerates clock
     * skew by comparing against "now minus a small buffer" rather than
     * exact equality.
     */
    public function isDueNow(): bool
    {
        if (! $this->is_active || ! $this->schedule_frequency || ! $this->standing_input) {
            return false;
        }

        $now = now();
        $last = $this->last_run_at;
        $hour = $this->schedule_hour_utc ?? 6;

        return match ($this->schedule_frequency) {
            self::FREQ_HOURLY => ! $last || $last->lt($now->copy()->subMinutes(55)),
            self::FREQ_DAILY  => $now->utc()->hour >= $hour
                                 && (! $last || ! $last->isToday()),
            self::FREQ_WEEKLY => $now->utc()->dayOfWeek === \Carbon\Carbon::MONDAY
                                 && $now->utc()->hour >= $hour
                                 && (! $last || $last->lt($now->copy()->startOfWeek())),
            default => false,
        };
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'agent_key', 'key');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
