@extends('layouts.site')

@section('head_title', getcong('site_name') ?: config('app.name'))
@section('head_description', getcong('site_description'))

@section('content')

<div class="container" style="margin-block: 2rem 1rem;">
    @php
        $lead = $recent_news->first();
        $secondary = $recent_news->slice(1, 4);
        $rest = $recent_news->slice(5);
        $featured = collect($slider_news)->flatten(1)->take(6);
    @endphp

    <div class="front-grid">
        {{-- Main column: lead + secondary tile row --}}
        <div>
            @if ($lead)
                <article class="lead-story">
                    @if ($lead->image)
                        <a href="{{ route('news.details', ['slug' => $lead->slug]) }}" class="lead-story__media">
                            <img src="{{ image_src($lead->image) }}"
                                 alt="{{ $lead->image_alt ?: stripslashes($lead->title) }}" loading="eager" fetchpriority="high">
                        </a>
                    @endif

                    @if (!empty($lead->kicker))
                        <span class="lead-story__kicker">{{ stripslashes($lead->kicker) }}</span>
                    @elseif ($lead->category)
                        <a class="lead-story__kicker" href="{{ route('category_news', ['slug' => $lead->category->slug]) }}">{{ $lead->category->name }}</a>
                    @endif

                    <h1 class="lead-story__title">
                        <a href="{{ route('news.details', ['slug' => $lead->slug]) }}">{{ stripslashes($lead->title) }}</a>
                    </h1>

                    @if (!empty($lead->subtitle))
                        <p class="lead-story__dek">{{ stripslashes($lead->subtitle) }}</p>
                    @else
                        <p class="lead-story__dek">{{ \Illuminate\Support\Str::limit(stripslashes($lead->excerpt), 220) }}</p>
                    @endif

                    <div class="lead-story__byline">
                        @if ($lead->user) By <strong>{{ $lead->user->name }}</strong> @endif
                        @if ($lead->effectivePublishedAt())
                            · <time datetime="{{ $lead->effectivePublishedAt()->toAtomString() }}">{{ $lead->effectivePublishedAt()->format('F j, Y') }}</time>
                        @endif
                        @if ($lead->reading_time_minutes)
                            · {{ $lead->reading_time_minutes }} min read
                        @endif
                    </div>
                </article>
            @endif

            @if ($secondary->isNotEmpty())
                <div class="tile-row">
                    @foreach ($secondary as $item)
                        @include('partials.site.card', ['item' => $item])
                    @endforeach
                </div>
            @endif

            {{-- Behavior-aware "For you" feed for signed-in readers.
                 The partial collapses cleanly when $for_you is empty
                 (anonymous visitors, no reading history yet). --}}
            @auth
                @include('partials.site.recommendations', [
                    'items'    => $for_you ?? collect(),
                    'heading'  => 'For you',
                    'subtitle' => 'Picked from your reading history',
                    'context'  => 'home',
                ])
            @endauth
        </div>

        {{-- Sidebar: numbered trending + popular tags + newsletter --}}
        <aside>
            @if ($trending_news->count())
                <div class="aside-block">
                    <h3>Trending now</h3>
                    <ul class="numbered-list">
                        @foreach ($trending_news as $t)
                            <li>
                                <a class="item-title" href="{{ route('news.details', ['slug' => $t->slug]) }}">{{ stripslashes($t->title) }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php $popularTags = most_used_tags(12); @endphp
            @if ($popularTags->count())
                <div class="aside-block">
                    <h3>Popular tags</h3>
                    <div class="cluster">
                        @foreach ($popularTags as $name => $count)
                            <a class="tag" href="{{ route('tags_news', ['slug' => \Illuminate\Support\Str::slug($name)]) }}">#{{ $name }}</a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="newsletter">
                <h3>The morning brief</h3>
                <p>Top stories delivered to your inbox each weekday at 7am ET. Free, no spam, one click to unsubscribe.</p>
                <form action="{{ route('newsletter.subscribe') }}" method="post">
                    @csrf
                    <input type="hidden" name="source" value="homepage">
                    <input type="email" name="email" placeholder="you@example.com" required aria-label="Email">
                    <button class="btn btn--brand" type="submit">Subscribe</button>
                </form>
            </div>
        </aside>
    </div>

    @if ($featured->isNotEmpty())
        <hr class="fancy">

        <section class="section">
            <div class="section-head">
                <h2>Featured</h2>
                <span class="more">Editor’s picks</span>
            </div>
            <div class="grid-cards">
                @foreach ($featured as $item)
                    @include('partials.site.card', ['item' => $item])
                @endforeach
            </div>
        </section>
    @endif

    @if ($rest->isNotEmpty())
        <section class="section">
            <div class="section-head">
                <h2>Latest</h2>
                <a class="more" href="{{ url('/feed.xml') }}">RSS feed →</a>
            </div>
            <div class="grid-cards">
                @foreach ($rest->take(9) as $item)
                    @include('partials.site.card', ['item' => $item])
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
