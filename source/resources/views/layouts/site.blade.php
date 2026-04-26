<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(getcong('rtl'))dir="rtl"@endif>
<head>
<meta charset="UTF-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0ea5e9">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="csp-nonce" content="{{ csp_nonce() }}">

{{-- Accessibility-preferences priming. Runs before paint so a
     reader's choice of high-contrast / large-text / dyslexia-font /
     reduced-motion takes effect on the very first frame, not after
     a flash-of-defaults. Server-side preference seed lives in
     `body[data-accessibility-prefs]` for authenticated readers; the
     localStorage shadow keeps anonymous prefs persistent across
     navigations and survives across devices once a reader signs in
     (server prefs win when they exist). --}}
<script nonce="{{ csp_nonce() }}">
(function () {
    try {
        var raw = localStorage.getItem('usnt_a11y_prefs');
        if (!raw) return;
        var prefs = JSON.parse(raw);
        var html = document.documentElement;
        if (prefs.high_contrast)     html.setAttribute('data-pref-contrast', 'high');
        if (prefs.large_text)        html.setAttribute('data-pref-text', 'large');
        if (prefs.dyslexia_font)     html.setAttribute('data-pref-font', 'dyslexia');
        if (prefs.reduced_motion)    html.setAttribute('data-pref-motion', 'reduced');
        if (prefs.underline_links)   html.setAttribute('data-pref-links', 'underline');
    } catch (e) { /* private mode etc. — no-op */ }
})();
</script>

<title>@yield('head_title', getcong('site_name') ?: config('app.name'))</title>

<meta name="description" content="@yield('head_description', getcong('site_description'))">
<link rel="canonical" href="@yield('head_url', url()->current())">

{{-- Favicon: prefer admin-uploaded site_favicon, fall back to a checked-in default --}}
@if (getcong('site_favicon'))
    <link rel="icon" href="{{ Storage::disk(getcong('site_storage'))->url(getcong('site_favicon')) }}">
@else
    <link rel="icon" href="{{ asset('site/img/favicon.svg') }}" type="image/svg+xml">
@endif

{{-- PWA manifest + service-worker registration. The SW is fail-soft —
     a browser without service-worker support falls through cleanly. --}}
<link rel="manifest" href="{{ asset('site/manifest.webmanifest') }}">
<meta name="application-name" content="{{ getcong('site_name') ?: config('app.name') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ getcong('site_name') ?: config('app.name') }}">

{{-- Open Graph + Twitter --}}
<meta property="og:site_name" content="{{ getcong('site_name') ?: config('app.name') }}">
<meta property="og:type" content="@yield('head_type', 'website')">
<meta property="og:title" content="@yield('head_title', getcong('site_name'))">
<meta property="og:description" content="@yield('head_description', getcong('site_description'))">
<meta property="og:image" content="@yield('head_image', getcong('site_logo') ? Storage::disk(getcong('site_storage'))->url(getcong('site_logo')) : asset('site/img/og-default.png'))">
<meta property="og:url" content="@yield('head_url', url()->current())">
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@yield('head_title', getcong('site_name'))">
<meta name="twitter:description" content="@yield('head_description', getcong('site_description'))">
<meta name="twitter:image" content="@yield('head_image', getcong('site_logo') ? Storage::disk(getcong('site_storage'))->url(getcong('site_logo')) : asset('site/img/og-default.png'))">
@hasSection('head_published_time')
<meta property="article:published_time" content="@yield('head_published_time')">
@endif
@hasSection('head_modified_time')
<meta property="article:modified_time" content="@yield('head_modified_time')">
@endif
@hasSection('head_section')
<meta property="article:section" content="@yield('head_section')">
@endif
@hasSection('head_author')
<meta property="article:author" content="@yield('head_author')">
@endif

{{-- Discoverability --}}
<link rel="alternate" type="application/rss+xml" title="{{ getcong('site_name') }} RSS" href="{{ url('/feed.xml') }}">
<link rel="alternate" type="application/atom+xml" title="{{ getcong('site_name') }} Atom" href="{{ url('/feed') }}">

@hasSection('head_hreflang')
@yield('head_hreflang')
@endif

{{-- Site styles --}}
<link rel="stylesheet" href="{{ asset('site/site.css') }}?v={{ filemtime(public_path('site/site.css')) }}">

@hasSection('head_jsonld')
@yield('head_jsonld')
@endif

@include('partials.site.analytics')

@if (getcong('site_header_code'))
{!! nonce_inject_html(stripslashes(getcong('site_header_code'))) !!}
@endif
</head>
<body @auth data-prefs-user-id="{{ auth()->id() }}" @endauth>
{{-- Skip-link cluster: keyboard users get straight access to the
     three landmarks they care about. visible on :focus only. --}}
<div class="skip-links" role="navigation" aria-label="Skip links">
    <a href="#main" class="skip-link">Skip to main content</a>
    <a href="#article-comments" class="skip-link">Skip to comments</a>
    <a href="#site-footer" class="skip-link">Skip to footer</a>
</div>

{{-- Single source of truth for screen-reader announcements. site.js's
     showFlash() pipes copy into this live region; AJAX state changes
     (vote results, like counts, new live entries) also write here. --}}
<div id="sr-announce" class="sr-only" aria-live="polite" aria-atomic="true"></div>

@yield('reading_progress')
@include('partials.site.breaking')
@include('partials.site.masthead')
@include('partials.site.header')

<div class="container">@include('partials.site.ad-slot', ['placement' => 'header'])</div>

<main id="main" tabindex="-1">
    {{-- Server-rendered flash messages now live inside an aria-live
         region so they're announced on first render too, not just
         when JS pushes new ones in. --}}
    @if (session('flash_message'))
        <div class="container"><div class="flash flash--ok" role="status" aria-live="polite">{{ session('flash_message') }}</div></div>
    @endif
    @if (session('error_flash_message'))
        <div class="container"><div class="flash flash--err" role="alert">{{ session('error_flash_message') }}</div></div>
    @endif

    @yield('content')
</main>

<div class="container">@include('partials.site.ad-slot', ['placement' => 'footer'])</div>

<div id="site-footer">
@include('partials.site.footer')
</div>
@include('partials.site.cookie-consent')

<script src="{{ asset('site/site.js') }}?v={{ filemtime(public_path('site/site.js')) }}" defer nonce="{{ csp_nonce() }}"></script>

@if (getcong('site_footer_code'))
{!! nonce_inject_html(stripslashes(getcong('site_footer_code'))) !!}
@endif

@stack('scripts')
</body>
</html>
