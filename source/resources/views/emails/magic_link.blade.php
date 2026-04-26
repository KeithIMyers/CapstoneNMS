@extends('emails._layout')

@section('title', 'Your sign-in link')

@section('content')
    <h1>Sign in to {{ getcong('site_name') ?: config('app.name') }}</h1>
    <p>Hi {{ $userName ?: 'there' }},</p>
    <p>Click the button below to sign in. The link works once and expires in {{ $expiresIn }} minutes.</p>
    <p style="text-align:center;">
        <a class="btn" href="{{ url('auth/magic-link/'.$token) }}">Sign in</a>
    </p>
    <p class="muted">If you didn't request this, you can safely ignore the email — your account isn't changed and the link expires on its own.</p>
    <p class="muted">Trouble with the button? Paste this URL into your browser:<br>
        <code>{{ url('auth/magic-link/'.$token) }}</code>
    </p>
    @if ($requestIp)
        <p class="muted" style="font-size:11px;">Request from {{ $requestIp }} · {{ $requestUa }}</p>
    @endif
@endsection
