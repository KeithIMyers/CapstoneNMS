@extends('layouts.site')

@section('head_title', 'Choose a new password · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="text-align:center; margin-bottom: 1.5rem;">Choose a new password</h1>

    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('password_reset') }}" class="card" style="padding: 1.5rem;">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required autocomplete="email" autofocus>
        </div>

        <div class="field">
            <label for="password">New password</label>
            <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
            <span class="help">At least 8 characters.</span>
        </div>

        <div class="field">
            <label for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </div>

        @if (getcong('recaptcha_on_forgot_pass') && getcong('recaptcha_site_key'))
            <div class="g-recaptcha" data-sitekey="{{ getcong('recaptcha_site_key') }}" style="margin-bottom: 1rem;"></div>
            @push('scripts')
                <script src="https://www.google.com/recaptcha/api.js" async defer nonce="{{ csp_nonce() }}"></script>
            @endpush
        @endif

        <button class="btn" type="submit" style="width: 100%;">Update password</button>
    </form>
</div>
@endsection
