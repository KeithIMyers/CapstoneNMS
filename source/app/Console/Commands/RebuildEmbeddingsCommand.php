<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Services\Ai\ArticleIndexer;
use Illuminate\Console\Command;

/**
 * Walks published articles and refreshes their embeddings. Idempotent
 * — content_hash gating means re-running this is essentially free
 * after the first pass.
 *
 * Suggested CRON cadence: every 30 minutes. New articles get indexed
 * by the next pass; edits trigger a re-embed automatically because
 * the body change shifts the hash.
 *
 *   /usr/local/php83/bin/php /path/to/install/artisan embeddings:rebuild
 */
class RebuildEmbeddingsCommand extends Command
{
    protected $signature = 'embeddings:rebuild
                            {--id= : Only re-index a single article by id}
                            {--quiet-progress : Suppress per-article output}';

    protected $description = 'Generate / refresh embedding vectors for published articles';

    public function handle(ArticleIndexer $indexer): int
    {
        if ($id = $this->option('id')) {
            $article = News::find((int) $id);
            if (! $article) {
                $this->error("No article with id {$id}");
                return self::FAILURE;
            }
            $result = $indexer->indexArticle($article);
            $this->info("Article {$id}: {$result}");
            return self::SUCCESS;
        }

        $quiet = (bool) $this->option('quiet-progress');
        $stats = $indexer->indexAll($quiet ? null : function (array $row) {
            $this->line(sprintf('  %d → %s', $row['news_id'], $row['status']));
        });

        $this->info(sprintf(
            'Done. indexed=%d · skipped=%d · errors=%d',
            $stats['indexed'], $stats['skipped'], $stats['errors'],
        ));

        return $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
