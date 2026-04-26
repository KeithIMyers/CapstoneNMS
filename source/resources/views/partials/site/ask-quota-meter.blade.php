{{--
    Compact quota readout shown above the Ask form. Hidden when the
    paywall is disabled or when the visitor's tier is unlimited
    (limit === -1). The "upgrade" link points anonymous visitors to
    signup and registered visitors to the subscribe checkout.
--}}
@if (($quota['enabled'] ?? false) && ($quota['limit'] ?? 0) !== -1)
    @php
        $tier      = $quota['tier'];
        $limit     = (int) $quota['limit'];
        $used      = (int) $quota['used'];
        $remaining = (int) $quota['remaining'];
        $resetsAt  = $quota['resets_at'] ?? null;
    @endphp

    <div class="aside-block" style="margin-block:1rem;padding:0.85rem 1.1rem;background:var(--c-paper);border-radius:var(--radius-md);display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div style="font-size:0.9rem;line-height:1.4;">
            @if ($limit === 0)
                <strong>Sign in to ask the newsroom.</strong>
                <span class="text-muted">Anonymous questions aren't enabled on this site.</span>
            @else
                <strong>{{ $remaining }} of {{ $limit }}</strong> question{{ $limit === 1 ? '' : 's' }} left this month
                @if ($resetsAt)
                    <span class="text-muted">· resets {{ \Illuminate\Support\Carbon::parse($resetsAt)->format('M j') }}</span>
                @endif
            @endif
        </div>

        @if ($remaining <= 1 && $tier !== \App\Services\Paywall\AskQuota::TIER_SUBSCRIBER)
            @php
                $subQuota = app(\App\Services\Paywall\AskQuota::class)
                    ->limitFor(\App\Services\Paywall\AskQuota::TIER_SUBSCRIBER);
            @endphp
            <div>
                @if ($tier === \App\Services\Paywall\AskQuota::TIER_ANONYMOUS)
                    <a class="btn btn--ghost" href="{{ url('/signup') }}">Sign up for more</a>
                @else
                    <a class="btn btn--ghost" href="{{ url('/subscribe') }}">Upgrade{{ $subQuota === -1 ? '' : ' for '.$subQuota.'/month' }}</a>
                @endif
            </div>
        @endif
    </div>
@endif
