@php
    /**
     * "Powered by CapstoneNMS" attribution. Forced on for the Solo
     * tier; suppressible on Team / Pro / Enterprise via Site Settings.
     *
     * Logic:
     *   - License's tier doesn't allow hiding → always show
     *   - License allows hiding AND admin set powered_by_hidden=1 →
     *     suppress
     *   - Otherwise show
     */
    $license = app(\App\Services\Licensing\LicenseService::class);
    $hideAllowed = $license->canHidePoweredBy();
    $hideRequested = function_exists('getcong') && (bool) getcong('powered_by_hidden');
    $show = ! ($hideAllowed && $hideRequested);
    $product = (string) config('capstone.product_name', 'CapstoneNMS');
    $url     = (string) config('capstone.product_url', 'https://capstonenms.com');
@endphp

@if ($show)
    <span class="site-footer__powered" style="font-size:0.8rem;color:var(--c-fg-muted);">
        Powered by <a href="{{ $url }}" rel="noopener" style="color:inherit;">{{ $product }}</a>
    </span>
@endif
