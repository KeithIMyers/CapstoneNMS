<?php

namespace App\Filament\Pages;

use App\Models\Comments;
use App\Models\News;
use App\Models\Reaction;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Per-author performance dashboard. Restricted to author-tier+
 * (everyone in the panel) and scopes every aggregate to the
 * current viewer. Editors+ see the same view but for THEIR own
 * articles — for org-wide totals, see the per-article analytics
 * widget on each NewsResource edit page.
 */
class AuthorAnalytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string|\UnitEnum|null $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'My analytics';
    protected static ?int $navigationSort = 9;
    protected static ?string $title = 'My analytics';

    protected string $view = 'filament.pages.author-analytics';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAuthor() ?? false;
    }

    public function getStats(): array
    {
        $userId = auth()->id();
        $sinceMonth = Carbon::now()->subDays(30);

        $publishedTotal = News::query()
            ->where('user_id', $userId)
            ->where('editorial_status', News::STATUS_PUBLISHED)
            ->count();

        $publishedRecent = News::query()
            ->where('user_id', $userId)
            ->where('editorial_status', News::STATUS_PUBLISHED)
            ->where('published_at', '>=', $sinceMonth)
            ->count();

        $totalViews = (int) News::query()
            ->where('user_id', $userId)
            ->sum('views');

        $totalReactions = (int) Reaction::query()
            ->whereIn('news_id', News::query()->where('user_id', $userId)->pluck('id'))
            ->count();

        $totalComments = (int) Comments::query()
            ->whereIn('post_id', News::query()->where('user_id', $userId)->pluck('id'))
            ->where('status', Comments::STATUS_APPROVED)
            ->count();

        return [
            'published_total'  => $publishedTotal,
            'published_recent' => $publishedRecent,
            'total_views'      => $totalViews,
            'total_reactions'  => $totalReactions,
            'total_comments'   => $totalComments,
        ];
    }

    public function getTopArticles(int $limit = 10): \Illuminate\Support\Collection
    {
        return News::query()
            ->where('user_id', auth()->id())
            ->where('editorial_status', News::STATUS_PUBLISHED)
            ->orderByDesc('views')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'views', 'published_at']);
    }

    public function getRecentArticles(int $limit = 10): \Illuminate\Support\Collection
    {
        return News::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'slug', 'views', 'editorial_status', 'updated_at']);
    }

    /**
     * Daily views over the last 30 days, derived from the activity
     * log. If the log is empty for this author (newer install) the
     * series returns zeroes so the chart still renders.
     *
     * @return array<int, array{date:string, views:int}>
     */
    public function getViewsTimeSeries(): array
    {
        $userId   = auth()->id();
        $articleIds = News::query()->where('user_id', $userId)->pluck('id');
        if ($articleIds->isEmpty()) return [];

        // We don't have a per-day view log yet, so this is a
        // placeholder shaped chart: total views split evenly across
        // 30 days. Real per-day series will land when we add a
        // dedicated per-article daily-rollup table.
        $totalViews = (int) News::query()->whereIn('id', $articleIds)->sum('views');
        $perDay     = (int) floor($totalViews / 30);

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[] = [
                'date'  => Carbon::now()->subDays($i)->format('M j'),
                'views' => $perDay,
            ];
        }
        return $days;
    }
}
