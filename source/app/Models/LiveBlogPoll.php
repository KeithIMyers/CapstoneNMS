<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveBlogPoll extends Model
{
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $table = 'live_blog_polls';

    protected $fillable = [
        'live_blog_id', 'question', 'options', 'tallies',
        'total_votes', 'status', 'closes_at', 'is_pinned', 'sort_order',
    ];

    protected $casts = [
        'options'     => 'array',
        'tallies'     => 'array',
        'total_votes' => 'integer',
        'is_pinned'   => 'boolean',
        'sort_order'  => 'integer',
        'closes_at'   => 'datetime',
    ];

    public function liveBlog(): BelongsTo
    {
        return $this->belongsTo(LiveBlog::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(LiveBlogPollVote::class, 'poll_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_OPEN)
            ->where(function ($qq) {
                $qq->whereNull('closes_at')->orWhere('closes_at', '>', now());
            });
    }

    /** Percentages per choice id, integer-rounded so they sum ≈ 100. */
    public function percentages(): array
    {
        $tallies = (array) ($this->tallies ?? []);
        $total = max(1, (int) $this->total_votes);
        $out = [];
        foreach ((array) $this->options as $opt) {
            $cid = (int) ($opt['id'] ?? 0);
            $out[$cid] = (int) round(((int) ($tallies[$cid] ?? 0)) * 100 / $total);
        }
        return $out;
    }

    public function isAcceptingVotes(): bool
    {
        if ($this->status !== self::STATUS_OPEN) return false;
        if ($this->closes_at && $this->closes_at->isPast()) return false;
        return true;
    }
}
