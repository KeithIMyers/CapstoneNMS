@php
    /**
     * Top-of-page deterrent banner. Shown to every visitor whenever
     * the running install isn't operating under a production license.
     *
     * Trigger conditions:
     *   - License missing / unverifiable    → "unlicensed" copy
     *   - License kind = development        → "dev build" copy
     *   - License kind = trial              → "trial" copy w/ days remaining
     *   - License kind = production         → not rendered
     *
     * The banner is intentionally not suppressible by site_settings —
     * even on tiers whose license permits hiding the "Powered by"
     * footer, this banner is forced because its purpose is to make
     * an unlicensed / dev install visually distinguishable from a
     * paying customer's deployment.
     */
    $license = app(\App\Services\Licensing\LicenseService::class);
    $status  = $license->status();
    $kind    = $status['kind'] ?? null;

    $show = false;
    $tone = 'warn';
    $copy = null;

    if ($status['state'] === 'unlicensed' || $status['state'] === 'invalid') {
        $show = true;
        $tone = 'err';
        $copy = 'This installation of CapstoneNMS is not licensed. Report unlicensed use to support@capstonenms.com.';
    } elseif ($kind === 'development') {
        $show = true;
        $tone = 'warn';
        $copy = 'This site is using a build of CapstoneNMS intended for development and testing. Report unlicensed use to support@capstonenms.com.';
    } elseif ($kind === 'trial') {
        $show = true;
        $tone = 'info';
        $days = isset($status['expires_at']) && $status['expires_at']
            ? max(0, (int) \Illuminate\Support\Carbon::parse($status['expires_at'])->diffInDays(\Illuminate\Support\Carbon::now(), false) * -1)
            : null;
        $copy = $days !== null
            ? sprintf('CapstoneNMS trial — %d day%s remaining. Visit capstonenms.com to purchase.', $days, $days === 1 ? '' : 's')
            : 'This site is running a CapstoneNMS trial. Visit capstonenms.com to purchase.';
    }
@endphp

@if ($show)
    @php
        $bg = match ($tone) {
            'err'  => '#7f1d1d',
            'warn' => '#92400e',
            default => '#1e3a8a',
        };
    @endphp
    <div role="status" aria-live="polite"
         style="background:{{ $bg }};color:#fff;padding:0.55rem 1rem;text-align:center;font-size:0.85rem;line-height:1.35;border-bottom:1px solid rgba(0,0,0,0.2);">
        {{ $copy }}
    </div>
@endif
