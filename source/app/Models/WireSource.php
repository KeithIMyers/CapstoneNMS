<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Newsroom-managed RSS / Atom feed. The wire:ingest CRON walks every
 * active source on a schedule, parses its feed, and lands new items
 * as draft articles. ai_rewrite routes items through the compose
 * agent before saving — useful for rephrasing summaries from outlets
 * with paywalled bodies.
 */
class WireSource extends Model
{
    protected $fillable = [
        'name', 'feed_url',
        'is_active', 'ai_rewrite',
        'default_category_id', 'default_author_id',
        'last_fetched_at', 'limit_per_run',
    ];

    protected $casts = [
        'is_active'        => 'boolean',
        'ai_rewrite'       => 'boolean',
        'last_fetched_at'  => 'datetime',
        'limit_per_run'    => 'integer',
    ];

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'default_category_id');
    }

    public function defaultAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_author_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
