<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveBlog extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CLOSED];

    protected $fillable = [
        'title', 'slug', 'summary', 'hero_image', 'status',
        'started_at', 'ended_at', 'created_by_user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LiveBlogEntry::class)->orderByDesc('posted_at');
    }

    public function polls(): HasMany
    {
        return $this->hasMany(LiveBlogPoll::class)->orderBy('sort_order')->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_CLOSED]);
    }
}
