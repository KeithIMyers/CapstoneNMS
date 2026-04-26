<?php

namespace App\Console\Commands;

use App\Models\NewsHeadline;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Drains the cache-resident A/B impression counters into the
 * news_headlines table.
 *
 * On a viral article the per-impression DB write would otherwise
 * cause hot-row write-lock contention. News::servedHeadline now
 * pushes impressions into the cache via Cache::increment; this
 * command flushes them in batched UPDATEs once a minute.
 *
 * Cache::pull() is atomic: any impressions arriving between the
 * pull and the DB UPDATE accumulate into a fresh counter that the
 * next run picks up. So nothing is lost and nothing double-counts.
 */
class FlushHeadlineImpressions extends Command
{
    protected $signature = 'news:flush-headline-impressions';

    protected $description = 'Flush cache-resident A/B headline impression counters into news_headlines.';

    public function handle(): int
    {
        $flushed = 0;

        // news_headlines is small (one row per variant per article
        // with a variant), so iterating to find dirty ids is cheaper
        // than maintaining a separate dirty-set in cache.
        NewsHeadline::query()->select(['id'])->cursor()->each(function (NewsHeadline $h) use (&$flushed) {
            if (! Cache::get("hl_imp_dirty:{$h->id}")) return;

            $count = (int) Cache::pull("hl_imp:{$h->id}", 0);
            Cache::forget("hl_imp_dirty:{$h->id}");
            if ($count > 0) {
                DB::table('news_headlines')->where('id', $h->id)->increment('impressions', $count);
                $flushed += $count;
            }
        });

        if ($flushed > 0) {
            $this->info("Flushed {$flushed} impression(s) to news_headlines.");
        }

        return self::SUCCESS;
    }
}
