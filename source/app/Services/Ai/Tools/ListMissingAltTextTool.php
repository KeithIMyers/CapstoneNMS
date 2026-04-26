<?php

namespace App\Services\Ai\Tools;

use App\Models\News;

/**
 * Surface published articles whose lead image lacks alt text. Pairs
 * with an audit-style agent that walks the list and fills each one in
 * — accessibility hygiene at scale.
 *
 * Excludes articles with no lead image (image column null/empty); alt
 * text only matters when there's an image to describe.
 */
class ListMissingAltTextTool implements Tool
{
    public function key(): string { return 'list_missing_alt_text'; }

    public function description(): string
    {
        return 'List published articles that have a lead image but no alt text. For accessibility audits.';
    }

    public function arguments(): array
    {
        return [
            'limit' => 'integer (optional) — max rows to return (default 20, max 100)',
        ];
    }

    public function execute(array $args): string
    {
        $limit = max(1, min(100, (int) ($args['limit'] ?? 20)));

        $rows = News::published()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->where(function ($q) {
                $q->whereNull('image_alt')->orWhere('image_alt', '');
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'title', 'image', 'image_caption', 'published_at']);

        if ($rows->isEmpty()) {
            return 'No published articles are missing alt text. Nice.';
        }

        return $rows->map(fn ($r) => sprintf(
            '%d | %s | image=%s | caption=%s',
            $r->id,
            \Illuminate\Support\Str::limit((string) $r->title, 60),
            (string) $r->image,
            \Illuminate\Support\Str::limit((string) $r->image_caption, 80),
        ))->implode("\n");
    }
}
