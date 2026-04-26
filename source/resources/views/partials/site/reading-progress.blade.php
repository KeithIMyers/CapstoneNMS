{{-- Reading progress indicator. Renders a 3px-tall accent bar sticky
     to the very top of the viewport and grows from 0% to 100% as the
     user scrolls through the article body.

     Expects: $news (the rendered article). The track-element id is
     reused from the existing scroll-depth tracker so we don't add a
     second scroll handler — site.js drives both. --}}
@if (! empty($news))
    <div class="reading-progress" aria-hidden="true">
        <div class="reading-progress__bar" data-reading-progress></div>
    </div>
@endif
