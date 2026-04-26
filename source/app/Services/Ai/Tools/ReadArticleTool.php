<?php

namespace App\Services\Ai\Tools;

use App\Models\News;

/**
 * Fetch the full body of a single article by id. Strips HTML so the
 * model sees prose, not markup. Truncates very long articles so a
 * single observation doesn't blow the context window.
 */
class ReadArticleTool implements Tool
{
    private const BODY_CHAR_LIMIT = 8000;

    public function key(): string { return 'read_article'; }

    public function description(): string
    {
        return 'Read the full body of an article by id. Use after search_articles to ground claims in actual coverage.';
    }

    public function arguments(): array
    {
        return [
            'id' => 'integer — article id from search_articles',
        ];
    }

    public function execute(array $args): string
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return 'ERROR: id is required and must be a positive integer';
        }
        $article = News::find($id);
        if (! $article) {
            return "ERROR: no article with id {$id}";
        }

        $body = trim(strip_tags((string) $article->content));
        if (mb_strlen($body) > self::BODY_CHAR_LIMIT) {
            $body = mb_substr($body, 0, self::BODY_CHAR_LIMIT)."\n[…truncated]";
        }

        return implode("\n", [
            "ID: {$article->id}",
            "Title: {$article->title}",
            "Slug: {$article->slug}",
            'Published: '.(optional($article->published_at)->toDateString() ?? 'unscheduled'),
            'Locale: '.($article->locale ?? 'en'),
            'Excerpt: '.trim((string) $article->excerpt),
            '',
            'Body:',
            $body,
        ]);
    }
}
