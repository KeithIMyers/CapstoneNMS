<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Virtual author / news agent. Each row pairs:
 *
 *   - A User row (the persona — name, photo, bio, byline link)
 *   - Persona config (writing style, knowledgebase, target word count)
 *   - Optional model overrides (chat provider/model, image provider/model)
 *   - A schedule (hourly | daily | weekly + posts_per_run)
 *
 * The dispatcher pulls agent_stories whose status = queued and
 * scheduled_for <= now(), runs each one through a research → draft →
 * image → publish pipeline, and stamps last_run_at on the agent.
 */
class NewsAgent extends Model
{
    public const FREQ_HOURLY = 'hourly';
    public const FREQ_DAILY  = 'daily';
    public const FREQ_WEEKLY = 'weekly';

    public const FREQUENCIES = [
        self::FREQ_HOURLY => 'Every hour',
        self::FREQ_DAILY  => 'Once a day',
        self::FREQ_WEEKLY => 'Once a week (Mondays)',
    ];

    protected $table = 'news_agents';

    protected $fillable = [
        'user_id', 'writing_style', 'knowledgebase', 'topic_keywords',
        'ai_provider_id', 'ai_model', 'temperature', 'max_tokens',
        'image_provider_id', 'image_model', 'image_style_hint',
        'schedule_frequency', 'schedule_hour_utc', 'posts_per_run',
        'target_word_count',
        'last_run_at', 'is_active',
    ];

    protected $casts = [
        'topic_keywords'    => 'array',
        'is_active'         => 'boolean',
        'temperature'       => 'float',
        'max_tokens'        => 'integer',
        'schedule_hour_utc' => 'integer',
        'posts_per_run'     => 'integer',
        'target_word_count' => 'integer',
        'last_run_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function imageProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'image_provider_id');
    }

    public function stories(): HasMany
    {
        return $this->hasMany(AgentStory::class, 'agent_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Same isDueNow shape we use on AiAgent — picked from the agent's
     * schedule_frequency + last_run_at + UTC hour. Returns false when
     * the agent has no schedule (manual-only).
     */
    public function isDueNow(): bool
    {
        if (! $this->is_active || ! $this->schedule_frequency) return false;
        $now  = now();
        $last = $this->last_run_at;
        $hour = $this->schedule_hour_utc ?? 13;

        return match ($this->schedule_frequency) {
            self::FREQ_HOURLY => ! $last || $last->lt($now->copy()->subMinutes(55)),
            self::FREQ_DAILY  => $now->utc()->hour >= $hour && (! $last || ! $last->isToday()),
            self::FREQ_WEEKLY => $now->utc()->dayOfWeek === \Carbon\Carbon::MONDAY
                                 && $now->utc()->hour >= $hour
                                 && (! $last || $last->lt($now->copy()->startOfWeek())),
            default => false,
        };
    }
}
