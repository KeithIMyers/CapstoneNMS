<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One embedding vector per (article, model) pair. Vector is stored as
 * a JSON array of floats so MySQL doesn't need a vector extension.
 *
 * Cosine similarity is computed in PHP at query time — bounded by
 * pulling rows from this table (one query) into memory and dotting them
 * against the query vector. Comfortable up to ~10k articles on shared
 * hosting; beyond that we'd want a real vector index.
 */
class ArticleEmbedding extends Model
{
    protected $table = 'article_embeddings';

    protected $fillable = [
        'news_id', 'model', 'dimensions', 'vector', 'content_hash',
    ];

    protected $casts = [
        'dimensions' => 'integer',
    ];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class, 'news_id');
    }

    /**
     * The vector column round-trips as a PHP array of floats. We keep
     * the on-disk format JSON (rather than e.g. base64 of pack()'d
     * floats) so future tooling — psql dumps, Datasette, jq queries —
     * can read it without bespoke decoders.
     */
    protected function vector(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                if (! $value) return [];
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            },
            set: function ($value): string {
                if (is_string($value)) return $value;
                return json_encode(array_values((array) $value), JSON_UNESCAPED_SLASHES);
            },
        );
    }
}
