@extends('layouts.site')

@section('head_title', 'Gift this article · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="margin-bottom: 0.5rem;">Gift this article</h1>
    <p class="text-muted" style="margin-bottom: 2rem;">
        Send a single-use link that lets a non-subscriber read this article in full.
    </p>

    <article class="card" style="padding: 1.25rem; margin-bottom: 1.5rem;">
        <div class="text-muted" style="font-size:0.85rem; margin-bottom:0.25rem;">Gifting</div>
        <h2 style="margin:0 0 0.4rem; font-size: var(--t-md);">{{ $article->title }}</h2>
        @if ($article->excerpt)
            <p class="text-muted" style="margin:0; font-size:0.9rem;">{{ \Illuminate\Support\Str::limit(strip_tags($article->excerpt), 200) }}</p>
        @endif
    </article>

    @if (session('flash_message'))
        <div class="flash flash--ok">{{ session('flash_message') }}</div>
    @endif
    @if (session('error_flash_message'))
        <div class="flash flash--err">{{ session('error_flash_message') }}</div>
    @endif

    @if (session('gift_url'))
        <div class="card" style="padding:1rem; margin-block:1rem; background: var(--c-paper);">
            <strong>Your gift link</strong>
            <p style="word-break: break-all; font-family: ui-monospace, monospace; font-size: 0.85rem; margin-top: 0.5rem;">
                {{ session('gift_url') }}
            </p>
        </div>
    @endif

    @if (! $status['can'])
        <div class="flash flash--err">
            @switch($status['reason'])
                @case('gifts_disabled')
                    Article gifting isn't available right now.
                    @break
                @case('not_subscribed')
                    You need an active subscription to gift articles.
                    <a href="{{ url('/subscribe') }}" class="btn btn--ghost" style="margin-top: 0.75rem;">Subscribe</a>
                    @break
                @case('monthly_cap_reached')
                    You've used all {{ $status['cap'] }} of your monthly gifts. Your allowance resets {{ $status['resets_at']->format('M j') }}.
                    @break
                @default
                    You can't gift this article right now.
            @endswitch
        </div>
    @else
        <p class="text-muted" style="margin-bottom: 0.75rem;">
            <strong>{{ $status['remaining'] === -1 ? 'Unlimited' : $status['remaining'] }}</strong>
            of {{ $status['cap'] === 0 || $status['cap'] === -1 ? '∞' : $status['cap'] }} gifts remaining this month
            · resets {{ $status['resets_at']->format('M j') }}.
        </p>

        <form method="post" action="{{ route('articles.gift.send', ['news' => $article->id]) }}" class="card" style="padding:1.5rem;">
            @csrf
            <div class="field">
                <label for="recipient_email">Recipient email <span class="text-muted">(optional — leave blank to copy a link)</span></label>
                <input id="recipient_email" type="email" name="recipient_email" placeholder="friend@example.com" value="{{ old('recipient_email') }}">
            </div>
            <button class="btn" type="submit">Create gift link</button>
            <a class="btn btn--ghost" href="{{ route('news.details', ['slug' => $article->slug]) }}">Cancel</a>
        </form>
    @endif

    <p class="text-muted" style="margin-top:1.5rem; font-size:0.85rem;">
        Gift links are single-use and expire in {{ config('paywall.gifts.link_ttl_days', 14) }} days. Once a recipient opens the link, the article stays unlocked on their browser.
    </p>
</div>
@endsection
