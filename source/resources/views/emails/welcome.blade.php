@extends('emails._layout')

@section('title', 'Welcome to '.(getcong('site_name') ?: config('app.name')))

@section('content')
    <h1>Welcome, {{ $user_name }}!</h1>
    <p>Thanks for creating an account on {{ getcong('site_name') ?: config('app.name') }}. You can now save articles to your favorites and join the discussion.</p>
    <p style="text-align:center;">
        <a class="btn" href="{{ url('/login') }}">Sign in</a>
    </p>
    <p class="muted">If you didn't sign up, just ignore this email and the account will sit dormant.</p>
@endsection
