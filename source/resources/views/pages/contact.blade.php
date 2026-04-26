@extends('layouts.site')

@section('head_title', 'Contact us · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem;">
    <h1 style="margin-bottom: 0.5rem;">Contact us</h1>
    <p class="text-muted" style="margin-bottom: 1.5rem;">Have a story tip, correction, or feedback? Send us a message.</p>

    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('contact_send') }}" class="card" style="padding: 1.5rem;">
        @csrf

        <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" required value="{{ old('name') }}">
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required value="{{ old('email') }}">
        </div>

        <div class="field">
            <label for="phone">Phone (optional)</label>
            <input id="phone" type="tel" name="phone" value="{{ old('phone') }}">
        </div>

        <div class="field">
            <label for="subject">Subject</label>
            <input id="subject" type="text" name="subject" required value="{{ old('subject') }}">
        </div>

        <div class="field">
            <label for="message">Message</label>
            <textarea id="message" name="message" rows="6" required>{{ old('message') }}</textarea>
        </div>

        @if (getcong('recaptcha_on_contact_us') && getcong('recaptcha_site_key'))
            <div class="g-recaptcha" data-sitekey="{{ getcong('recaptcha_site_key') }}" style="margin-bottom: 1rem;"></div>
            @push('scripts')
                <script src="https://www.google.com/recaptcha/api.js" async defer nonce="{{ csp_nonce() }}"></script>
            @endpush
        @endif

        <button class="btn" type="submit">Send message</button>
    </form>
</div>
@endsection
