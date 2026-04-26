<?php

namespace App\Services\Ai\Tools;

use App\Models\News;
use App\Models\NewsSource;

/**
 * Append a citation to an article via the existing news_sources table
 * (Phase F). The Sources block at the foot of the article will pick up
 * the new entry automatically — no separate render path.
 *
 * Idempotency: refuses to add a source whose URL already exists on the
 * article, so an agent re-run on the same piece doesn't fan out into
 * duplicates.
 */
class AddSourceTool implements Tool
{
    public function key(): string { return 'add_source'; }

    public function description(): string
    {
        return 'Add a source citation to an article. Renders in the public "Sources" block. Refuses to add a duplicate URL.';
    }

    public function arguments(): array
    {
        return [
            'article_id' => 'integer — article id from search_articles',
            'label'      => 'string — short description of what this source substantiates (under 255 chars)',
            'url'        => 'string (optional) — public link to the source',
            'publisher'  => 'string (optional) — publisher / agency name (under 200 chars)',
        ];
    }

    public function execute(array $args): string
    {
        $articleId = (int) ($args['article_id'] ?? 0);
        $label     = trim((string) ($args['label'] ?? ''));
        $url       = trim((string) ($args['url'] ?? ''));
        $publisher = trim((string) ($args['publisher'] ?? ''));

        if ($articleId <= 0) return 'ERROR: article_id is required';
        if ($label === '') return 'ERROR: label is required';
        if (mb_strlen($label) > 255) return 'ERROR: label must be 255 characters or fewer';
        if (mb_strlen($publisher) > 200) return 'ERROR: publisher must be 200 characters or fewer';
        if ($url !== '' && ! preg_match('~^https?://~i', $url)) {
            return 'ERROR: url must start with http:// or https:// (or be omitted)';
        }

        if (! News::whereKey($articleId)->exists()) {
            return "ERROR: no article with id {$articleId}";
        }

        // Idempotency: skip if same URL already cited on this article.
        if ($url !== '' && NewsSource::where('news_id', $articleId)->where('url', $url)->exists()) {
            return "Skipped: a source with this URL is already cited on article {$articleId}.";
        }

        $sort = (int) NewsSource::where('news_id', $articleId)->max('sort') + 1;

        $source = NewsSource::create([
            'news_id'   => $articleId,
            'label'     => $label,
            'url'       => $url ?: null,
            'publisher' => $publisher ?: null,
            'sort'      => $sort,
        ]);

        return sprintf('OK | source_id=%d | article_id=%d', $source->id, $articleId);
    }
}
