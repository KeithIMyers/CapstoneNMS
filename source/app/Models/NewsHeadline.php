<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Headline variant for A/B testing. Each News article can have many
 * variants; the homepage and list pages pick one per session weighted
 * by click-through rate (with a small explore factor for newer
 * variants). is_default flags the canonical headline that articles
 * fall back to when no variant has impressions yet.
 */
class NewsHeadline extends Model
{
    protected $table = 'news_headlines';

    protected $fillable = ['news_id', 'variant', 'is_default', 'impressions', 'clicks'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    /**
     * Click-through rate as a decimal in [0, 1]. Returns null when
     * impressions are too low to compute reliably.
     */
    public function ctr(): ?float
    {
        if ($this->impressions < 50) {
            return null;
        }
        return round($this->clicks / $this->impressions, 4);
    }
}
