@extends('emails._layout')

@section('title', 'Sign-up attempt on your '.(getcong('site_name') ?: config('app.name')).' account')

@section('content')
    <h1>Hi {{ $name }},</h1>
    <p>Someone just tried to create a new account on {{ getcong('site_name') ?: config('app.name') }} using your email address. We didn't create a duplicate — your existing account is unchanged.</p>
    <p>If this was you and you've forgotten your password, you can reset it instead:</p>
    <p style="text-align:center;">
        <a class="btn" href="{{ url('/password/email') }}">Reset password</a>
    </p>
    <p class="muted">If it wasn't you, no action is needed — the attempt didn't change anything on your account.</p>
@endsection
