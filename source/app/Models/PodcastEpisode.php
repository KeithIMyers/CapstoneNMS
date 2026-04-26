<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One podcast episode. media_url is an absolute URL to the audio file
 * (the admin uploads to the public disk; we store the storage URL). The
 * RSS feed exposes duration_seconds formatted HH:MM:SS.
 */
class PodcastEpisode extends Model
{
    protected $table = 'podcast_episodes';

    public const TYPES = [
        'full'    => 'Full episode',
        'trailer' => 'Trailer',
        'bonus'   => 'Bonus',
    ];

    protected $fillable = [
        'show_id', 'title', 'slug', 'description', 'show_notes',
        'media_url', 'media_size_bytes', 'media_mime', 'duration_seconds',
        'season_number', 'episode_number', 'episode_type',
        'explicit', 'published_at',
    ];

    protected $casts = [
        'explicit'     => 'boolean',
        'published_at' => 'datetime',
    ];

    public function show(): BelongsTo
    {
        return $this->belongsTo(PodcastShow::class, 'show_id');
    }

    public function durationFormatted(): string
    {
        $d = (int) ($this->duration_seconds ?? 0);
        $h = intdiv($d, 3600);
        $m = intdiv($d % 3600, 60);
        $s = $d % 60;
        return $h > 0
            ? sprintf('%02d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
    }
}
