@extends('emails._layout')

@section('title', 'Reset your password')

@section('content')
    <h1>Reset your password</h1>
    <p>Someone — hopefully you — asked to reset the password on your {{ getcong('site_name') ?: config('app.name') }} account. Click the button below within the next hour to choose a new one.</p>
    <p style="text-align:center;">
        <a class="btn" href="{{ url('password/reset/'.$token) }}">Reset password</a>
    </p>
    <p class="muted">If you didn't make this request, ignore this email — your password won't change. The link expires in 60 minutes.</p>
    <p class="muted">Trouble with the button? Paste this URL into your browser:<br>
        <code>{{ url('password/reset/'.$token) }}</code>
    </p>
@endsection
