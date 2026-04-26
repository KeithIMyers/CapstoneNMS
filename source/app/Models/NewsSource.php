<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-article source citation. Rendered in a "Sources" block at the foot
 * of the article. Useful for fact-check journalism where each claim needs
 * traceable provenance.
 */
class NewsSource extends Model
{
    protected $table = 'news_sources';

    protected $fillable = ['news_id', 'label', 'url', 'publisher', 'sort'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
