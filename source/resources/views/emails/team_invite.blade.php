@extends('emails._layout')

@section('title', 'You\'re invited to a team subscription')

@section('content')
    <h1>{{ $inviterName ?: 'A friend' }} invited you</h1>
    <p>{{ $inviterName ?: 'A friend' }} added you to {{ $teamName ?: 'their' }} {{ $tierName ?: 'team' }} subscription on {{ getcong('site_name') ?: config('app.name') }}.</p>
    <p>Click below to accept and unlock full access. The link is single-use and expires in {{ $expiresIn }} days.</p>

    <p style="text-align:center;">
        <a class="btn" href="{{ $acceptUrl }}">Accept invitation</a>
    </p>

    <p class="muted">If you don't have an account yet, you can create one before accepting — the same link will pick up where you left off.</p>
    <p class="muted">Trouble with the button? Paste this URL into your browser:<br>
        <code>{{ $acceptUrl }}</code>
    </p>
@endsection
