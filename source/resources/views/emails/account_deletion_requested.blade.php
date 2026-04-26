@extends('emails._layout')

@section('title', 'Account deletion scheduled')

@section('content')
    <h1>Hi {{ $name }},</h1>
    <p>We've received your request to delete your account on {{ getcong('site_name') ?: config('app.name') }}. Your account is locked while we wait.</p>
    <p>If you change your mind, sign in via a magic link within the next <strong>{{ $graceDays }} days</strong> and click "Cancel deletion" on the privacy page:</p>
    <p style="text-align:center;">
        <a class="btn" href="{{ url('/auth/magic-link') }}">Sign in to cancel</a>
    </p>
    <p class="muted">After {{ $graceDays }} days, we hard-delete your profile, comments, reading data, and every other row tied to your account. Some downstream services (Stripe, mail provider) hold their own copies — request those separately if you need to.</p>
@endsection
