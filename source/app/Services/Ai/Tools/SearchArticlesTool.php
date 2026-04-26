<?php

namespace App\Services\Ai\Tools;

use App\Models\News;

/**
 * Find published articles by keyword. The model uses this to ground its
 * answers in the newsroom's own coverage rather than guessing. Returns
 * a numbered list of "{id} | {title} | {published_at}" lines so the
 * model can pick an id and call ReadArticleTool next.
 */
class SearchArticlesTool implements Tool
{
    public function key(): string { return 'search_articles'; }

    public function description(): string
    {
        return 'Search published articles by keyword. Returns up to 10 matching articles with id, title, and publication date.';
    }

    public function arguments(): array
    {
        return [
            'query' => 'string — keyword(s) to match against title and excerpt',
            'limit' => 'integer (optional) — max rows to return (default 10, max 25)',
        ];
    }

    public function execute(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return 'ERROR: query is required';
        }
        $limit = max(1, min(25, (int) ($args['limit'] ?? 10)));

        $rows = News::published()
            ->where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('excerpt', 'LIKE', "%{$query}%");
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'published_at']);

        if ($rows->isEmpty()) {
            return "No published articles match \"{$query}\".";
        }

        $lines = $rows->map(fn ($r) => sprintf(
            '%d | %s | %s',
            $r->id,
            str_replace("\n", ' ', (string) $r->title),
            optional($r->published_at)->toDateString() ?? 'unscheduled',
        ));
        return $lines->implode("\n");
    }
}
