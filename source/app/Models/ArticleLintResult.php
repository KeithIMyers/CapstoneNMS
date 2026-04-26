<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per article holding the latest AI lint pass. Findings store
 * the parsed JSON array (severity + category + message + fix per item);
 * counts duplicate the per-severity tallies for cheap dashboard
 * queries without unpacking the JSON.
 *
 * Updated-by-CRON via articles:lint-recent and updated-by-form when an
 * editor runs lint from the article edit page.
 */
class ArticleLintResult extends Model
{
    public $timestamps = false;
    protected $table = 'article_lint_results';

    protected $fillable = [
        'news_id', 'findings', 'critical_count', 'warn_count', 'info_count',
        'model', 'ran_at',
    ];

    protected $casts = [
        'findings'       => 'array',
        'critical_count' => 'integer',
        'warn_count'     => 'integer',
        'info_count'     => 'integer',
        'ran_at'         => 'datetime',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
