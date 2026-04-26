<?php

namespace App\Filament\Resources\News\Pages;

use App\Filament\Resources\News\NewsResource;
use App\Models\News;
use App\Services\Analytics\ArticleAnalytics;
use Filament\Resources\Pages\Page;

/**
 * Per-article analytics dashboard. Reachable from the EditNews
 * "Analytics" header action, mounted at /admin/news/{record}/analytics.
 *
 * Aggregates everything we know about the article's performance —
 * views over time, country breakdown, A/B headline CTR, reactions,
 * comment counts, scroll depth — and renders it in a single
 * server-rendered view. No client-side chart library; all charts
 * are inline SVG.
 */
class ArticleAnalyticsPage extends Page
{
    protected static string $resource = NewsResource::class;

    protected static ?string $title = 'Analytics';

    protected string $view = 'filament.resources.news.analytics';

    public News $record;

    public array $stats = [];

    public function mount(int|string $record): void
    {
        $this->record = News::with(['category', 'user'])->findOrFail($record);
        $this->stats = app(ArticleAnalytics::class)->for($this->record);
    }

    public function getTitle(): string
    {
        return 'Analytics · '.\Illuminate\Support\Str::limit((string) $this->record->title, 60);
    }
}
