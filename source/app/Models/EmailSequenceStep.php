<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSequenceStep extends Model
{
    protected $table = 'email_sequence_steps';

    protected $fillable = [
        'sequence_id', 'sort_order', 'delay_hours', 'subject', 'body_markdown',
    ];

    protected $casts = [
        'sort_order'  => 'integer',
        'delay_hours' => 'integer',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(EmailSequence::class, 'sequence_id');
    }
}
