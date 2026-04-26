@extends('layouts.site')

@section('head_title', 'Reset password · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="text-align:center; margin-bottom: 0.5rem;">Reset your password</h1>
    <p class="text-muted text-center" style="margin-bottom: 1.5rem;">Enter your email and we'll send you a reset link that's valid for 60 minutes.</p>

    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('forget_password_submit') }}" class="card" style="padding: 1.5rem;">
        @csrf

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autocomplete="email" autofocus value="{{ old('email') }}">
        </div>

        @if (getcong('recaptcha_on_forgot_pass') && getcong('recaptcha_site_key'))
            <div class="g-recaptcha" data-sitekey="{{ getcong('recaptcha_site_key') }}" style="margin-bottom: 1rem;"></div>
            @push('scripts')
                <script src="https://www.google.com/recaptcha/api.js" async defer nonce="{{ csp_nonce() }}"></script>
            @endpush
        @endif

        <button class="btn" type="submit" style="width: 100%;">Send reset link</button>
    </form>

    <p class="text-muted text-center" style="margin-top: 1rem;">
        Remembered? <a href="{{ route('user_login') }}">Back to sign in</a>
    </p>
</div>
@endsection
