<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editorial assignment / pitch row. Owned by editors+, claimable by
 * authors, completed by linking a published article back via the
 * news_id column.
 */
class Assignment extends Model
{
    public const STATUS_PITCHED     = 'pitched';
    public const STATUS_ASSIGNED    = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED   = 'submitted';
    public const STATUS_PUBLISHED   = 'published';
    public const STATUS_ARCHIVED    = 'archived';

    public const STATUSES = [
        self::STATUS_PITCHED, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED,
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $table = 'assignments';

    protected $fillable = [
        'title', 'brief', 'status', 'priority',
        'assigned_to_user_id', 'created_by_user_id',
        'category_id', 'news_id',
        'deadline', 'claimed_at', 'submitted_at',
    ];

    protected $casts = [
        'deadline'     => 'datetime',
        'claimed_at'   => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNotIn('status', [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED]);
    }
}
