<?php

namespace App\Services\Ai\Tools;

use App\Models\News;
use App\Models\Revision;

/**
 * Append a public correction / clarification / update to an article's
 * revisions log. Wires into the existing P1-Phase-A revisions feature
 * so AI-suggested corrections appear at the foot of the article exactly
 * the same way human-authored ones do.
 *
 * The agent is the editor of record — auth()->id() is null when this
 * runs from CRON, so we leave editor_id null in that case and the
 * revisions UI shows "system" as the author.
 */
class AppendCorrectionTool implements Tool
{
    public function key(): string { return 'append_correction'; }

    public function description(): string
    {
        return 'Append a public correction, clarification, or update note to an article. Visible at the foot of the article page after publish.';
    }

    public function arguments(): array
    {
        return [
            'article_id' => 'integer — article id from search_articles',
            'kind'       => 'string — one of: update, correction, clarification (default update)',
            'note'       => 'string — the public-facing note (under 2000 chars)',
        ];
    }

    public function execute(array $args): string
    {
        $articleId = (int) ($args['article_id'] ?? 0);
        if ($articleId <= 0) return 'ERROR: article_id is required';

        $kind = strtolower(trim((string) ($args['kind'] ?? 'update')));
        $validKinds = [Revision::KIND_UPDATE, Revision::KIND_CORRECTION, Revision::KIND_CLARIFICATION];
        if (! in_array($kind, $validKinds, true)) {
            return 'ERROR: kind must be one of: '.implode(', ', $validKinds);
        }

        $note = trim((string) ($args['note'] ?? ''));
        if ($note === '') return 'ERROR: note is required';
        if (mb_strlen($note) > 2000) return 'ERROR: note must be 2000 characters or fewer';

        $article = News::find($articleId);
        if (! $article) return "ERROR: no article with id {$articleId}";

        $rev = Revision::create([
            'news_id'   => $article->id,
            'editor_id' => optional(auth()->user())->id,
            'kind'      => $kind,
            'note'      => $note,
        ]);

        return sprintf('OK | revision_id=%d | article_id=%d | kind=%s', $rev->id, $article->id, $kind);
    }
}
