<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Multi-part investigation / explainer / running coverage. Articles
 * point at a series via news.series_id; sort_in_series controls the
 * ordering on the public series landing page.
 */
class ArticleSeries extends Model
{
    protected $table = 'article_series';

    protected $fillable = [
        'name', 'slug', 'description', 'hero_image',
        'is_active', 'sort',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort'      => 'integer',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(News::class, 'series_id')
            ->orderBy('sort_in_series')
            ->orderBy('published_at');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
