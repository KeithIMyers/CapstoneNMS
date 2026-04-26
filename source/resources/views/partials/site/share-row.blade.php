{{-- Social share row. Server-rendered intent URLs; no third-party
     tracker scripts, so this stays cache-safe. Mastodon prompts the
     user for their home instance because there's no fixed share URL
     the way other networks have. Copy-link uses navigator.clipboard
     with a flash fallback. --}}
@php
    $shareUrl   = $news->canonical_url ?: route('news.details', ['slug' => $news->slug]);
    $shareTitle = strip_tags(stripslashes((string) $news->title));
    $u = rawurlencode($shareUrl);
    $t = rawurlencode($shareTitle);
@endphp

<div class="share-row" aria-label="Share this article">
    <span class="share-row__label">Share:</span>

    <a class="share-row__btn" target="_blank" rel="noopener noreferrer"
       href="https://twitter.com/intent/tweet?url={{ $u }}&text={{ $t }}"
       aria-label="Share on X / Twitter">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" aria-hidden="true">
            <path d="M17.53 3H21l-7.5 8.57L22 21h-6.79l-5.31-6.94L3.7 21H.22l8.04-9.18L0 3h6.96l4.81 6.36L17.53 3zm-1.2 16h1.92L7.74 5H5.7l10.63 14z"/>
        </svg>
        <span>X</span>
    </a>

    <a class="share-row__btn" target="_blank" rel="noopener noreferrer"
       href="https://www.facebook.com/sharer/sharer.php?u={{ $u }}"
       aria-label="Share on Facebook">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" aria-hidden="true">
            <path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.51 1.49-3.9 3.78-3.9 1.1 0 2.24.2 2.24.2v2.46H15.2c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.45 2.9h-2.34v6.98A10 10 0 0 0 22 12z"/>
        </svg>
        <span>Facebook</span>
    </a>

    <a class="share-row__btn" target="_blank" rel="noopener noreferrer"
       href="https://www.linkedin.com/sharing/share-offsite/?url={{ $u }}"
       aria-label="Share on LinkedIn">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" aria-hidden="true">
            <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zM8.34 18.34H5.67V9.99h2.67v8.35zM7 8.83a1.55 1.55 0 1 1 0-3.1 1.55 1.55 0 0 1 0 3.1zm11.34 9.51h-2.67v-4.06c0-.97-.02-2.21-1.35-2.21-1.35 0-1.56 1.05-1.56 2.14v4.13H10.1V9.99h2.56v1.14h.04c.36-.68 1.23-1.4 2.53-1.4 2.7 0 3.2 1.78 3.2 4.1v4.51z"/>
        </svg>
        <span>LinkedIn</span>
    </a>

    <a class="share-row__btn" target="_blank" rel="noopener noreferrer"
       href="https://reddit.com/submit?url={{ $u }}&title={{ $t }}"
       aria-label="Share on Reddit">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" aria-hidden="true">
            <path d="M22 11.5a2.2 2.2 0 0 0-3.7-1.6 10.6 10.6 0 0 0-5.6-1.7l1-4.6 3.3.7a1.5 1.5 0 1 0 .2-1L13.3 2.2a.5.5 0 0 0-.6.4l-1.1 5.1a10.7 10.7 0 0 0-5.7 1.7A2.2 2.2 0 1 0 3.4 13a4.2 4.2 0 0 0 0 .6c0 3.3 3.8 6 8.6 6s8.6-2.7 8.6-6a4.2 4.2 0 0 0 0-.6 2.2 2.2 0 0 0 1.4-1.5zM7.5 13a1.5 1.5 0 1 1 1.5 1.5A1.5 1.5 0 0 1 7.5 13zm8.6 4a5.5 5.5 0 0 1-4.1 1.4 5.5 5.5 0 0 1-4.1-1.4.4.4 0 0 1 .6-.6 4.7 4.7 0 0 0 3.5 1.2 4.7 4.7 0 0 0 3.5-1.2.4.4 0 1 1 .6.6zm-.1-2.5a1.5 1.5 0 1 1 1.5-1.5 1.5 1.5 0 0 1-1.5 1.5z"/>
        </svg>
        <span>Reddit</span>
    </a>

    <button type="button" class="share-row__btn" data-share-mastodon
            data-share-url="{{ $shareUrl }}"
            data-share-title="{{ e($shareTitle) }}"
            aria-label="Share on Mastodon">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" aria-hidden="true">
            <path d="M21.6 7.2c0-4.5-3-5.8-3-5.8A20.5 20.5 0 0 0 12.6 0a20.5 20.5 0 0 0-6 1.4S3.4 2.7 3.4 7.2a99 99 0 0 0 0 5.5c.2 4.7 1 8.7 5.5 9.8 2 .6 3.7.7 5 .6 2.4-.1 3.7-.8 3.7-.8v-1.7s-1.7.5-3.6.5c-1.9 0-3.9-.1-4.2-2.4l-.1-.5s2 .5 4.5.6c1.6 0 3 0 4.5-.2 2.9-.4 5.4-2.1 5.7-3.7.5-2.5.5-6.1.5-7.7zM18 12.4h-2.2V7.5c0-1.1-.5-1.6-1.4-1.6s-1.4.6-1.4 1.7v2.7h-2.2V7.6c0-1.1-.5-1.7-1.4-1.7s-1.4.5-1.4 1.6v4.9H6V7.4c0-1.1.3-1.9.8-2.5.6-.6 1.4-.9 2.4-.9 1.1 0 2 .5 2.6 1.4l.5.9.5-.9c.6-.9 1.5-1.4 2.6-1.4 1 0 1.8.3 2.4.9.6.6.8 1.4.8 2.5v5z"/>
        </svg>
        <span>Mastodon</span>
    </button>

    <button type="button" class="share-row__btn" data-share-copy
            data-share-url="{{ $shareUrl }}"
            aria-label="Copy link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        </svg>
        <span>Copy link</span>
    </button>
</div>
