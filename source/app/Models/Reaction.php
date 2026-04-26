<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reaction extends Model
{
    protected $fillable = ['user_id', 'news_id', 'type'];

    /**
     * Reaction taxonomy. Each entry is a keyword paired with an emoji and a
     * human label. Stays static so we don't need an additional table; the
     * `type` column on `reactions` holds the keyword.
     */
    private const CATALOG = [
        'like'     => ['emoji' => '👍', 'label' => 'Like'],
        'love'     => ['emoji' => '❤️', 'label' => 'Love'],
        'insight'  => ['emoji' => '💡', 'label' => 'Insightful'],
        'laugh'    => ['emoji' => '😂', 'label' => 'Funny'],
        'surprise' => ['emoji' => '😮', 'label' => 'Surprised'],
        'sad'      => ['emoji' => '😢', 'label' => 'Sad'],
        'angry'    => ['emoji' => '😠', 'label' => 'Angry'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    /**
     * List of reaction keywords, used by validators and view loops.
     */
    public static function getTypes(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function getEmoji(string $type): string
    {
        return self::CATALOG[$type]['emoji'] ?? '👍';
    }

    public static function getLabel(string $type): string
    {
        return self::CATALOG[$type]['label'] ?? ucfirst($type);
    }
}
