<?php

namespace App\Services\Ai\Tools;

use App\Models\News;
use Illuminate\Support\Str;

/**
 * Surface articles that look similar to a candidate, by keyword overlap
 * with title + excerpt. Used by dedup-style agents that want to flag
 * "did we already cover this?" before drafting.
 *
 * Intentionally a cheap keyword approach (Jaccard over title+excerpt
 * tokens). Real semantic similarity would need an embedding model or
 * a vector index — those can come later as a separate tool when the
 * newsroom outgrows this.
 */
class FindSimilarArticlesTool implements Tool
{
    public function key(): string { return 'find_similar_articles'; }

    public function description(): string
    {
        return 'Find published articles whose title or excerpt looks similar to a candidate text. Cheap keyword overlap, not semantic. Use to flag potential duplicates before drafting a new piece.';
    }

    public function arguments(): array
    {
        return [
            'text'  => 'string — the candidate headline or paragraph to compare against',
            'limit' => 'integer (optional) — max rows to return (default 5, max 15)',
        ];
    }

    public function execute(array $args): string
    {
        $text = trim((string) ($args['text'] ?? ''));
        if ($text === '') {
            return 'ERROR: text is required';
        }
        $limit = max(1, min(15, (int) ($args['limit'] ?? 5)));

        $tokens = $this->tokens($text);
        if (empty($tokens)) {
            return 'No usable keywords in the supplied text.';
        }

        // Pull candidate articles whose title OR excerpt contains any of
        // the top-3 tokens — keeps the SQL bounded — then score in PHP.
        $candidates = News::published()
            ->where(function ($q) use ($tokens) {
                $top = array_slice($tokens, 0, 3);
                foreach ($top as $tok) {
                    $q->orWhere('title', 'LIKE', "%{$tok}%")
                      ->orWhere('excerpt', 'LIKE', "%{$tok}%");
                }
            })
            ->orderByDesc('published_at')
            ->limit(200)
            ->get(['id', 'title', 'excerpt', 'slug', 'published_at']);

        if ($candidates->isEmpty()) {
            return 'No similar articles found.';
        }

        $scored = $candidates
            ->map(function ($a) use ($tokens) {
                $combined = $this->tokens($a->title.' '.$a->excerpt);
                $intersect = count(array_intersect($combined, $tokens));
                $union     = max(1, count(array_unique(array_merge($combined, $tokens))));
                return ['article' => $a, 'score' => round($intersect / $union, 3)];
            })
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        if ($scored->isEmpty()) {
            return 'No similar articles found above zero overlap.';
        }

        return $scored->map(fn ($r) => sprintf(
            '%d | sim=%.2f | %s | %s',
            $r['article']->id,
            $r['score'],
            optional($r['article']->published_at)->toDateString() ?? '—',
            Str::limit((string) $r['article']->title, 80),
        ))->implode("\n");
    }

    /** Lowercase, alpha-only tokens, length>=4, deduped. */
    private function tokens(string $s): array
    {
        $s = strtolower(strip_tags($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
        $stop = array_flip(['the', 'and', 'with', 'that', 'this', 'from', 'have',
            'will', 'what', 'when', 'were', 'their', 'would', 'about', 'after', 'into',
            'just', 'than', 'them', 'they', 'said', 'over', 'under']);
        $tokens = array_values(array_filter(
            array_unique(preg_split('/\s+/', trim($s)) ?: []),
            fn ($t) => strlen($t) >= 4 && ! isset($stop[$t]),
        ));
        return $tokens;
    }
}
