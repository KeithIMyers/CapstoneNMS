<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    protected static function booted(): void
    {
        static::saving(function (self $tag) {
            if (empty($tag->slug)) {
                $tag->slug = self::uniqueSlug($tag->name);
            }
        });
    }

    public function news(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'news_tag', 'tag_id', 'news_id');
    }

    public static function findOrCreateByName(string $name): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Tag name cannot be empty.');
        }

        $slug = Str::slug($name);
        $existing = self::where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'name' => $name,
            'slug' => self::uniqueSlug($name),
        ]);
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base ?: 'tag';
        $i = 1;
        while (self::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }
}
