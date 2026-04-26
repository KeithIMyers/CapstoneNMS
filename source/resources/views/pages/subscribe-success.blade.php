@extends('layouts.site')

@section('head_title', 'Welcome · '.getcong('site_name'))

@section('content')
<div class="container">
    <article class="aside-block" style="max-width:var(--container-prose);margin:3rem auto;padding:2.5rem;background:var(--c-paper);border:1px solid var(--c-border);border-radius:var(--radius-lg);text-align:center;">
        <h1 style="font-family:var(--font-display);">Welcome aboard</h1>
        <p class="text-muted" style="line-height:1.6;">
            Your subscription is active. Premium articles are unlocked across the site —
            and thank you for supporting independent reporting at
            {{ getcong('site_name') ?: config('app.name') }}.
        </p>
        <p style="margin-top:1.5rem;">
            <a class="btn" href="{{ url('/') }}">Back to the homepage</a>
        </p>
    </article>
</div>
@endsection
