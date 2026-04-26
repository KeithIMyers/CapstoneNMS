<?php

namespace App\Filament\Widgets;

use App\Models\AgentRun;
use App\Models\AiRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * At-a-glance summary of AI usage. Sits below the editorial overview on
 * the admin dashboard and surfaces the four numbers admins care about:
 *   - completion calls today (from ai_requests)
 *   - errors today (any provider failures)
 *   - estimated cost over the last 7 days (in USD)
 *   - agent runs over the last 7 days
 *
 * Hidden when no provider is configured so freshly-installed sites
 * don't show empty cards.
 */
class AiActivityOverview extends BaseWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'AI activity';

    public static function canView(): bool
    {
        return \App\Models\AiProvider::query()->exists();
    }

    protected function getStats(): array
    {
        $today = AiRequest::where('created_at', '>=', now()->startOfDay());
        $week  = AiRequest::where('created_at', '>=', now()->subDays(7));
        $weekRuns = AgentRun::where('started_at', '>=', now()->subDays(7));

        $todayCount  = (clone $today)->count();
        $todayErrors = (clone $today)->where('status', 'error')->count();
        $weekCostMu  = (clone $week)->sum('cost_microusd');
        $weekRunsCnt = (clone $weekRuns)->count();
        $weekRunsErr = (clone $weekRuns)->where('status', AgentRun::STATUS_ERROR)->count();

        $stats = [
            Stat::make('AI calls today', number_format($todayCount))
                ->description('Across all providers and assistants')
                ->color($todayCount > 0 ? 'success' : 'gray')
                ->descriptionIcon('heroicon-m-sparkles', 'before'),

            Stat::make('Errors today', number_format($todayErrors))
                ->description($todayErrors === 0 ? 'All clean' : 'Worth a look in the usage log')
                ->color($todayErrors > 0 ? 'danger' : 'success'),

            Stat::make('Est. cost (7d)', '$'.number_format($weekCostMu / 1_000_000, 2))
                ->description('Based on listed token prices')
                ->color('gray'),

            Stat::make('Agent runs (7d)', number_format($weekRunsCnt))
                ->description($weekRunsErr === 0
                    ? 'No failures'
                    : number_format($weekRunsErr).' errored')
                ->color($weekRunsErr > 0 ? 'warning' : 'success'),
        ];

        // Embedding index health — only when a provider supports embeddings.
        if (\App\Models\AiProvider::defaultEmbeddingProvider()) {
            $publishedCount = \App\Models\News::published()->count();
            $indexedCount   = \App\Models\ArticleEmbedding::query()->distinct('news_id')->count('news_id');
            $coverage = $publishedCount > 0
                ? (int) round(($indexedCount / $publishedCount) * 100)
                : 0;

            $stats[] = Stat::make('Embedding index', "{$indexedCount} / {$publishedCount}")
                ->description("{$coverage}% of published articles indexed")
                ->color(match (true) {
                    $coverage >= 95 => 'success',
                    $coverage >= 60 => 'warning',
                    default         => 'danger',
                });
        }

        return $stats;
    }
}
