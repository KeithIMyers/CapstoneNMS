@extends('layouts.site')

@section('head_title', 'Sign in · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="text-align:center; margin-bottom: 1.5rem;">Sign in</h1>

    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('user_login_check') }}" class="card" style="padding: 1.5rem;">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autocomplete="email" autofocus value="{{ old('email') }}">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>

        <div class="cluster" style="justify-content: space-between; margin-bottom: 1rem;">
            <label style="display:inline-flex; align-items:center; gap:0.4rem; font-size:var(--t-sm); color:var(--c-fg-soft);">
                <input type="checkbox" name="remember"> Remember me
            </label>
            <a href="{{ route('forget_password') }}" style="font-size: var(--t-sm);">Forgot password?</a>
        </div>

        @if (getcong('recaptcha_on_login') && getcong('recaptcha_site_key'))
            <div class="g-recaptcha" data-sitekey="{{ getcong('recaptcha_site_key') }}" style="margin-bottom: 1rem;"></div>
            @push('scripts')
                <script src="https://www.google.com/recaptcha/api.js" async defer nonce="{{ csp_nonce() }}"></script>
            @endpush
        @endif

        <button class="btn" type="submit" style="width: 100%;">Sign in</button>

        <hr>
        <a class="btn btn--ghost" href="{{ route('magic_link.show') }}" style="width: 100%;">
            Email me a sign-in link instead
        </a>

        @if (getcong('google_login_on'))
            <hr>
            <a class="btn btn--ghost" href="{{ route('google_login') }}" style="width: 100%;">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                    <path fill="#4285F4" d="M22 11.5C22 10.7 21.9 10.1 21.8 9.4H12V13.4H17.6C17.4 14.6 16.6 16.5 14.7 17.7L14.7 17.7L18.2 20.4C20.4 18.4 22 15.3 22 11.5Z"/>
                    <path fill="#34A853" d="M12 22C14.7 22 17 21.1 18.7 19.5L14.6 16.4C13.6 17.1 12.3 17.6 12 17.6C9.4 17.6 7.2 15.9 6.4 13.5L6.3 13.5L2.7 16.3C4.4 19.7 7.9 22 12 22Z"/>
                    <path fill="#FBBC05" d="M6.4 13.5C6.2 12.8 6.1 12.1 6.1 11.4C6.1 10.7 6.2 10 6.4 9.3L6.4 9.1L2.7 6.3L2.6 6.3C1.9 7.9 1.5 9.6 1.5 11.4C1.5 13.2 1.9 14.9 2.6 16.5Z"/>
                    <path fill="#EB4335" d="M12 5.6C13.9 5.6 15.2 6.4 15.9 7.1L19 4.1C17.1 2.3 14.7 1.4 12 1.4C7.9 1.4 4.4 3.7 2.7 7.1L6.4 9.9C7.2 7.5 9.4 5.6 12 5.6Z"/>
                </svg>
                <span>Continue with Google</span>
            </a>
        @endif
    </form>

    <p class="text-muted text-center" style="margin-top: 1rem;">
        Don't have an account? <a href="{{ route('signup') }}">Create one</a>
    </p>
</div>
@endsection
