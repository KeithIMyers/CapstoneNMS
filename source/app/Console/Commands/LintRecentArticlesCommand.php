<?php

namespace App\Console\Commands;

use App\Models\ArticleLintResult;
use App\Models\News;
use App\Services\Ai\ArticleLinter;
use Illuminate\Console\Command;

/**
 * Walk recently published / recently updated articles and (re)run the
 * article.lint prompt on each. Idempotent: skips articles whose
 * article_lint_results row is newer than the article's updated_at.
 *
 * Suggested cadence: once a day off-peak. Cost is ~1 short completion
 * per article without a recent lint, so a 50-article newsroom is well
 * under a dollar a day on cheap models.
 *
 *   /usr/local/php83/bin/php /path/to/install/artisan articles:lint-recent
 */
class LintRecentArticlesCommand extends Command
{
    protected $signature = 'articles:lint-recent
                            {--days=14 : Look back this many days for candidate articles}
                            {--limit=200 : Hard cap on articles processed in one invocation}
                            {--force : Re-lint even if the existing row is newer than updated_at}';

    protected $description = 'Run AI lint on recently-published or recently-edited articles, persisting findings to article_lint_results';

    public function handle(ArticleLinter $linter): int
    {
        $days  = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');

        $candidates = News::published()
            ->where('updated_at', '>=', now()->subDays($days))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $linted = 0; $skipped = 0; $errors = 0;

        foreach ($candidates as $article) {
            if (! $force) {
                $existing = ArticleLintResult::where('news_id', $article->id)->first();
                if ($existing && $existing->ran_at && $existing->ran_at->gte($article->updated_at)) {
                    $skipped++;
                    continue;
                }
            }

            $this->line("→ #{$article->id} {$article->title}");
            try {
                $findings = $linter->lint($article);
                $count = is_array($findings) ? count($findings) : 0;
                $criticals = is_array($findings)
                    ? collect($findings)->where('severity', 'critical')->count()
                    : 0;
                $this->info(sprintf('  ✓ %d findings (%d critical)', $count, $criticals));
                $linted++;
            } catch (\Throwable $e) {
                $errors++;
                $this->error('  ✗ '.$e->getMessage());
            }
        }

        $this->info(sprintf(
            'Done. linted=%d · skipped=%d · errors=%d',
            $linted, $skipped, $errors,
        ));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
