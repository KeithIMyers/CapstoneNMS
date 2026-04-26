@extends('layouts.site')

@section('head_title', 'Support '.getcong('site_name'))
@section('head_description', 'Make a one-time donation to support independent journalism.')

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Support {{ getcong('site_name') ?: config('app.name') }}</h1>
    <p class="text-muted" style="margin-block:0.5rem 1.5rem;">
        Independent reporting depends on readers. Donations are one-time charges and entirely optional —
        if you'd rather have ongoing access to premium reporting, <a href="{{ url('/subscribe') }}">subscribe instead</a>.
    </p>

    @if (session('error_flash_message'))
        <div class="flash flash--err">{{ session('error_flash_message') }}</div>
    @endif

    @if (! $configured)
        <div class="aside-block" style="padding:1.25rem;background:var(--c-paper);border:1px solid var(--c-border);border-radius:var(--radius-lg);">
            <p>Donations aren't configured yet. The site administrator hasn't set Stripe API keys.</p>
        </div>
    @else
        <form method="post" action="{{ route('donate.checkout') }}" class="card" style="padding:1.5rem;">
            @csrf

            <div class="field">
                <label>Amount</label>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.75rem;">
                    @foreach ($presets as $cents)
                        <label style="flex:1; min-width:6.5rem; cursor:pointer;">
                            <input type="radio" name="preset" value="{{ $cents }}" {{ $loop->index === 1 ? 'checked' : '' }} style="display:none;" data-amount-preset>
                            <span class="btn btn--ghost" style="display:block; text-align:center; padding:0.85rem;">${{ number_format($cents / 100, 0) }}</span>
                        </label>
                    @endforeach
                </div>
                <label for="amount_dollars" class="text-muted" style="font-size:0.85rem;">Or enter a custom amount (USD)</label>
                <input id="amount_dollars" type="number" name="amount_dollars" step="0.01" min="1" max="1000" placeholder="e.g. 25.00" inputmode="decimal">
                <input type="hidden" name="amount_cents" id="amount_cents" value="{{ $presets[1] }}">
            </div>

            <div class="field">
                <label for="message">Add a public message <span class="text-muted">(optional, ≤ 280 chars)</span></label>
                <textarea id="message" name="message" rows="3" maxlength="280" placeholder="Why this matters to you…"></textarea>
            </div>

            <div class="field">
                <label style="display:inline-flex; align-items:center; gap:0.5rem;">
                    <input type="checkbox" name="anonymous" value="1">
                    <span>List me anonymously on the supporters page</span>
                </label>
            </div>

            <button class="btn" type="submit" style="width:100%;">Continue to payment</button>
            <p class="text-muted" style="margin-top:0.75rem; font-size:0.85rem; text-align:center;">
                Cards are processed by Stripe. Your card details never touch our servers.
            </p>
        </form>

        <script nonce="{{ csp_nonce() }}">
            (() => {
                const dollars = document.getElementById('amount_dollars');
                const cents   = document.getElementById('amount_cents');
                const presets = document.querySelectorAll('[data-amount-preset]');

                function setFromPreset(value) {
                    cents.value = value;
                    dollars.value = '';
                }

                presets.forEach((r) => {
                    r.addEventListener('change', () => setFromPreset(r.value));
                    if (r.checked) cents.value = r.value;
                });

                dollars.addEventListener('input', () => {
                    const v = parseFloat(dollars.value);
                    if (!isNaN(v) && v > 0) {
                        cents.value = String(Math.round(v * 100));
                        presets.forEach((p) => p.checked = false);
                    }
                });
            })();
        </script>
    @endif
</div>
@endsection
