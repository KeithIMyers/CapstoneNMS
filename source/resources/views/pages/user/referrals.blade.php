@extends('layouts.site')

@section('head_title', 'Refer a friend · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Refer a friend</h1>
    <p class="text-muted" style="margin-block:0.5rem 1.5rem;">
        Share your code with anyone who'd enjoy {{ getcong('site_name') ?: config('app.name') }}. When they sign up and subscribe, you both get a thank-you reward.
    </p>

    <section class="card" style="padding:1.5rem; margin-bottom:1.5rem;">
        <h2 style="font-size:var(--t-md); margin-bottom:0.5rem;">Your code</h2>
        <p style="margin:0 0 0.75rem;">
            <code style="background:var(--c-bg-soft, #f3f4f6); padding:0.25rem 0.6rem; border-radius:6px; font-size:1.05rem; letter-spacing:0.08em;">{{ $code }}</code>
        </p>
        <h3 style="font-size:0.85rem; letter-spacing:0.06em; text-transform:uppercase; color:var(--c-fg-soft); margin-bottom:0.4rem;">Share link</h3>
        <p style="word-break:break-all; font-family:ui-monospace, monospace; font-size:0.85rem; padding:0.65rem; background:var(--c-bg-soft, #f8fafc); border-radius:6px;">
            {{ $shareUrl }}
        </p>
        <button type="button"
                class="btn btn--ghost"
                data-copy-target="{{ $shareUrl }}"
                style="margin-top:0.6rem;"
                aria-label="Copy your referral link to clipboard">
            Copy link
        </button>
    </section>

    <section style="margin-bottom:1.5rem;">
        <h2 style="font-size:var(--t-md);">People you've referred</h2>
        @if ($referees->isEmpty())
            <p class="text-muted">No referrals yet. Send your link and they'll show up here once they sign up.</p>
        @else
            <ul style="list-style:none; padding:0; display:grid; gap:0.4rem;">
                @foreach ($referees as $r)
                    <li class="card" style="padding:0.75rem 1rem; display:flex; justify-content:space-between; align-items:center;">
                        <span><strong>{{ $r->name }}</strong> <span class="text-muted" style="font-size:0.85rem;">· joined {{ $r->created_at?->diffForHumans() }}</span></span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section>
        <h2 style="font-size:var(--t-md);">Your rewards</h2>
        @if ($rewards->isEmpty())
            <p class="text-muted">Rewards land here when one of your referrals subscribes.</p>
        @else
            <ul style="list-style:none; padding:0; display:grid; gap:0.4rem;">
                @foreach ($rewards as $rw)
                    <li class="card" style="padding:0.75rem 1rem; display:flex; justify-content:space-between; align-items:center; gap:1rem;">
                        <span>
                            <strong>{{ $rw->reward_amount }} {{ str_replace('_', ' ', $rw->reward_type) }}</strong>
                            <span class="text-muted" style="font-size:0.85rem;">· {{ $rw->referee_email ?: '—' }}</span>
                        </span>
                        <span class="text-muted" style="font-size:0.78rem; letter-spacing:0.04em; text-transform:uppercase; padding:0.15rem 0.55rem; border-radius:999px;
                            background: {{ $rw->status === 'fulfilled' ? '#dcfce7' : ($rw->status === 'granted' ? '#fef3c7' : '#e5e7eb') }};
                            color: {{ $rw->status === 'fulfilled' ? '#166534' : ($rw->status === 'granted' ? '#92400e' : '#374151') }};">
                            {{ $rw->status }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="text-muted" style="margin-top:1.5rem; font-size:0.85rem;">
        Reward fulfillment is handled by the newsroom team. You'll get an email when each one is delivered.
    </p>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-copy-target]');
        if (!btn) return;
        e.preventDefault();
        var text = btn.dataset.copyTarget || '';
        if (!text) return;
        var ok = function () {
            if (typeof window.usntAnnounce === 'function') window.usntAnnounce('Referral link copied to clipboard.');
            btn.textContent = 'Copied';
            setTimeout(function () { btn.textContent = 'Copy link'; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(ok, function () {});
        }
    });
})();
</script>
@endsection
