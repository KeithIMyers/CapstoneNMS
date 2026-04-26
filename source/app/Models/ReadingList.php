<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Per-user collection of articles. Each user gets a default list
 * ("Saved") on first save; they can create more via the profile
 * page.
 */
class ReadingList extends Model
{
    protected $table = 'reading_lists';

    protected $fillable = [
        'user_id', 'name', 'slug', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReadingListItem::class, 'reading_list_id')->latest('added_at');
    }

    /** Get or create the user's default "Saved" list. */
    public static function defaultFor(User $user): self
    {
        return self::firstOrCreate(
            ['user_id' => $user->id, 'is_default' => true],
            ['name' => 'Saved', 'slug' => 'saved'],
        );
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            if (empty($row->slug)) {
                $row->slug = Str::slug($row->name) ?: 'list-'.Str::lower(Str::random(4));
            }
        });
    }
}
