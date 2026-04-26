@php
    use App\Models\News;
    $breaking = \Illuminate\Support\Facades\Cache::remember('site.breaking', 60, function () {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('news', 'is_breaking')) {
            return null;
        }
        return News::published()
            ->where('is_breaking', true)
            ->where(function ($q) {
                $q->whereNull('breaking_until')->orWhere('breaking_until', '>', now());
            })
            ->orderByDesc('published_at')
            ->first();
    });
@endphp

@if ($breaking)
    <div class="breaking-banner" role="alert">
        <strong>Breaking</strong> · <a href="{{ route('news.details', ['slug' => $breaking->slug]) }}">{{ stripslashes($breaking->title) }}</a>
    </div>
@endif
