<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReadingListItem extends Model
{
    protected $table = 'reading_list_items';

    protected $fillable = [
        'reading_list_id', 'news_id', 'note', 'added_at',
    ];

    protected $casts = [
        'added_at' => 'datetime',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(ReadingList::class, 'reading_list_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }
}
