<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A configurable home-page block — admin picks a list of articles or a query
 * type and the homepage iterates over them. Kept lean; the post_ids column
 * is a CSV the admin form populates.
 */
class HomeSections extends Model
{
    protected $table = 'home_sections';

    protected $fillable = [
        'section_name',
        'post_type',
        'post_ids',
        'display_style',
        'section_order',
        'status',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
