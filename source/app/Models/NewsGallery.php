<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Secondary images attached to a News article (above-the-fold lead image
 * lives on the news row itself; gallery images supplement).
 */
class NewsGallery extends Model
{
    protected $table = 'news_gallery';

    public $timestamps = false;

    protected $fillable = ['news_id', 'image'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
