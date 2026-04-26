<?php

namespace App\Services\Ai\Tools;

use App\Models\News;

/**
 * Set image_alt on an article that doesn't currently have one. Refuses
 * to overwrite existing alt text — the agent has to flag those for
 * human review rather than silently rewriting them. Keeps the
 * human-in-the-loop principle intact.
 */
class SetAltTextTool implements Tool
{
    public function key(): string { return 'set_alt_text'; }

    public function description(): string
    {
        return 'Set image_alt on an article that currently has none. Refuses to overwrite an existing value — flag those cases instead.';
    }

    public function arguments(): array
    {
        return [
            'article_id' => 'integer — article id from list_missing_alt_text',
            'alt'        => 'string — the alt text (under 250 chars, no "image of"/"photo of" filler)',
        ];
    }

    public function execute(array $args): string
    {
        $id  = (int) ($args['article_id'] ?? 0);
        $alt = trim((string) ($args['alt'] ?? ''));

        if ($id <= 0)         return 'ERROR: article_id is required';
        if ($alt === '')      return 'ERROR: alt is required';
        if (mb_strlen($alt) > 250) return 'ERROR: alt must be 250 characters or fewer';

        $article = News::find($id);
        if (! $article)       return "ERROR: no article with id {$id}";
        if (empty($article->image)) return "ERROR: article {$id} has no lead image";
        if (! empty(trim((string) $article->image_alt))) {
            return "ERROR: article {$id} already has alt text — leave existing values alone";
        }

        $article->forceFill(['image_alt' => $alt])->save();

        return sprintf('OK | article_id=%d | alt_chars=%d', $id, mb_strlen($alt));
    }
}
