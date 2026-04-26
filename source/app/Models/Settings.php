<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic key/value settings store. Values are read everywhere via the
 * getcong() helper, which caches the entire table per request.
 */
class Settings extends Model
{
    protected $fillable = ['key', 'value'];
}
