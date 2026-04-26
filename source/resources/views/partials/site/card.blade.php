@php
    // A/B headline: servedHeadline() returns the variant model (or null)
    // and increments impressions once per session.
    $_hdl = $item->servedHeadline();
    $_headlineText = $_hdl?->variant ?: $item->title;
    $_headlineAttr = $_hdl ? 'data-headline="'.$_hdl->id.'"' : '';
@endphp
<article class="card">
    @if ($item->image)
        <a class="card__media" href="{{ route('news.details', ['slug' => $item->slug]) }}" {!! $_headlineAttr !!}>
            <img src="{{ image_src($item->image) }}"
                 alt="{{ $item->image_alt ?: stripslashes($_headlineText) }}" loading="lazy">
        </a>
    @endif
    <div class="card__body">
        @if (!empty($item->kicker))
            <span class="card__kicker">{{ stripslashes($item->kicker) }}</span>
        @elseif ($item->category)
            <a class="card__kicker" href="{{ route('category_news', ['slug' => $item->category->slug]) }}">{{ $item->category->name }}</a>
        @endif
        <h3 class="card__title">
            <a href="{{ route('news.details', ['slug' => $item->slug]) }}" {!! $_headlineAttr !!}>{{ stripslashes($_headlineText) }}</a>
        </h3>
        @if (!empty($item->excerpt))
            <p class="card__excerpt">{{ \Illuminate\Support\Str::limit(stripslashes($item->excerpt), 120) }}</p>
        @endif
        <div class="card__meta">
            @if ($item->user) <span>{{ $item->user->name }}</span> <span class="dot" aria-hidden="true"></span> @endif
            @if ($item->effectivePublishedAt())
                <time datetime="{{ $item->effectivePublishedAt()->toAtomString() }}">{{ $item->effectivePublishedAt()->diffForHumans() }}</time>
            @endif
            @if ($item->reading_time_minutes)
                <span class="dot" aria-hidden="true"></span>
                <span>{{ $item->reading_time_minutes }} min</span>
            @endif
        </div>
    </div>
</article>
