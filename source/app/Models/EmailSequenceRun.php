<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSequenceRun extends Model
{
    protected $table = 'email_sequence_runs';

    protected $fillable = [
        'sequence_id', 'user_id', 'email', 'current_step',
        'started_at', 'last_sent_at', 'completed_at', 'paused_at',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'started_at'   => 'datetime',
        'last_sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'paused_at'    => 'datetime',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(EmailSequence::class, 'sequence_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('completed_at')->whereNull('paused_at');
    }
}
