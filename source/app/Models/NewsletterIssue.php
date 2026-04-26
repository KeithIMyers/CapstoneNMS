<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One newsletter blast — daily brief, weekly roundup, ad-hoc, etc.
 * Per-recipient lives in newsletter_sends; aggregate counters live
 * here for fast list-view rendering.
 */
class NewsletterIssue extends Model
{
    protected $table = 'newsletter_issues';

    protected $fillable = [
        'product_id', 'subject', 'subject_variants', 'sender_email', 'body_summary',
        'created_by_user_id', 'queued_count', 'sent_count', 'failed_count',
        'sent_at',
    ];

    protected $casts = [
        'sent_at'          => 'datetime',
        'queued_count'     => 'integer',
        'sent_count'       => 'integer',
        'failed_count'     => 'integer',
        'subject_variants' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(NewsletterProduct::class, 'product_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(NewsletterSend::class, 'issue_id');
    }
}
