<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-use sign-in token row. Created when a visitor requests a
 * magic link; consumed when they click through. `token_hash` is the
 * sha256 of the plaintext token included in the email — we never
 * store the plaintext.
 */
class MagicLink extends Model
{
    public $timestamps = false;
    protected $table = 'magic_links';

    protected $fillable = [
        'email', 'token_hash',
        'expires_at', 'used_at',
        'request_ip', 'request_ua',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
        'created_at' => 'datetime',
    ];
}
