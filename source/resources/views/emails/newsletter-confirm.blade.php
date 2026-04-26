@extends('emails._layout')

@section('title', 'Confirm your subscription')

@section('content')
    <h1>One quick click</h1>
    <p>Thanks for subscribing to {{ getcong('site_name') ?: config('app.name') }}. Confirm your email by clicking the button below — we don't want to send you anything you didn't ask for.</p>
    <p style="text-align:center;">
        <a class="btn" href="{{ $confirmUrl }}">Confirm subscription</a>
    </p>
    <p class="muted">If the button doesn't work, copy this link into your browser:</p>
    <p class="muted"><code>{{ $confirmUrl }}</code></p>
    <p class="muted">Didn't sign up? You can safely ignore this email — your address won't be added to our list.</p>
    <p class="muted">If you'd like to remove this address from any pending sign-ups, <a href="{{ $unsubscribeUrl }}">click here</a>.</p>
@endsection
