{{-- Minimal cookie consent banner. Pure CSS / JS; no third-party SDK.
     Stores acceptance in localStorage so server-side rendering stays
     cacheable. CCPA / UK PECR / GDPR-compliant for a first-party-only
     site that doesn't drop tracking cookies — the banner explains
     what's stored and links to the privacy policy. --}}
<div id="cookie-consent" hidden role="dialog" aria-live="polite" aria-label="Cookie notice"
     class="cookie-consent">
    <div class="cookie-consent__inner">
        <p class="cookie-consent__text">
            We use a small number of first-party cookies to keep you signed in,
            remember your theme choice, and count article reads. We don't sell
            your data and we don't run third-party tracking scripts.
            @if (\App\Models\Pages::where('page_slug', 'privacy')->exists())
                <a href="{{ url('/pages/privacy') }}">Read the privacy policy</a>.
            @endif
        </p>
        <div class="cookie-consent__actions">
            <button type="button" class="btn cookie-consent__accept">Got it</button>
        </div>
    </div>
</div>
<script nonce="{{ csp_nonce() }}">
(() => {
    const KEY = 'usnt_cookie_ok';
    const banner = document.getElementById('cookie-consent');
    if (!banner) return;
    try {
        if (localStorage.getItem(KEY) === '1') return;
    } catch (e) { /* private mode — show banner each session */ }
    banner.hidden = false;
    banner.querySelector('.cookie-consent__accept')?.addEventListener('click', () => {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
        banner.hidden = true;
    });
})();
</script>
