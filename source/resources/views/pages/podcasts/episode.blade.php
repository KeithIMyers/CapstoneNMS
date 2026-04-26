@extends('layouts.site')

@section('head_title', $episode->title.' · '.$show->title)
@section('head_description', $episode->description)
@section('head_type', 'article')

@section('content')
<article class="container article">
    <header class="article__header">
        <a href="{{ route('podcasts.show', ['slug' => $show->slug]) }}" class="article__kicker">{{ $show->title }}</a>
        <h1 class="article__title">{{ $episode->title }}</h1>
        @if ($episode->description)
            <p class="article__subtitle">{{ $episode->description }}</p>
        @endif
        <div class="article__meta">
            @if ($episode->published_at)
                <time datetime="{{ $episode->published_at->toAtomString() }}">{{ $episode->published_at->format('F j, Y') }}</time>
            @endif
            @if ($episode->duration_seconds)
                <span>{{ $episode->durationFormatted() }}</span>
            @endif
            @if ($episode->season_number) <span>Season {{ $episode->season_number }}</span> @endif
            @if ($episode->episode_number) <span>Episode {{ $episode->episode_number }}</span> @endif
        </div>
    </header>

    <audio controls preload="metadata" src="{{ $episode->media_url }}"
           style="width:100%; max-width: var(--container-prose); margin: 1rem auto; display:block;"></audio>

    @if ($episode->show_notes)
        <div class="prose">
            {!! sanitize_rich_html(stripslashes($episode->show_notes)) !!}
        </div>
    @endif

    <p style="margin-top:2rem;">
        <a class="btn btn--ghost" href="{{ route('podcasts.show', ['slug' => $show->slug]) }}">← All episodes of {{ $show->title }}</a>
    </p>
</article>
@endsection
