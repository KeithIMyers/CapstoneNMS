{{-- Trending articles partial. Pulls the top N by view count in the
     last 24h, falling back to most-viewed-this-week when there's no
     same-day data (e.g., quiet news days, fresh installs). Cached for
     5 minutes so the SQL only runs once per cache window.

     Args (optional):
       $limit — number of articles to render (default 5)
       $heading — section heading (default "Trending now") --}}
@php
    $limit   = $limit   ?? 5;
    $heading = $heading ?? 'Trending now';
    $trending = \Illuminate\Support\Facades\Cache::store('file')->remember(
        "trending.{$limit}",
        300,
        function () use ($limit) {
            $rows = \App\Models\News::published()
                ->where('published_at', '>=', now()->subDay())
                ->orderByDesc('views')
                ->limit($limit)
                ->get(['id', 'title', 'slug', 'image', 'published_at', 'category_id']);

            // Fall back to the last week when the day is quiet.
            if ($rows->count() < $limit) {
                $rows = \App\Models\News::published()
                    ->where('published_at', '>=', now()->subDays(7))
                    ->orderByDesc('views')
                    ->limit($limit)
                    ->get(['id', 'title', 'slug', 'image', 'published_at', 'category_id']);
            }
            return $rows->load('category');
        }
    );
@endphp

@if (! $trending->isEmpty())
    <aside class="trending" aria-label="{{ $heading }}">
        <h2 class="trending__heading">{{ $heading }}</h2>
        <ol class="trending__list">
            @foreach ($trending as $i => $item)
                <li class="trending__item">
                    <span class="trending__rank">{{ $i + 1 }}</span>
                    <div class="trending__body">
                        @if ($item->category)
                            <a class="trending__kicker" href="{{ route('category_news', ['slug' => $item->category->slug]) }}">{{ $item->category->name }}</a>
                        @endif
                        <a class="trending__title" href="{{ route('news.details', ['slug' => $item->slug]) }}">{{ stripslashes($item->title) }}</a>
                    </div>
                </li>
            @endforeach
        </ol>
    </aside>
@endif
