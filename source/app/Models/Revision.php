<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editor-authored corrections / updates / clarifications log entry on a
 * news article. Rendered at the bottom of the article page so readers can
 * see when a piece was updated and what changed, NYT/AP style.
 */
class Revision extends Model
{
    protected $table = 'news_revisions';

    public const KIND_UPDATE = 'update';
    public const KIND_CORRECTION = 'correction';
    public const KIND_CLARIFICATION = 'clarification';

    public const KINDS = [self::KIND_UPDATE, self::KIND_CORRECTION, self::KIND_CLARIFICATION];

    protected $fillable = ['news_id', 'editor_id', 'kind', 'note'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::KIND_CORRECTION => 'Correction',
            self::KIND_CLARIFICATION => 'Clarification',
            default => 'Updated',
        };
    }
}
