<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Editorially curated landing page that combines a manual list of pinned
 * articles with an auto-include filter (category, tags, keyword). Useful
 * for ongoing coverage that doesn't fit a single category — e.g. an
 * election, a war, a long-running investigation.
 */
class Topic extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'hero_image',
        'is_active', 'sort', 'auto_rules',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_rules' => 'array',
    ];

    public function manualArticles(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'topic_news', 'topic_id', 'news_id')
            ->withPivot('sort')
            ->orderBy('topic_news.sort');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Combined feed of pinned articles + auto-included articles, deduped,
     * with pinned items appearing first in the order set on the pivot.
     */
    public function articles(int $limit = 30): \Illuminate\Support\Collection
    {
        $manual = $this->manualArticles()
            ->published()
            ->with(['category', 'user'])
            ->limit($limit)
            ->get();

        $auto = collect();
        $rules = $this->auto_rules ?? [];

        if (! empty($rules['categories']) || ! empty($rules['tags']) || ! empty($rules['keyword'])) {
            $auto = News::published()
                ->with(['category', 'user'])
                ->when(! empty($rules['categories']), function ($q) use ($rules) {
                    $q->whereIn('category_id', $rules['categories']);
                })
                ->when(! empty($rules['tags']), function ($q) use ($rules) {
                    $q->whereHas('tagsRelation', fn ($qq) => $qq->whereIn('tags.id', $rules['tags']));
                })
                ->when(! empty($rules['keyword']), function ($q) use ($rules) {
                    $kw = $rules['keyword'];
                    $q->where(function ($qq) use ($kw) {
                        $qq->where('title', 'LIKE', "%{$kw}%")
                            ->orWhere('excerpt', 'LIKE', "%{$kw}%");
                    });
                })
                ->orderByDesc('published_at')
                ->limit($limit)
                ->get();
        }

        // Manual first, then auto, dedupe by id.
        return $manual->merge($auto)->unique('id')->values();
    }
}
