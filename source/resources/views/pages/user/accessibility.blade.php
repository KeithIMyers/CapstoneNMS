@extends('layouts.site')

@section('head_title', 'Accessibility preferences · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Accessibility preferences</h1>
    <p class="text-muted" style="margin-block:0.5rem 1.5rem;">
        Tune how the site looks and behaves to fit how you read. Choices apply immediately and persist across pages on this device.
        @auth — your selection also syncs across browsers when you sign in. @endauth
    </p>

    @if (session('flash_message'))
        <div class="flash flash--ok" role="status" aria-live="polite">{{ session('flash_message') }}</div>
    @endif

    <form method="post" action="{{ route('accessibility.update') }}" id="accessibility-form" class="card" style="padding:1.5rem;">
        @csrf

        <fieldset style="border:0; padding:0; margin:0 0 1.25rem;">
            <legend style="font-weight:600; margin-bottom:0.5rem; padding:0;">Sight + readability</legend>

            <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.85rem;">
                <input type="checkbox" name="high_contrast" value="1" {{ ! empty($prefs['high_contrast']) ? 'checked' : '' }}
                       data-a11y-pref="high_contrast"
                       aria-describedby="hc-help">
                <span>
                    <strong>High contrast</strong>
                    <span id="hc-help" class="text-muted" style="display:block; font-size:0.85rem;">
                        Pure black on white (or white on black in dark mode), heavier borders, AAA-targeted text contrast.
                    </span>
                </span>
            </label>

            <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.85rem;">
                <input type="checkbox" name="large_text" value="1" {{ ! empty($prefs['large_text']) ? 'checked' : '' }}
                       data-a11y-pref="large_text"
                       aria-describedby="lt-help">
                <span>
                    <strong>Larger text</strong>
                    <span id="lt-help" class="text-muted" style="display:block; font-size:0.85rem;">
                        Bumps the root font-size by ~19%. All headings, body text, and forms scale together.
                    </span>
                </span>
            </label>

            <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.85rem;">
                <input type="checkbox" name="dyslexia_font" value="1" {{ ! empty($prefs['dyslexia_font']) ? 'checked' : '' }}
                       data-a11y-pref="dyslexia_font"
                       aria-describedby="df-help">
                <span>
                    <strong>Dyslexia-friendly font</strong>
                    <span id="df-help" class="text-muted" style="display:block; font-size:0.85rem;">
                        Switches body, form, and button text to Atkinson Hyperlegible (or Verdana / Tahoma fallback). Headings keep the editorial face.
                    </span>
                </span>
            </label>

            <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.85rem;">
                <input type="checkbox" name="underline_links" value="1" {{ ! empty($prefs['underline_links']) ? 'checked' : '' }}
                       data-a11y-pref="underline_links"
                       aria-describedby="ul-help">
                <span>
                    <strong>Always underline links</strong>
                    <span id="ul-help" class="text-muted" style="display:block; font-size:0.85rem;">
                        Color alone isn't always enough. Force every link to be underlined.
                    </span>
                </span>
            </label>
        </fieldset>

        <fieldset style="border:0; padding:0; margin:0 0 1.25rem;">
            <legend style="font-weight:600; margin-bottom:0.5rem; padding:0;">Motion + audio</legend>

            <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.85rem;">
                <input type="checkbox" name="reduced_motion" value="1" {{ ! empty($prefs['reduced_motion']) ? 'checked' : '' }}
                       data-a11y-pref="reduced_motion"
                       aria-describedby="rm-help">
                <span>
                    <strong>Reduce motion</strong>
                    <span id="rm-help" class="text-muted" style="display:block; font-size:0.85rem;">
                        Disables fade-ins, slides, and smooth scrolling. Overrides whatever your OS reports.
                    </span>
                </span>
            </label>

            <label style="display:flex; align-items:start; gap:0.6rem; margin-bottom:0.85rem;">
                <input type="checkbox" name="autoplay_tts" value="1" {{ ! empty($prefs['autoplay_tts']) ? 'checked' : '' }}
                       data-a11y-pref="autoplay_tts"
                       aria-describedby="tts-help">
                <span>
                    <strong>Auto-start "Read aloud" on articles</strong>
                    <span id="tts-help" class="text-muted" style="display:block; font-size:0.85rem;">
                        When an article has a generated narration, the audio player starts playing as soon as you open the page.
                    </span>
                </span>
            </label>
        </fieldset>

        <button class="btn" type="submit">Save preferences</button>
    </form>

    <details style="margin-top:1.5rem;">
        <summary style="cursor:pointer; font-weight:600;">Keyboard shortcuts</summary>
        <dl style="margin:1rem 0 0; line-height:1.7;">
            <dt><kbd>Tab</kbd></dt><dd>Move forward through interactive elements</dd>
            <dt><kbd>Shift</kbd>+<kbd>Tab</kbd></dt><dd>Move backward</dd>
            <dt><kbd>Esc</kbd></dt><dd>Close mobile menu / dialogs</dd>
            <dt>Skip links</dt><dd>Tab from the top of any page to jump straight to main content, comments, or footer.</dd>
        </dl>
    </details>

    {{-- Inline JS that mirrors form state into localStorage so prefs
         apply on the *very next page load* (and on this page after
         submit) without a server round-trip. The head-script in the
         layout reads localStorage before paint. --}}
    <script nonce="{{ csp_nonce() }}">
    (function () {
        const KEY = 'usnt_a11y_prefs';
        const form = document.getElementById('accessibility-form');
        if (! form) return;

        function persist() {
            const prefs = {};
            form.querySelectorAll('[data-a11y-pref]').forEach((cb) => {
                prefs[cb.dataset.a11yPref] = cb.checked;
            });
            try { localStorage.setItem(KEY, JSON.stringify(prefs)); } catch (e) {}
            // Apply immediately to the live page.
            const html = document.documentElement;
            html.toggleAttribute('data-pref-contrast', !!prefs.high_contrast);
            if (prefs.high_contrast) html.setAttribute('data-pref-contrast', 'high');
            else html.removeAttribute('data-pref-contrast');
            if (prefs.large_text) html.setAttribute('data-pref-text', 'large'); else html.removeAttribute('data-pref-text');
            if (prefs.dyslexia_font) html.setAttribute('data-pref-font', 'dyslexia'); else html.removeAttribute('data-pref-font');
            if (prefs.reduced_motion) html.setAttribute('data-pref-motion', 'reduced'); else html.removeAttribute('data-pref-motion');
            if (prefs.underline_links) html.setAttribute('data-pref-links', 'underline'); else html.removeAttribute('data-pref-links');
        }

        form.querySelectorAll('[data-a11y-pref]').forEach((cb) => {
            cb.addEventListener('change', persist);
        });
        // Also persist on form submit so the localStorage shadow
        // survives even when the server-side store fails.
        form.addEventListener('submit', persist);
    })();
    </script>
</div>
@endsection
