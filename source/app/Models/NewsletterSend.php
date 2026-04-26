<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per recipient × issue. Webhook receivers update the
 * counters + status lifecycle when the mail provider reports back.
 *
 * provider_message_id is the linkage — Postmark's X-PM-Message-Id
 * or Mailgun's message-id — set when the send is dispatched. The
 * webhook handler looks it up to find the matching row.
 */
class NewsletterSend extends Model
{
    protected $table = 'newsletter_sends';

    protected $fillable = [
        'issue_id', 'subscription_id', 'email',
        'provider', 'provider_message_id', 'status',
        'subject_variant_index',
        'opens_count', 'clicks_count',
        'first_opened_at', 'last_event_at',
        'sent_at', 'delivered_at', 'bounced_at',
        'complained_at', 'unsubscribed_at',
        'error_message',
    ];

    protected $casts = [
        'first_opened_at' => 'datetime',
        'last_event_at'   => 'datetime',
        'sent_at'         => 'datetime',
        'delivered_at'    => 'datetime',
        'bounced_at'      => 'datetime',
        'complained_at'   => 'datetime',
        'unsubscribed_at' => 'datetime',
        'opens_count'     => 'integer',
        'clicks_count'    => 'integer',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(NewsletterIssue::class, 'issue_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }
}
