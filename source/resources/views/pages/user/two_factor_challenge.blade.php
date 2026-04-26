@extends('layouts.site')

@section('head_title', 'Two-factor sign-in · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="text-align:center; margin-bottom: 1.5rem;">Two-factor authentication</h1>

    <p class="text-muted text-center" style="margin-bottom: 2rem;">
        Enter the 6-digit code from your authenticator app, or one of your recovery codes.
    </p>

    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif
    @if (session('error_flash_message'))
        <div class="flash flash--err">{{ session('error_flash_message') }}</div>
    @endif

    <form method="post" action="{{ route('two_factor.verify') }}" class="card" style="padding: 1.5rem;">
        @csrf

        <div class="field">
            <label for="code">Code</label>
            <input id="code" type="text" name="code" required autofocus
                   inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9A-Za-z\- ]{6,20}" maxlength="20">
        </div>

        <button class="btn" type="submit" style="width: 100%;">Verify and sign in</button>
    </form>

    <p class="text-muted text-center" style="margin-top: 1rem;">
        <a href="{{ route('user_login') }}">Cancel and start over</a>
    </p>
</div>
@endsection
