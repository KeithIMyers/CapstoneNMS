@extends('layouts.site')

@section('head_title', 'Sign in with email · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="text-align:center; margin-bottom: 1.5rem;">Sign in with email</h1>

    <p class="text-muted text-center" style="margin-bottom: 2rem;">
        We'll email you a one-time sign-in link. No password needed.
    </p>

    @if (session('flash_message'))
        <div class="flash flash--ok">{{ session('flash_message') }}</div>
    @endif
    @if (session('error_flash_message'))
        <div class="flash flash--err">{{ session('error_flash_message') }}</div>
    @endif
    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('magic_link.send') }}" class="card" style="padding: 1.5rem;">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autocomplete="email" autofocus value="{{ old('email') }}">
        </div>

        @if (getcong('recaptcha_on_login') && getcong('recaptcha_site_key'))
            <div class="g-recaptcha" data-sitekey="{{ getcong('recaptcha_site_key') }}" style="margin-bottom: 1rem;"></div>
            @push('scripts')
                <script src="https://www.google.com/recaptcha/api.js" async defer nonce="{{ csp_nonce() }}"></script>
            @endpush
        @endif

        <button class="btn" type="submit" style="width: 100%;">Email me a sign-in link</button>
    </form>

    <p class="text-muted text-center" style="margin-top: 1rem;">
        Prefer a password? <a href="{{ route('user_login') }}">Sign in with password</a>
        @if (getcong('google_login_on'))
            · <a href="{{ route('google_login') }}">Continue with Google</a>
        @endif
    </p>
</div>
@endsection
