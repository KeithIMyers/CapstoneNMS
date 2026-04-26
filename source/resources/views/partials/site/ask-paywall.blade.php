{{--
    Hard paywall shown when the visitor has consumed their tier's
    monthly Ask quota. The CTA is tier-aware:
      anonymous  → sign up (free) for the registered tier
      registered → subscribe (paid) for the subscriber tier
      subscriber → "you've used your monthly allowance" (no CTA)
--}}
@php
    $tier      = $quota['tier'];
    $limit     = (int) $quota['limit'];
    $used      = (int) $quota['used'];
    $resetsAt  = $quota['resets_at'] ?? null;

    // Resolve the *next* tier's allowance via the service so settings
    // overrides apply consistently. Avoids a separate getcong+cast
    // path that could return 0 for an unset key.
    $svc      = app(\App\Services\Paywall\AskQuota::class);
    $regQuota = $svc->limitFor(\App\Services\Paywall\AskQuota::TIER_REGISTERED);
    $subQuota = $svc->limitFor(\App\Services\Paywall\AskQuota::TIER_SUBSCRIBER);
@endphp

<section class="aside-block" style="margin-block:1.5rem;padding:1.75rem;background:var(--c-paper);border-radius:var(--radius-lg);text-align:center;">
    <div style="font-size:2rem;line-height:1;margin-bottom:0.5rem;" aria-hidden="true">🔒</div>

    @if ($tier === \App\Services\Paywall\AskQuota::TIER_ANONYMOUS)
        <h2 style="margin:0 0 0.5rem;">Sign up to keep asking</h2>
        <p style="margin:0 auto 1.25rem;max-width:36rem;">
            You've used all {{ $limit }} of this month's free questions for this network. Create a free
            account to get <strong>{{ $regQuota }} questions every month</strong>, or subscribe for
            <strong>{{ $subQuota }}/month</strong>.
        </p>
        <div style="display:flex;justify-content:center;gap:0.75rem;flex-wrap:wrap;">
            <a class="btn" href="{{ url('/signup') }}">Sign up free</a>
            <a class="btn btn--ghost" href="{{ url('/subscribe') }}">See subscriber plans</a>
        </div>
    @elseif ($tier === \App\Services\Paywall\AskQuota::TIER_REGISTERED)
        <h2 style="margin:0 0 0.5rem;">You've hit your monthly limit</h2>
        <p style="margin:0 auto 1.25rem;max-width:36rem;">
            Your free account includes {{ $limit }} questions per month, and you've used them all.
            Subscribe for <strong>{{ $subQuota }} questions every month</strong> plus full access to
            premium articles.
        </p>
        <div style="display:flex;justify-content:center;gap:0.75rem;flex-wrap:wrap;">
            <a class="btn" href="{{ url('/subscribe') }}">Subscribe</a>
            @if ($resetsAt)
                <span class="text-muted" style="align-self:center;">or wait until {{ \Illuminate\Support\Carbon::parse($resetsAt)->format('M j') }}</span>
            @endif
        </div>
    @else
        <h2 style="margin:0 0 0.5rem;">Monthly allowance reached</h2>
        <p style="margin:0 auto 1.25rem;max-width:36rem;">
            You've used all {{ $limit }} of your subscriber questions this month. Your allowance resets
            @if ($resetsAt) on {{ \Illuminate\Support\Carbon::parse($resetsAt)->format('M j') }}@endif.
            Reach out to the newsroom if you need a higher cap.
        </p>
        <div>
            <a class="btn btn--ghost" href="{{ url('/pages/contact-us') }}">Contact us</a>
        </div>
    @endif
</section>
