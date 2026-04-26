@extends('layouts.site')

@section('head_title', 'Cancel subscription · '.getcong('site_name'))

@section('content')
<div class="container">
    <article class="aside-block" style="max-width:var(--container-prose);margin:2.5rem auto;padding:2rem;background:var(--c-paper);border:1px solid var(--c-border);border-radius:var(--radius-lg);">
        @if (session('flash_message'))
            <div class="flash flash--ok" style="margin-bottom:1rem;">{{ session('flash_message') }}</div>
        @endif
        @if (session('error_flash_message'))
            <div class="flash flash--err" style="margin-bottom:1rem;">{{ session('error_flash_message') }}</div>
        @endif

        @if (! $isSubscribed)
            <h1 style="font-family:var(--font-display);">Nothing to cancel</h1>
            <p class="text-muted">There's no active subscription on your account.</p>
            <p style="margin-top:1.5rem;"><a class="btn btn--ghost" href="{{ url('/') }}">Back to the homepage</a></p>
        @else
            <h1 style="font-family:var(--font-display);">Sorry to see you go</h1>
            <p class="text-muted" style="line-height:1.6;">
                Tell us what didn't work so we can do better. Your access continues until the end of the
                current billing period regardless of which option you pick (or whether you skip the survey).
            </p>

            <form method="POST" action="{{ route('subscribe.cancel.submit') }}" style="margin-top:1.5rem;">
                @csrf

                <div class="field">
                    <label for="reason" style="font-weight:600;">Main reason</label>
                    <select id="reason" name="reason" style="width:100%;padding:0.6rem;border:1px solid var(--c-border);border-radius:var(--radius);">
                        <option value="">— optional —</option>
                        <option value="too_expensive">Too expensive</option>
                        <option value="not_enough_value">Didn't find enough I wanted to read</option>
                        <option value="content_quality">Content quality</option>
                        <option value="technical_issues">Technical issues</option>
                        <option value="moving_publication">Reading elsewhere now</option>
                        <option value="temporary">Temporary — coming back later</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="field" style="margin-top:1rem;">
                    <label for="feedback" style="font-weight:600;">Anything else? (optional)</label>
                    <textarea id="feedback" name="feedback" rows="4" maxlength="2000" placeholder="What would have kept you?"
                              style="width:100%;padding:0.6rem;border:1px solid var(--c-border);border-radius:var(--radius);"></textarea>
                </div>

                <div style="display:flex;gap:0.75rem;justify-content:flex-end;margin-top:1.5rem;flex-wrap:wrap;">
                    <a class="btn btn--ghost" href="{{ url('/') }}">Keep my subscription</a>
                    <button class="btn" type="submit" style="background:#dc2626;color:#fff;">Cancel my subscription</button>
                </div>
            </form>
        @endif
    </article>
</div>
@endsection
