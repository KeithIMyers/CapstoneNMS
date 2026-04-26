<?php

namespace App\Services\Ai\Tools;

use App\Models\AiProvider;
use App\Models\ArticleEmbedding;
use App\Services\Ai\EmbeddingClient;

/**
 * Find articles whose embedded content is semantically close to a
 * query. Powered by the article_embeddings index built by the
 * embeddings:rebuild CRON command. Cosine similarity computed in PHP
 * — fine up to ~10k articles on shared hosting.
 *
 * Falls through to a clear error string if no embedding-capable
 * provider is configured, so the calling agent can switch to the
 * cheaper find_similar_articles tool on the next iteration.
 */
class SemanticSearchTool implements Tool
{
    public function key(): string { return 'semantic_search'; }

    public function description(): string
    {
        return 'Semantic search over published articles using embeddings. Returns articles ranked by meaning-overlap with the query. Use when keyword search is too brittle (synonyms, paraphrasing).';
    }

    public function arguments(): array
    {
        return [
            'query' => 'string — the question, claim, or paragraph to match',
            'limit' => 'integer (optional) — rows to return (default 8, max 20)',
        ];
    }

    public function execute(array $args): string
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') return 'ERROR: query is required';
        $limit = max(1, min(20, (int) ($args['limit'] ?? 8)));

        $provider = AiProvider::defaultEmbeddingProvider();
        if (! $provider) {
            return 'ERROR: no embedding-capable provider configured. Use find_similar_articles or search_articles instead.';
        }

        try {
            $resp = app(EmbeddingClient::class)->embed($query, $provider, purpose: 'embedding.search');
        } catch (\Throwable $e) {
            return 'ERROR: embedding query failed: '.\Illuminate\Support\Str::limit($e->getMessage(), 240);
        }
        $queryVec = $resp->vector;
        if (empty($queryVec)) return 'ERROR: provider returned an empty vector';

        // Pull every embedding for the active model. With ~10k rows and
        // ~1500 dims this is well under a hundred MB; for bigger indexes
        // we'd need pagination + a partial top-k pass.
        $rows = ArticleEmbedding::query()
            ->where('model', $provider->embedding_model)
            ->get(['news_id', 'vector']);

        if ($rows->isEmpty()) {
            return 'No embeddings indexed yet. Run `php artisan embeddings:rebuild`.';
        }

        $queryNorm = $this->norm($queryVec);
        if ($queryNorm <= 0.0) return 'ERROR: zero-magnitude query vector';

        $scored = [];
        foreach ($rows as $row) {
            $vec = $row->vector;
            if (count($vec) !== count($queryVec)) continue; // dim mismatch from a model swap
            $scored[] = [
                'news_id' => $row->news_id,
                'score'   => $this->cosine($queryVec, $queryNorm, $vec),
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);

        if (empty($top)) return 'No comparable embeddings (model dimension mismatch?).';

        // Hydrate titles in one query keyed by id.
        $ids = array_column($top, 'news_id');
        $titles = \App\Models\News::whereIn('id', $ids)
            ->get(['id', 'title', 'slug', 'published_at'])
            ->keyBy('id');

        return collect($top)->map(function ($r) use ($titles) {
            $a = $titles[$r['news_id']] ?? null;
            return $a
                ? sprintf('%d | sim=%.3f | %s | %s',
                    $a->id,
                    $r['score'],
                    optional($a->published_at)->toDateString() ?? '—',
                    \Illuminate\Support\Str::limit((string) $a->title, 80))
                : sprintf('%d | sim=%.3f | (article not found)', $r['news_id'], $r['score']);
        })->implode("\n");
    }

    private function cosine(array $a, float $aNorm, array $b): float
    {
        $dot = 0.0;
        $bSq = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $bSq += $b[$i] * $b[$i];
        }
        $bNorm = sqrt($bSq);
        if ($bNorm <= 0.0) return 0.0;
        return $dot / ($aNorm * $bNorm);
    }

    private function norm(array $v): float
    {
        $sq = 0.0;
        foreach ($v as $x) $sq += $x * $x;
        return sqrt($sq);
    }
}
