@extends('layouts.site')

@section('head_title', 'Privacy preferences · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Privacy preferences</h1>
    <p class="text-muted" style="margin-block:0.5rem 1.5rem;">
        Manage what we know about you, what we share, and what happens to your data.
    </p>

    @if (session('flash_message'))
        <div class="flash flash--ok">{{ session('flash_message') }}</div>
    @endif
    @if (session('error_flash_message'))
        <div class="flash flash--err">{{ session('error_flash_message') }}</div>
    @endif

    <form method="post" action="{{ route('privacy.update') }}" class="card" style="padding:1.5rem;">
        @csrf
        <h2 style="font-size:var(--t-md); margin-bottom:0.5rem;">Your preferences</h2>
        <p class="text-muted" style="margin-bottom:1rem;">
            We don't sell user data; these toggles are documented options for clarity and CCPA compliance.
        </p>

        <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.75rem;">
            <input type="checkbox" name="do_not_sell" value="1" {{ $user->privacyFlag('do_not_sell') ? 'checked' : '' }}>
            <span><strong>Do not sell or share my personal information.</strong> <span class="text-muted">(California "Do Not Sell" right; we don't sell anyway, but this records your preference.)</span></span>
        </label>

        <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.75rem;">
            <input type="checkbox" name="marketing_email_opt_out" value="1" {{ $user->privacyFlag('marketing_email_opt_out') ? 'checked' : '' }}>
            <span><strong>Don't email me about marketing or product updates.</strong> <span class="text-muted">(Newsletters you've opted into stay unaffected — manage those <a href="{{ url('/profile') }}">in your profile</a>.)</span></span>
        </label>

        <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:1rem;">
            <input type="checkbox" name="behavioral_tracking_opt_out" value="1" {{ $user->privacyFlag('behavioral_tracking_opt_out') ? 'checked' : '' }}>
            <span><strong>Disable behavioral tracking.</strong> <span class="text-muted">(Analytics page-view + scroll-depth tracking. Subscription / login state still tracked since it's required to operate the account.)</span></span>
        </label>

        <button class="btn" type="submit">Save preferences</button>
    </form>

    <section class="card" style="padding:1.5rem; margin-top:1.5rem;">
        <h2 style="font-size:var(--t-md); margin-bottom:0.5rem;">Export your data</h2>
        <p class="text-muted" style="margin-bottom:1rem;">
            Download a single JSON file of every row we hold tied to your account: profile, comments, reactions, favorites, reading lists, history, donations, gifts, AI requests, and more.
        </p>
        <form method="post" action="{{ route('privacy.export') }}">
            @csrf
            <button class="btn btn--ghost" type="submit">Download my data</button>
        </form>
    </section>

    @if ($user->deletion_requested_at)
        <section class="card" style="padding:1.5rem; margin-top:1.5rem; border:2px solid #b00020;">
            <h2 style="font-size:var(--t-md); margin-bottom:0.5rem; color:#b00020;">Account deletion scheduled</h2>
            <p style="margin-bottom:1rem;">
                Your account is scheduled for hard deletion on
                <strong>{{ $user->deletion_requested_at->copy()->addDays(\App\Services\Privacy\AccountDeletionService::GRACE_DAYS)->format('M j, Y') }}</strong>.
                You can cancel until then.
            </p>
            <form method="post" action="{{ route('privacy.cancel_deletion') }}">
                @csrf
                <button class="btn" type="submit">Cancel deletion</button>
            </form>
        </section>
    @else
        <section class="card" style="padding:1.5rem; margin-top:1.5rem; border:1px solid var(--c-border);">
            <h2 style="font-size:var(--t-md); margin-bottom:0.5rem;">Delete my account</h2>
            <p class="text-muted" style="margin-bottom:1rem;">
                Hard-deletes your profile + everything tied to your user id after a {{ \App\Services\Privacy\AccountDeletionService::GRACE_DAYS }}-day grace window. Comments and AI-request history get anonymized rather than removed so other readers' threads stay intact. <strong>This cannot be undone after the grace window.</strong>
            </p>
            <form method="post" action="{{ route('privacy.delete') }}" onsubmit="return confirm('Delete your account? You can cancel within {{ \App\Services\Privacy\AccountDeletionService::GRACE_DAYS }} days.');">
                @csrf
                <div class="field">
                    <label for="confirm_email">Type your email to confirm</label>
                    <input id="confirm_email" type="email" name="confirm_email" required placeholder="{{ $user->email }}">
                </div>
                <div class="field">
                    <label for="confirm_word">Type the word <strong>delete</strong></label>
                    <input id="confirm_word" type="text" name="confirm_word" required placeholder="delete">
                </div>
                <button class="btn" type="submit" style="background:#b00020; border-color:#b00020;">Delete my account</button>
            </form>
        </section>
    @endif
</div>
@endsection
