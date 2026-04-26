@extends('layouts.site')

@section('head_title', 'Subscribe · '.getcong('site_name'))
@section('head_description', 'Subscribe to '.getcong('site_name').' for full access to premium reporting.')

@section('content')
<div class="container">
    <div class="page-intro">
        <h1>Subscribe</h1>
        <p>
            Support {{ getcong('site_name') ?: config('app.name') }} and unlock premium reporting.
            Cancel anytime.
        </p>
    </div>

    @if (session('error_flash_message'))
        <div class="flash flash--err">{{ session('error_flash_message') }}</div>
    @endif
    @if (session('flash_message'))
        <div class="flash flash--ok">{{ session('flash_message') }}</div>
    @endif

    @if (! $configured)
        <div class="aside-block" style="max-width:var(--container-prose);margin:1.5rem auto;padding:1.25rem;background:var(--c-paper);border:1px solid var(--c-border);border-radius:var(--radius-lg);">
            <h2 style="font-family:var(--font-display);">Subscriptions aren't configured yet</h2>
            <p class="text-muted">
                The site administrator hasn't set up subscription tiers yet. Check back later, or
                drop us a line via <a href="{{ url('/pages/contact-us') }}">Contact</a>.
            </p>
        </div>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));gap:1.25rem;max-width:var(--container-prose);margin:1.5rem auto;">

            @foreach ($tiers as $tier)
                @php
                    $isFeatured = $tier->is_default || $loop->index === 0;
                    $borderStyle = $isFeatured
                        ? 'border:2px solid #0ea5e9;'
                        : 'border:1px solid var(--c-border);';
                @endphp
                <article class="aside-block" style="padding:1.5rem;background:var(--c-paper);{{ $borderStyle }}border-radius:var(--radius-lg);text-align:center;position:relative;">
                    @if ($tier->is_default)
                        <span style="position:absolute;top:-0.65rem;left:50%;transform:translateX(-50%);background:#0ea5e9;color:#fff;font-size:0.7rem;letter-spacing:0.06em;text-transform:uppercase;padding:0.2rem 0.65rem;border-radius:999px;font-weight:700;">Most popular</span>
                    @endif
                    <h3 style="font-family:var(--font-display);font-size:var(--t-xl);margin:0 0 0.35rem 0;">{{ $tier->name }}</h3>
                    @if ($tier->description)
                        <p class="text-muted" style="margin:0 0 1rem 0;font-size:0.9rem;">{{ $tier->description }}</p>
                    @endif

                    @if ($tier->is_team)
                        <p class="text-muted" style="font-size:0.8rem; margin-bottom:0.6rem;">
                            Per-seat pricing · {{ $tier->min_seats }}–{{ $tier->max_seats }} seats
                        </p>
                        <form method="POST" action="{{ route('subscribe.checkout') }}" style="margin-bottom:0.6rem;">
                            @csrf
                            <input type="hidden" name="tier" value="{{ $tier->slug }}">
                            <input type="hidden" name="billing" value="{{ $tier->stripe_price_annual ? 'annual' : 'monthly' }}">
                            <div class="field" style="margin-bottom:0.6rem; text-align:left;">
                                <label for="seats-{{ $tier->slug }}" style="font-size:0.85rem;">Seats</label>
                                <input id="seats-{{ $tier->slug }}" type="number" name="seats"
                                       min="{{ $tier->min_seats }}" max="{{ $tier->max_seats }}"
                                       value="{{ $tier->min_seats }}" required style="text-align:center;">
                            </div>
                            <div class="field" style="margin-bottom:0.6rem; text-align:left;">
                                <label for="team-name-{{ $tier->slug }}" style="font-size:0.85rem;">Team name (optional)</label>
                                <input id="team-name-{{ $tier->slug }}" type="text" name="team_name" maxlength="120"
                                       placeholder="e.g. Newsroom A">
                            </div>
                            <button class="btn" type="submit" style="width:100%;">Buy {{ $tier->name }} seats</button>
                        </form>
                    @elseif ($tier->stripe_price_monthly)
                        <form method="POST" action="{{ route('subscribe.checkout') }}" style="margin-bottom:0.6rem;">
                            @csrf
                            <input type="hidden" name="tier" value="{{ $tier->slug }}">
                            <input type="hidden" name="billing" value="monthly">
                            <button class="btn" type="submit" style="width:100%;">
                                @if ($tier->monthlyPriceLabel())
                                    {{ $tier->monthlyPriceLabel() }} / month
                                @else
                                    Subscribe monthly
                                @endif
                            </button>
                        </form>
                    @endif

                    @if (! $tier->is_team && $tier->stripe_price_annual)
                        <form method="POST" action="{{ route('subscribe.checkout') }}">
                            @csrf
                            <input type="hidden" name="tier" value="{{ $tier->slug }}">
                            <input type="hidden" name="billing" value="annual">
                            <button class="btn btn--ghost" type="submit" style="width:100%;">
                                @if ($tier->annualPriceLabel())
                                    {{ $tier->annualPriceLabel() }} / year
                                @else
                                    Subscribe yearly
                                @endif
                            </button>
                        </form>
                    @endif
                </article>
            @endforeach

            @if ($giftPrice)
                <article class="aside-block" style="padding:1.5rem;background:var(--c-paper);border:1px solid var(--c-border);border-radius:var(--radius-lg);text-align:center;">
                    <h3 style="font-family:var(--font-display);font-size:var(--t-xl);margin:0 0 0.35rem 0;">Gift</h3>
                    <p class="text-muted" style="margin:0 0 1rem 0;font-size:0.9rem;">A year of access for someone else.</p>
                    <form method="POST" action="{{ route('subscribe.checkout') }}">
                        @csrf
                        <input type="hidden" name="plan" value="gift">
                        <button class="btn btn--ghost" type="submit" style="width:100%;">Buy a gift</button>
                    </form>
                </article>
            @endif
        </div>

        <p class="text-muted" style="margin:1.25rem auto;max-width:var(--container-prose);text-align:center;font-size:0.85rem;line-height:1.5;">
            Cards are processed by Stripe. Promo codes can be applied on the next page.
        </p>

        @guest
            <p class="text-muted" style="margin-top:1rem;font-size:0.9rem;text-align:center;">
                Don't have an account yet?
                <a href="{{ route('signup') }}">Create one</a>, or
                <a href="{{ route('user_login') }}">sign in</a>.
            </p>
        @endguest
    @endif
</div>
@endsection
