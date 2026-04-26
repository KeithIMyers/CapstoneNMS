<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingHistory extends Model
{
    protected $table = 'reading_history';

    protected $fillable = [
        'user_id', 'news_id', 'last_read_at', 'scroll_depth_pct', 'read_count',
    ];

    protected $casts = [
        'last_read_at'     => 'datetime',
        'scroll_depth_pct' => 'integer',
        'read_count'       => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    /**
     * Upsert convenience: bump last_read_at + read_count for the
     * given user × article. Called from NewsController::details on
     * authenticated reads. Intentionally cheap — a single
     * updateOrCreate per article view.
     */
    public static function record(int $userId, int $newsId): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'news_id' => $newsId],
            ['last_read_at' => now()],
        );
        static::where('user_id', $userId)
            ->where('news_id', $newsId)
            ->increment('read_count');
    }
}
