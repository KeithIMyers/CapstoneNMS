<?php

namespace App\Filament\Widgets;

use App\Models\Comments;
use App\Models\News;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * At-a-glance editorial dashboard cards.
 */
class EditorialOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Editorial overview';

    protected function getStats(): array
    {
        $publishedThisWeek = News::published()
            ->where('published_at', '>=', now()->subDays(7))
            ->count();

        $publishedTotal = News::published()->count();

        $drafts = News::where('editorial_status', News::STATUS_DRAFT)->count();
        $inReview = News::where('editorial_status', News::STATUS_IN_REVIEW)->count();
        $scheduled = News::where('editorial_status', News::STATUS_SCHEDULED)
            ->whereNotNull('published_at')
            ->where('published_at', '>', now())
            ->count();

        $pendingComments = Comments::where('status', Comments::STATUS_PENDING)->count();

        $totalViews = News::sum('views');

        return [
            Stat::make('Published', $publishedTotal)
                ->description($publishedThisWeek.' new this week')
                ->descriptionIcon('heroicon-m-arrow-trending-up', 'before')
                ->color('success'),

            Stat::make('Drafts in flight', $drafts + $inReview)
                ->description($drafts.' draft · '.$inReview.' in review')
                ->color('warning'),

            Stat::make('Scheduled', $scheduled)
                ->description($scheduled === 0 ? 'Nothing in queue' : 'Auto-publishing on schedule')
                ->color('info'),

            Stat::make('Comments pending', $pendingComments)
                ->description($pendingComments === 0 ? 'Inbox zero' : 'Need a look')
                ->color($pendingComments === 0 ? 'success' : 'warning'),

            Stat::make('Total views', number_format((int) $totalViews))
                ->description('All-time across published articles')
                ->color('gray'),
        ];
    }
}
