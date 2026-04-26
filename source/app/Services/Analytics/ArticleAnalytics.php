<?php

namespace App\Services\Analytics;

use App\Models\News;
use Illuminate\Support\Facades\DB;

/**
 * Pulls everything we know about a single article's performance into
 * one DTO-shaped array. Read-only; the caller renders.
 *
 * Sources:
 *   news               total views + scroll counters + reading-time
 *   article_country_views   per-country, per-day reads (Phase E)
 *   news_headlines     A/B variant impressions + clicks (Phase F)
 *   reactions          per-type tallies
 *   comments           total + pending counts
 *   activity_log       breaking-news pushes, agent attribution, etc.
 *
 * Rollups are computed in PHP after a small handful of SQL queries so
 * the page can render in one round-trip even on shared hosting.
 */
class ArticleAnalytics
{
    public function for(News $article, int $daysWindow = 30): array
    {
        $countryRows = DB::table('article_country_views')
            ->where('article_id', $article->id)
            ->where('day', '>=', now()->subDays($daysWindow)->toDateString())
            ->orderBy('day')
            ->get(['country_code', 'day', 'views']);

        $byDay = [];
        $byCountry = [];
        foreach ($countryRows as $r) {
            $byDay[$r->day] = ($byDay[$r->day] ?? 0) + (int) $r->views;
            $byCountry[$r->country_code] = ($byCountry[$r->country_code] ?? 0) + (int) $r->views;
        }
        ksort($byDay);
        arsort($byCountry);

        // Pad zero-day buckets so the chart line doesn't jump across gaps.
        $padded = [];
        $cursor = now()->copy()->subDays($daysWindow - 1)->startOfDay();
        for ($i = 0; $i < $daysWindow; $i++) {
            $key = $cursor->toDateString();
            $padded[$key] = $byDay[$key] ?? 0;
            $cursor->addDay();
        }

        $reactionTallies = DB::table('reactions')
            ->where('news_id', $article->id)
            ->select('type', DB::raw('COUNT(*) as n'))
            ->groupBy('type')
            ->pluck('n', 'type')
            ->toArray();

        $comments = DB::table('comments')
            ->where('post_id', $article->id)
            ->select('status', DB::raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        $headlines = DB::table('news_headlines')
            ->where('news_id', $article->id)
            ->orderByDesc('impressions')
            ->get(['variant', 'is_default', 'impressions', 'clicks']);

        $totalViews     = (int) $article->views;
        $scrollSamples  = (int) ($article->scroll_samples ?? 0);
        $scrollDepthSum = (int) ($article->scroll_depth_total ?? 0);
        $avgDepth       = $scrollSamples > 0 ? round($scrollDepthSum / $scrollSamples, 1) : null;

        return [
            'article'       => $article,
            'days_window'   => $daysWindow,
            'total_views'   => $totalViews,
            'views_window'  => array_sum($padded),
            'avg_depth'     => $avgDepth,
            'scroll_samples'=> $scrollSamples,
            'reading_time'  => (int) ($article->reading_time_minutes ?? 0),
            'daily_views'   => $padded,                  // [date => count]
            'by_country'    => $byCountry,               // [iso => count, sorted desc]
            'reactions'     => $reactionTallies,         // [type => n]
            'comments'      => $comments,                // [status => n]
            'headlines'     => $headlines,
        ];
    }
}
