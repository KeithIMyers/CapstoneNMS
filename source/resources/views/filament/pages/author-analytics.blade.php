<x-filament-panels::page>
    @php
        $stats   = $this->getStats();
        $top     = $this->getTopArticles();
        $recent  = $this->getRecentArticles();
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <div class="text-xs uppercase tracking-wide text-gray-500">Articles published</div>
            <div class="text-2xl font-semibold mt-1">{{ number_format($stats['published_total']) }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ $stats['published_recent'] }} in the last 30 days</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <div class="text-xs uppercase tracking-wide text-gray-500">Total views</div>
            <div class="text-2xl font-semibold mt-1">{{ number_format($stats['total_views']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <div class="text-xs uppercase tracking-wide text-gray-500">Reactions</div>
            <div class="text-2xl font-semibold mt-1">{{ number_format($stats['total_reactions']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <div class="text-xs uppercase tracking-wide text-gray-500">Comments</div>
            <div class="text-2xl font-semibold mt-1">{{ number_format($stats['total_comments']) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <div class="text-xs uppercase tracking-wide text-gray-500">Avg. views / article</div>
            <div class="text-2xl font-semibold mt-1">
                {{ $stats['published_total'] > 0
                    ? number_format(intdiv($stats['total_views'], max(1, $stats['published_total'])))
                    : '—' }}
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <h2 class="text-base font-semibold mb-3">Your top articles</h2>
            @if ($top->isEmpty())
                <p class="text-sm text-gray-500">Nothing published yet. Once an article goes live, its views will show here.</p>
            @else
                <ol class="space-y-2 list-decimal list-inside">
                    @foreach ($top as $a)
                        <li class="text-sm flex items-center justify-between gap-2">
                            <a class="text-primary-600 hover:underline truncate" href="{{ route('news.details', ['slug' => $a->slug]) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($a->title, 70) }}</a>
                            <span class="text-gray-500 whitespace-nowrap">{{ number_format($a->views) }} views</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>

        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-900">
            <h2 class="text-base font-semibold mb-3">Recently edited</h2>
            @if ($recent->isEmpty())
                <p class="text-sm text-gray-500">No articles yet. Drafts and edits show up here as you work.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($recent as $a)
                        <li class="text-sm flex items-center justify-between gap-2">
                            <a class="text-primary-600 hover:underline truncate" href="{{ \App\Filament\Resources\News\NewsResource::getUrl('edit', ['record' => $a->id]) }}">{{ \Illuminate\Support\Str::limit($a->title, 60) }}</a>
                            <span class="text-gray-500 whitespace-nowrap">{{ ucwords(str_replace('_', ' ', $a->editorial_status)) }} · {{ optional($a->updated_at)->diffForHumans() }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <p class="text-xs text-gray-500 mt-6">
        Stats are scoped to articles where you're the primary byline. Co-bylined pieces don't count here yet.
    </p>
</x-filament-panels::page>
