<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comments extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SPAM = 'spam';
    public const STATUS_TRASH = 'trash';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_SPAM,
        self::STATUS_TRASH,
    ];

    public const VERDICT_ALLOW  = 'allow';
    public const VERDICT_REVIEW = 'review';
    public const VERDICT_REJECT = 'reject';

    public const VERDICTS = [
        self::VERDICT_ALLOW  => 'Allow',
        self::VERDICT_REVIEW => 'Review',
        self::VERDICT_REJECT => 'Reject',
    ];

    protected $fillable = [
        'post_id', 'user_id', 'parent_comment_id', 'content', 'status',
        'guest_name', 'guest_email', 'ip_address',
        'ai_verdict', 'ai_reason', 'ai_verdict_at',
    ];

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function authorDisplayName(): string
    {
        if ($this->user) return (string) $this->user->name;
        return trim((string) $this->guest_name) !== '' ? (string) $this->guest_name : 'Anonymous';
    }

    protected $casts = [
        'ai_verdict_at' => 'datetime',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(News::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(CommentLike::class, 'comment_id');
    }

    public function likesCount(): int
    {
        return (int) $this->likes()->count();
    }

    public function isLikedBy(?User $user): bool
    {
        if (! $user) return false;
        return $this->likes()->where('user_id', $user->id)->exists();
    }
}
