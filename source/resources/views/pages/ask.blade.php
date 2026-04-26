@extends('layouts.site')

@section('head_title', 'Ask · '.getcong('site_name'))
@section('head_description', 'Ask the newsroom a question. Our research assistant searches our coverage and answers with citations.')

@section('content')
<div class="container">
    <div class="page-intro">
        <span class="card__kicker">Ask</span>
        <h1>Ask the newsroom</h1>
        <p>
            Type a question and our research assistant searches our coverage to answer with cited sources.
            Answers are AI-generated and grounded in articles we've published — not a substitute for breaking news.
        </p>
    </div>

    @if (! $available)
        <div class="flash flash--err" style="margin-block:1rem;">
            {{ $error ?? 'The Ask feature isn\'t enabled right now.' }}
        </div>
    @elseif ($paywalled ?? false)
        @include('partials.site.ask-paywall', ['quota' => $quota])
    @else
        @include('partials.site.ask-quota-meter', ['quota' => $quota])

        <form method="post" action="{{ route('ask.ask') }}" style="margin-block:1.5rem;">
            @csrf
            <div class="field">
                <label for="ask_q" class="sr-only">Your question</label>
                <textarea
                    id="ask_q"
                    name="question"
                    rows="3"
                    minlength="5"
                    maxlength="1000"
                    required
                    placeholder="e.g., What did the latest energy bill change about residential solar tax credits?"
                    style="width:100%;font-size:1rem;line-height:1.5;padding:0.75rem;border-radius:8px;border:1px solid var(--c-border);">{{ old('question', $question) }}</textarea>
            </div>
            <div style="margin-top:0.6rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                <button class="btn" type="submit">Ask</button>
                <span class="text-muted" style="font-size:0.85rem;">
                    AI-generated answer · grounded in our coverage · 10 questions/hour per visitor.
                </span>
            </div>
            @error('question')
                <p class="text-muted" style="color:#b00020;margin-top:0.4rem;">{{ $message }}</p>
            @enderror
        </form>

        @if ($error)
            <div class="flash flash--err" style="margin-block:1rem;">{{ $error }}</div>
        @endif

        @if ($answer)
            <article class="aside-block" style="background:var(--c-paper);padding:1.5rem;border-radius:var(--radius-lg);">
                <header style="display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                    <h2 style="margin:0;font-size:var(--t-lg);">Answer</h2>
                    @if ($cached)
                        <span class="text-muted" style="font-size:0.78rem;">Cached from a recent identical question.</span>
                    @endif
                </header>
                <div class="prose" style="line-height:1.65;font-size:1rem;">
                    {!! nl2br(e($answer)) !!}
                </div>

                @if (! empty($sources))
                    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--c-border);">
                        <h3 style="font-size:0.95rem;margin-bottom:0.5rem;">Articles the assistant read:</h3>
                        <ul style="margin:0;padding-left:1.25rem;">
                            @foreach ($sources as $s)
                                <li>
                                    <a href="{{ route('news.details', ['slug' => $s['slug']]) }}">{{ $s['title'] }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </article>
        @endif
    @endif

    <p class="text-muted" style="margin-top:2rem;font-size:0.85rem;line-height:1.5;">
        Answers are produced by an AI assistant. They can be wrong or out of date. For breaking news,
        check the <a href="{{ url('/') }}">homepage</a> or our <a href="{{ url('/feed.xml') }}">RSS feed</a>.
    </p>
</div>
@endsection
