@extends('layouts.site')

@section('head_title', $show->title.' · '.getcong('site_name'))
@section('head_description', $show->description)

@push('scripts')
<link rel="alternate" type="application/rss+xml" title="{{ $show->title }} RSS"
      href="{{ route('podcasts.feed', ['slug' => $show->slug]) }}">
@endpush

@section('content')
<div class="container">
    <header class="podcast-header">
        @if ($show->artwork_path)
            <figure class="podcast-header__art">
                <img src="{{ image_src($show->artwork_path) }}" alt="{{ $show->title }}">
            </figure>
        @endif
        <div class="podcast-header__meta">
            <span class="card__kicker">Podcast</span>
            <h1>{{ $show->title }}</h1>
            @if ($show->author) <p class="text-muted">By {{ $show->author }}</p> @endif
            @if ($show->description) <p>{{ $show->description }}</p> @endif
            <p style="margin-top:1rem;">
                <a class="btn btn--ghost" href="{{ route('podcasts.feed', ['slug' => $show->slug]) }}">Subscribe (RSS)</a>
            </p>
        </div>
    </header>

    <h2 style="margin-top:2rem;">Episodes</h2>
    @if ($episodes->count())
        <ol class="episode-list">
            @foreach ($episodes as $ep)
                <li class="episode-item">
                    <div class="episode-item__meta">
                        @if ($ep->episode_number)
                            <span class="card__kicker">Ep. {{ $ep->episode_number }}</span>
                        @endif
                        <h3><a href="{{ route('podcasts.episode', ['showSlug' => $show->slug, 'episodeSlug' => $ep->slug]) }}">{{ $ep->title }}</a></h3>
                        <div class="card__meta">
                            @if ($ep->published_at)
                                <time datetime="{{ $ep->published_at->toAtomString() }}">{{ $ep->published_at->format('M j, Y') }}</time>
                            @endif
                            @if ($ep->duration_seconds)
                                <span class="dot" aria-hidden="true"></span>
                                <span>{{ $ep->durationFormatted() }}</span>
                            @endif
                        </div>
                        @if ($ep->description)
                            <p class="card__excerpt">{{ \Illuminate\Support\Str::limit($ep->description, 200) }}</p>
                        @endif
                    </div>
                    <audio controls preload="none" src="{{ $ep->media_url }}" style="width:100%; margin-top:0.5rem;"></audio>
                </li>
            @endforeach
        </ol>
        @if (method_exists($episodes, 'links'))
            <div>{{ $episodes->links('partials.site.pagination') }}</div>
        @endif
    @else
        <p class="text-muted">No episodes published yet.</p>
    @endif
</div>
@endsection
