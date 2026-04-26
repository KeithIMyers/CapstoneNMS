<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\ArticleEmbedding;
use App\Models\News;

/**
 * Walks published articles and (re)generates their embeddings. Cheap to
 * run repeatedly — content_hash gates the actual API call so unchanged
 * articles are skipped after the first pass.
 *
 * Source text fed to the embedder: title + excerpt + plain-text body
 * (HTML stripped, capped at 6000 chars). Including title/excerpt boosts
 * quality on short articles where the body alone might be thin; the
 * char cap keeps a single embed call fast and on the cheap side of the
 * tokenizer.
 */
class ArticleIndexer
{
    private const SOURCE_CHAR_LIMIT = 6000;

    public function __construct(private readonly EmbeddingClient $client) {}

    /**
     * Embed (or refresh) one article. Returns 'indexed' on actual API
     * call, 'skipped' when the content_hash matched the stored row.
     */
    public function indexArticle(News $article): string
    {
        $provider = AiProvider::defaultEmbeddingProvider();
        if (! $provider) {
            return 'no_provider';
        }

        $sourceText = $this->sourceTextFor($article);
        $hash = hash('sha256', $sourceText);
        $model = $provider->embedding_model;

        $existing = ArticleEmbedding::where('news_id', $article->id)
            ->where('model', $model)
            ->first();

        if ($existing && $existing->content_hash === $hash) {
            return 'skipped';
        }

        $resp = $this->client->embed($sourceText, $provider, purpose: 'embedding.article');

        ArticleEmbedding::updateOrCreate(
            ['news_id' => $article->id, 'model' => $model],
            [
                'dimensions'   => $resp->dimensions(),
                'vector'       => $resp->vector,
                'content_hash' => $hash,
            ]
        );

        return 'indexed';
    }

    /**
     * Embed every published article that's missing an entry or whose
     * content_hash is stale. Closure receives ['news_id' => int, 'status'
     * => string] for each row processed so callers can stream progress.
     *
     * @return array{indexed:int, skipped:int, errors:int}
     */
    public function indexAll(?\Closure $onEach = null): array
    {
        $stats = ['indexed' => 0, 'skipped' => 0, 'errors' => 0];

        News::published()
            ->orderBy('id')
            ->chunk(50, function ($articles) use (&$stats, $onEach) {
                foreach ($articles as $a) {
                    try {
                        $result = $this->indexArticle($a);
                        $stats[$result] = ($stats[$result] ?? 0) + 1;
                        if ($onEach) $onEach(['news_id' => $a->id, 'status' => $result]);
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        if ($onEach) $onEach(['news_id' => $a->id, 'status' => 'error', 'error' => $e->getMessage()]);
                    }
                }
            });

        return $stats;
    }

    /** Build the canonical "what to embed" string from an article. */
    public function sourceTextFor(News $article): string
    {
        $body = trim(strip_tags((string) $article->content));
        $text = trim(implode("\n\n", array_filter([
            (string) $article->title,
            (string) $article->excerpt,
            $body,
        ])));
        if (mb_strlen($text) > self::SOURCE_CHAR_LIMIT) {
            $text = mb_substr($text, 0, self::SOURCE_CHAR_LIMIT);
        }
        return $text;
    }
}
