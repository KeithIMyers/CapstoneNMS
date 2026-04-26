<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveBlogEntry extends Model
{
    protected $fillable = [
        'live_blog_id', 'posted_by_user_id', 'headline', 'body',
        'is_pinned', 'posted_at',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            if (empty($entry->posted_at)) {
                $entry->posted_at = now();
            }
            if (empty($entry->posted_by_user_id) && auth()->check()) {
                $entry->posted_by_user_id = auth()->id();
            }
        });
    }

    public function liveBlog(): BelongsTo
    {
        return $this->belongsTo(LiveBlog::class, 'live_blog_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
