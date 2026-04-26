<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User-submitted abuse / inaccuracy reports about an article.
 */
class Reports extends Model
{
    protected $table = 'reports';

    public $timestamps = false;

    protected $fillable = ['user_id', 'post_id', 'message', 'date'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(News::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
