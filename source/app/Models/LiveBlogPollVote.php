<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveBlogPollVote extends Model
{
    public $timestamps = false;
    protected $table = 'live_blog_poll_votes';

    protected $fillable = [
        'poll_id', 'choice_id', 'voter_hash', 'ip_hash', 'created_at',
    ];

    protected $casts = [
        'choice_id'  => 'integer',
        'created_at' => 'datetime',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LiveBlogPoll::class, 'poll_id');
    }
}
