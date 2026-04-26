@extends('layouts.site')

@section('head_title', 'Reading history · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Your reading history</h1>
    <p class="text-muted" style="margin-bottom:1.5rem;">
        Articles you've opened on this account, most recent first. Only visible to you.
    </p>

    @if (session('flash_message'))
        <div class="flash flash--ok">{{ session('flash_message') }}</div>
    @endif

    @if ($continueReading->isNotEmpty())
        <h2 style="font-size:var(--t-md); margin-bottom:0.6rem;">Pick up where you left off</h2>
        <ul style="list-style:none; padding:0; display:grid; gap:0.5rem; margin-bottom:1.5rem;">
            @foreach ($continueReading as $h)
                @if ($h->article)
                    <li class="card" style="padding:0.85rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                        <a href="{{ route('news.details', ['slug' => $h->article->slug]) }}">{{ $h->article->title }}</a>
                        <span class="text-muted" style="font-size:0.85rem; white-space:nowrap;">
                            {{ $h->scroll_depth_pct }}% read
                        </span>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif

    <h2 style="font-size:var(--t-md); margin-bottom:0.6rem;">All recent reads</h2>
    @if ($recent->isEmpty())
        <p class="text-muted">No reads tracked yet. Open any article while signed in.</p>
    @else
        <ul style="list-style:none; padding:0; display:grid; gap:0.5rem;">
            @foreach ($recent as $h)
                @if ($h->article)
                    <li class="card" style="padding:0.85rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                        <a href="{{ route('news.details', ['slug' => $h->article->slug]) }}">{{ $h->article->title }}</a>
                        <span class="text-muted" style="font-size:0.8rem; white-space:nowrap;">
                            {{ optional($h->last_read_at)->diffForHumans() ?: '—' }}
                            · {{ $h->read_count }}×
                        </span>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif

    <form method="post" action="{{ route('reading_history.clear') }}" style="margin-top:2rem;" onsubmit="return confirm('Clear your reading history?');">
        @csrf
        <button class="btn btn--ghost" type="submit">Clear reading history</button>
    </form>
</div>
@endsection
