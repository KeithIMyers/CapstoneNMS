<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued / in-flight / completed story for a news agent. The
 * dispatcher walks this table ordered by priority + scheduled_for and
 * processes one row at a time per agent.
 *
 * Statuses:
 *   queued      ready for the next dispatcher tick
 *   drafting    dispatcher in flight (set as the run starts)
 *   drafted     article exists; image generation pending
 *   published   article exists with image; status reflected on news row
 *   failed      attempt threw; last_error captures why
 *   skipped     editor rejected from the queue manually
 */
class AgentStory extends Model
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_DRAFTING  = 'drafting';
    public const STATUS_DRAFTED   = 'drafted';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    public const STATUSES = [
        self::STATUS_QUEUED    => 'Queued',
        self::STATUS_DRAFTING  => 'Drafting',
        self::STATUS_DRAFTED   => 'Drafted',
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_FAILED    => 'Failed',
        self::STATUS_SKIPPED   => 'Skipped',
    ];

    protected $table = 'agent_stories';

    protected $fillable = [
        'agent_id', 'topic', 'brief',
        'source_urls', 'category_id',
        'status', 'priority', 'scheduled_for',
        'news_id', 'attempt_count', 'last_error', 'completed_at',
    ];

    protected $casts = [
        'source_urls'   => 'array',
        'priority'      => 'integer',
        'attempt_count' => 'integer',
        'scheduled_for' => 'datetime',
        'completed_at'  => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(NewsAgent::class, 'agent_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    public function scopeReady(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_QUEUED)
            ->where(function ($q2) {
                $q2->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            });
    }
}
