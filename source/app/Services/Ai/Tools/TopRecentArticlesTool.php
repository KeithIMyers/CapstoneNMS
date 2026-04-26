<?php

namespace App\Services\Ai\Tools;

use App\Models\News;

/**
 * Surface the most-viewed recently-published articles. Useful for
 * agents that compose newsletters, social posts, or trend recaps.
 *
 * Returns "id | title | views | published_at | excerpt" lines so the
 * model can pick a few and call read_article on each for full body.
 */
class TopRecentArticlesTool implements Tool
{
    public function key(): string { return 'top_recent_articles'; }

    public function description(): string
    {
        return 'List the top articles published in the last N hours, ordered by view count. Use to find newsworthy stories for newsletters, recaps, or social posts.';
    }

    public function arguments(): array
    {
        return [
            'hours_back' => 'integer (optional) — window size in hours, default 24, max 168',
            'limit'      => 'integer (optional) — rows to return, default 8, max 25',
        ];
    }

    public function execute(array $args): string
    {
        $hours = max(1, min(168, (int) ($args['hours_back'] ?? 24)));
        $limit = max(1, min(25, (int) ($args['limit'] ?? 8)));

        $rows = News::published()
            ->where('published_at', '>=', now()->subHours($hours))
            ->orderByDesc('views')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'excerpt', 'views', 'published_at']);

        if ($rows->isEmpty()) {
            return "No published articles in the last {$hours} hours.";
        }

        return $rows->map(fn ($r) => sprintf(
            '%d | %s | %s views | %s | %s',
            $r->id,
            str_replace("\n", ' ', (string) $r->title),
            number_format((int) $r->views),
            optional($r->published_at)->toDateTimeString() ?? '—',
            \Illuminate\Support\Str::limit((string) $r->excerpt, 140),
        ))->implode("\n");
    }
}
