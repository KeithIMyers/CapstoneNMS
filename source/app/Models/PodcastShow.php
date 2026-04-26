<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A podcast show (channel). Holds the metadata required to generate a
 * working iTunes / Apple Podcasts RSS feed — episodes are attached below.
 */
class PodcastShow extends Model
{
    protected $table = 'podcast_shows';

    protected $fillable = [
        'title', 'slug', 'description', 'author',
        'owner_name', 'owner_email', 'language',
        'itunes_category', 'itunes_subcategory',
        'artwork_path', 'explicit', 'is_active',
    ];

    protected $casts = [
        'explicit'  => 'boolean',
        'is_active' => 'boolean',
    ];

    public function episodes(): HasMany
    {
        return $this->hasMany(PodcastEpisode::class, 'show_id');
    }

    public function publishedEpisodes(): HasMany
    {
        return $this->episodes()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
