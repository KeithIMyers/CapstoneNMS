<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use gift link granting a non-subscriber full-body access to
 * one premium article. Issued by an active subscriber (the "gifter")
 * via the article-gift form; consumed by the recipient when they
 * follow the URL with the matching `?gift={token}` query string.
 */
class ArticleGift extends Model
{
    protected $table = 'article_gifts';

    protected $fillable = [
        'news_id', 'gifter_user_id', 'recipient_email',
        'token_hash', 'expires_at', 'redeemed_at', 'redeemed_ip',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    public function gifter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gifter_user_id');
    }
}
