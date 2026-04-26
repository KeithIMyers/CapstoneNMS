<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'status', 'cat_order', 'parent_id'];

    public function posts(): HasMany
    {
        return $this->hasMany(News::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('cat_order');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    /**
     * Recursive descendant ID list — for "show all articles in a section
     * including its sub-sections" queries. Cached at the request level so
     * a single page render with multiple category boxes doesn't re-walk.
     */
    public function descendantIds(): array
    {
        static $cache = [];
        if (isset($cache[$this->id])) {
            return $cache[$this->id];
        }

        $ids = [$this->id];
        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->descendantIds());
        }

        return $cache[$this->id] = $ids;
    }

    /**
     * Build a tree structure useful for nav menus / nested admin selects.
     */
    public static function tree(): Collection
    {
        return self::active()->orderBy('cat_order')
            ->with('children')
            ->roots()
            ->get();
    }

    public function depth(): int
    {
        $depth = 0;
        $node = $this;
        while ($node->parent) {
            $depth++;
            $node = $node->parent;
            if ($depth > 10) break; // sanity cap
        }
        return $depth;
    }
}
