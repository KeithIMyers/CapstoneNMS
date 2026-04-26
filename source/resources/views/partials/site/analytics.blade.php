{{-- Analytics injection. Reads `analytics_provider` + the per-provider
     id/key from settings (managed in the Filament Site Settings →
     Analytics tab) and renders the right snippet.

     Bot filter: skip every analytics snippet on requests whose User-Agent
     looks like a crawler. Saves the bill on GA4 / Plausible / etc.

     Honor-DNT: skip when the visitor sends Do-Not-Track. Plausible /
     Fathom / Cloudflare are privacy-friendly anyway, but we treat the
     header consistently across providers. --}}
@php
    $provider = function_exists('getcong') ? trim((string) getcong('analytics_provider')) : '';
    $ua = strtolower((string) request()->header('User-Agent'));
    $isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|fetcher|scrape|wget|curl|python-requests|httpclient/i', $ua);
    $dnt = request()->header('DNT') === '1' || request()->header('Sec-GPC') === '1';
    $skip = $provider === '' || $provider === 'none' || $isBot || $dnt;
@endphp

@unless ($skip)
    @switch($provider)

        {{-- Google Analytics 4 — gtag.js loader --}}
        @case('ga4')
            @php $id = trim((string) getcong('analytics_ga4_id')); @endphp
            @if ($id !== '')
                <script async src="https://www.googletagmanager.com/gtag/js?id={{ $id }}" nonce="{{ csp_nonce() }}"></script>
                <script nonce="{{ csp_nonce() }}">
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', @json($id), { anonymize_ip: true });
                </script>
            @endif
            @break

        {{-- Plausible — plain self-hosted or plausible.io --}}
        @case('plausible')
            @php
                $domain = trim((string) getcong('analytics_plausible_domain'));
                $host   = trim((string) getcong('analytics_plausible_host')) ?: 'plausible.io';
            @endphp
            @if ($domain !== '')
                <script defer data-domain="{{ $domain }}" src="https://{{ $host }}/js/script.js" nonce="{{ csp_nonce() }}"></script>
            @endif
            @break

        {{-- Fathom Analytics --}}
        @case('fathom')
            @php $site = trim((string) getcong('analytics_fathom_site_id')); @endphp
            @if ($site !== '')
                <script src="https://cdn.usefathom.com/script.js" data-site="{{ $site }}" defer nonce="{{ csp_nonce() }}"></script>
            @endif
            @break

        {{-- Cloudflare Web Analytics (free, privacy-friendly) --}}
        @case('cloudflare')
            @php $token = trim((string) getcong('analytics_cloudflare_token')); @endphp
            @if ($token !== '')
                <script defer src="https://static.cloudflareinsights.com/beacon.min.js"
                    nonce="{{ csp_nonce() }}"
                    data-cf-beacon='{!! json_encode(['token' => $token], JSON_UNESCAPED_SLASHES) !!}'></script>
            @endif
            @break

        {{-- Umami (self-hosted analytics) --}}
        @case('umami')
            @php
                $website = trim((string) getcong('analytics_umami_website_id'));
                $script  = trim((string) getcong('analytics_umami_script_url'));
            @endphp
            @if ($website !== '' && $script !== '')
                <script async defer src="{{ $script }}" data-website-id="{{ $website }}" nonce="{{ csp_nonce() }}"></script>
            @endif
            @break

        {{-- Custom: free-form HTML the admin pastes. Useful for less-common
             vendors (Matomo Cloud, Mixpanel page-load script, etc). The
             value is stored in analytics_custom_html. --}}
        @case('custom')
            @php $html = (string) getcong('analytics_custom_html'); @endphp
            @if (trim($html) !== '')
                {!! nonce_inject_html($html) !!}
            @endif
            @break

    @endswitch
@endunless
