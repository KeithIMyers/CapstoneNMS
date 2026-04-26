<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailSequence extends Model
{
    public const TRIGGER_SUBSCRIBER_CONFIRMED = 'subscriber_confirmed';
    public const TRIGGER_USER_SIGNED_UP       = 'user_signed_up';
    public const TRIGGER_MANUAL               = 'manual';

    public const TRIGGERS = [
        self::TRIGGER_SUBSCRIBER_CONFIRMED,
        self::TRIGGER_USER_SIGNED_UP,
        self::TRIGGER_MANUAL,
    ];

    protected $table = 'email_sequences';

    protected $fillable = [
        'slug', 'name', 'description', 'trigger_event', 'active',
    ];

    protected $casts = ['active' => 'boolean'];

    public function steps(): HasMany
    {
        return $this->hasMany(EmailSequenceStep::class, 'sequence_id')->orderBy('sort_order');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EmailSequenceRun::class, 'sequence_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** All active sequences listening for a particular event. */
    public static function listeningFor(string $event): \Illuminate\Support\Collection
    {
        return static::active()->where('trigger_event', $event)->get();
    }
}
