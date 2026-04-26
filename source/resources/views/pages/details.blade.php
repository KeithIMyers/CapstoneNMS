@extends('layouts.site')

@php
    use App\Models\News;
    $publishedAt = $news->effectivePublishedAt();
    $modifiedAt = $news->updated_at ?? $publishedAt;
    $articleUrl = $news->canonical_url ?: route('news.details', ['slug' => $news->slug]);
    $heroSrc = $news->image
        ? image_src($news->image)
        : null;

    // $related is provided by NewsController@details (tag-overlap scored).

    // Per-article schema.org variant. `news.article_type` was added
    // in P13.C.2 — Google News uses this as a discovery signal so
    // an opinion piece doesn't get crawled as straight reportage.
    $schemaTypeMap = [
        'reportage'  => 'ReportageNewsArticle',
        'opinion'    => 'OpinionNewsArticle',
        'review'     => 'ReviewNewsArticle',
        'analysis'   => 'AnalysisNewsArticle',
        'background' => 'BackgroundNewsArticle',
    ];
    $schemaType = $schemaTypeMap[$news->article_type ?? 'reportage'] ?? 'NewsArticle';

    $jsonld = array_filter([
        '@context' => 'https://schema.org',
        '@type' => $schemaType,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $articleUrl],
        'headline' => mb_substr(strip_tags(stripslashes($news->title)), 0, 110),
        'description' => $news->metaDescription(),
        'image' => $heroSrc ? [$heroSrc] : null,
        'datePublished' => optional($publishedAt)->toAtomString(),
        'dateModified' => optional($modifiedAt)->toAtomString(),
        'author' => [['@type' => 'Person', 'name' => optional($news->user)->name ?: 'Editorial staff']],
        'publisher' => [
            '@type' => 'Organization',
            'name' => getcong('site_name') ?: config('app.name'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => getcong('site_logo')
                    ? Storage::disk(getcong('site_storage'))->url(getcong('site_logo'))
                    : asset('site/img/favicon.svg'),
            ],
        ],
        'articleSection' => optional($news->category)->name,
        'keywords' => $news->tags ? array_map('trim', explode(',', $news->tags)) : null,
    ], fn ($v) => $v !== null && $v !== '');
@endphp

@section('head_title', stripslashes($news->metaTitle()).' · '.getcong('site_name'))
@section('head_description', $news->metaDescription())
@section('head_image', $heroSrc ?: asset('site/img/og-default.png'))
@section('head_url', $articleUrl)
@section('head_type', 'article')
@section('head_published_time', optional($publishedAt)->toAtomString())
@section('head_modified_time', optional($modifiedAt)->toAtomString())
@section('head_section', optional($news->category)->name)
@section('head_author', optional($news->user)->name)

@section('head_jsonld')
<script type="application/ld+json">@php echo json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); @endphp</script>
@endsection

{{-- Reading-progress bar lives outside the main grid so it can pin
     to the very top of the viewport. The fill width is updated by the
     scroll handler in site.js using the existing data-track-scroll
     element as the reference. --}}
@section('reading_progress')
@include('partials.site.reading-progress', ['news' => $news])
@endsection

@php
    // Language alternates: self + any translation siblings. Emitted as
    // <link rel="alternate" hreflang> for Google, and rendered inline as
    // a compact language switcher near the byline.
    $defaultLocale = config('locales.default', 'en');
    $supportedLocales = config('locales.supported', []);
    $siblings = $news->translationSiblings()->get();
    $langAlternates = collect([$news])->concat($siblings)->filter(fn ($n) => isset($supportedLocales[$n->locale ?? $defaultLocale]));
@endphp

@if ($langAlternates->count() > 1)
    @section('head_hreflang')
    @foreach ($langAlternates as $alt)
        @php
            $altLocale = $alt->locale ?: $defaultLocale;
            $altUrl = $altLocale === $defaultLocale
                ? route('news.details', ['slug' => $alt->slug])
                : route('news.details.localized', ['locale' => $altLocale, 'slug' => $alt->slug]);
        @endphp
        <link rel="alternate" hreflang="{{ $altLocale }}" href="{{ $altUrl }}">
        @if ($altLocale === $defaultLocale)
            <link rel="alternate" hreflang="x-default" href="{{ $altUrl }}">
        @endif
    @endforeach
    @endsection
@endif

@section('content')

<article class="container article" data-track-scroll="{{ $news->id }}">
    @if (! empty($news->is_sponsored))
        <div class="sponsored-banner" role="note" aria-label="Sponsored content">
            <span class="sponsored-banner__label">Sponsored</span>
            @if (!empty($news->sponsor_label))
                <span class="sponsored-banner__by">· {{ $news->sponsor_label }}</span>
            @endif
        </div>
    @endif
    <header class="article__header">
        @if (!empty($news->kicker))
            <span class="article__kicker">{{ stripslashes($news->kicker) }}</span>
        @elseif ($news->category)
            <a href="{{ route('category_news', ['slug' => $news->category->slug]) }}" class="article__kicker">{{ $news->category->name }}</a>
        @endif

        <h1 class="article__title">{{ stripslashes($news->title) }}</h1>

        @if (!empty($news->subtitle))
            <p class="article__subtitle">{{ stripslashes($news->subtitle) }}</p>
        @endif

        @if ($siblings->count())
            <div class="lang-switch" aria-label="Available languages" style="margin-top:0.75rem; display:flex; gap:0.5rem; flex-wrap:wrap; font-size:0.85rem;">
                <span class="text-muted">Read in:</span>
                @foreach ($langAlternates as $alt)
                    @php
                        $altLocale = $alt->locale ?: $defaultLocale;
                        $altUrl = $altLocale === $defaultLocale
                            ? route('news.details', ['slug' => $alt->slug])
                            : route('news.details.localized', ['locale' => $altLocale, 'slug' => $alt->slug]);
                        $isCurrent = $alt->id === $news->id;
                    @endphp
                    <a href="{{ $altUrl }}"
                       hreflang="{{ $altLocale }}"
                       @if ($isCurrent) aria-current="true" style="font-weight:700;" @endif>
                        {{ $supportedLocales[$altLocale] ?? $altLocale }}
                    </a>
                @endforeach
            </div>
        @endif

        @php $factCheck = $news->factCheckBadge(); @endphp
        @if ($factCheck)
            <p class="article__factcheck" style="margin-top:0.75rem;">
                <span class="fact-check fact-check--{{ $factCheck['tone'] }}" title="Fact-check status: {{ $factCheck['label'] }}">
                    &#x2713; {{ $factCheck['label'] }}
                </span>
            </p>
        @endif

        <div class="article__meta">
            @php
                $bylines = collect();
                if ($news->user) {
                    $bylines->push(['user' => $news->user, 'role' => 'primary']);
                }
                foreach ($news->authors ?? [] as $coa) {
                    if ($coa->id === optional($news->user)->id) continue;
                    $bylines->push(['user' => $coa, 'role' => $coa->pivot->role ?? 'contributor']);
                }
            @endphp

            @if ($bylines->isNotEmpty())
                <span class="article__byline">
                    By
                    @foreach ($bylines as $i => $b)
                        @if ($i > 0){{ $loop->last ? ' and ' : ', ' }}@endif
                        <a href="{{ $b['user']->profileUrl() }}">{{ $b['user']->name }}</a>
                    @endforeach
                </span>
            @endif
            @if ($publishedAt)
                <time datetime="{{ $publishedAt->toAtomString() }}">{{ $publishedAt->format('F j, Y · g:i a') }}</time>
            @endif
            @if (!empty($news->reading_time_minutes))
                <span>{{ $news->reading_time_minutes }} min read</span>
            @endif
            @if ($news->views)
                <span>{{ number_format_short($news->views, 0) }} views</span>
            @endif
        </div>
    </header>

    @if ($heroSrc)
        <div class="article__hero">
            <figure>
                @include('partials.article-image', ['news' => $news])
                @if (!empty($news->image_caption) || !empty($news->image_credit))
                    <figcaption>
                        @if (!empty($news->image_caption)) {{ stripslashes($news->image_caption) }} @endif
                        @if (!empty($news->image_credit)) <span class="credit">— {{ stripslashes($news->image_credit) }}</span> @endif
                    </figcaption>
                @endif
            </figure>
        </div>
    @endif

    {{-- Browser-language nudge. Shown when the visitor's preferred
         locale (Accept-Language or saved cookie) differs from the
         active locale AND the article has a sibling in that locale.
         JS dismiss handler stamps a sessionStorage flag so the nudge
         appears at most once per session. --}}
    @php
        $preferred = function_exists('preferred_locale') ? preferred_locale() : null;
        $preferredSibling = null;
        if ($preferred && $siblings->isNotEmpty()) {
            $preferredSibling = $siblings->first(fn ($s) => ($s->locale ?? null) === $preferred);
        }
    @endphp
    @if ($preferredSibling)
        @php
            $preferredUrl = $preferred === ($defaultLocale ?? 'en')
                ? route('news.details', ['slug' => $preferredSibling->slug])
                : route('news.details.localized', ['locale' => $preferred, 'slug' => $preferredSibling->slug]);
            $preferredLabel = $supportedLocales[$preferred] ?? $preferred;
        @endphp
        <div class="lang-nudge" role="note" data-lang-nudge="{{ $preferred }}">
            <span>This article is also available in <strong>{{ $preferredLabel }}</strong>.</span>
            <a class="lang-nudge__btn" href="{{ $preferredUrl }}">Read in {{ $preferredLabel }}</a>
            <button type="button" class="lang-nudge__close" aria-label="Dismiss">×</button>
        </div>
    @endif

    {{-- NotebookLM-style podcast player. Renders only when an MP3
         exists for this article. Subscribers + non-paywalled visitors
         alike get the audio — it's a summary aid, not a paywall
         alternative. --}}
    @if (! empty($news->podcast_audio_path))
        <aside class="article-podcast" aria-label="Listen to this article">
            <div class="article-podcast__head">
                <strong>Listen to a 2-minute deep dive</strong>
                <span class="text-muted" style="font-size:0.78rem;letter-spacing:0.04em;text-transform:uppercase;">AI-generated</span>
            </div>
            <audio controls preload="none" style="width:100%;margin-top:0.4rem;"
                   src="{{ image_src($news->podcast_audio_path) }}{{ $news->podcast_generated_at ? '?v='.$news->podcast_generated_at->timestamp : '' }}"></audio>
        </aside>
    @endif

    @if (! empty($news->tts_audio_path))
        <aside class="article-tts" aria-label="Read this article aloud" style="margin-block:1rem;padding:0.85rem 1rem;background:var(--c-paper);border:1px solid var(--c-border);border-radius:var(--radius-md);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.4rem;">
                <strong style="font-size:0.95rem;">🔊 Read aloud</strong>
                <span class="text-muted" style="font-size:0.75rem;letter-spacing:0.04em;text-transform:uppercase;">AI narration{{ $news->tts_voice ? ' · '.$news->tts_voice : '' }}</span>
            </div>
            <audio controls preload="none" style="width:100%;"
                   src="{{ image_src($news->tts_audio_path) }}{{ $news->tts_generated_at ? '?v='.$news->tts_generated_at->timestamp : '' }}"></audio>
        </aside>
    @endif

    <div class="prose">
        @if (!empty($news->dateline))
            <span class="article__dateline">{{ stripslashes($news->dateline) }}</span>
        @endif

        @if ($can_read_full ?? true)
            {{-- Block-based content takes precedence; the renderer
                 walks the JSON tree and emits sanitized HTML. The
                 legacy `content` column is the fallback for
                 articles authored before P13.C.3. --}}
            @if (is_array($news->content_blocks) && count($news->content_blocks) > 0)
                {!! app(\App\Services\Blocks\BlockRenderer::class)->render($news->content_blocks) !!}
            @else
                {!! sanitize_rich_html(stripslashes($news->content)) !!}
            @endif

            @if (!empty($news->video_embed_code))
                <div class="article__video">
                    {!! sanitize_embed_html(stripslashes($news->video_embed_code)) !!}
                </div>
            @endif
        @else
            {{-- Paywall preview: short excerpt + subscribe CTA. The full
                 body lives behind an active subscription. --}}
            <p class="article__preview">{{ $preview_body }}</p>

            <aside class="paywall" aria-label="Subscribe to read more"
                   style="margin:2rem auto;max-width:var(--container-prose);padding:1.5rem;border:1px solid var(--c-border);border-radius:var(--radius-lg);background:var(--c-paper);text-align:center;">
                <h3 style="font-family:var(--font-display);font-size:var(--t-xl);margin:0 0 0.5rem 0;">Subscribe to keep reading</h3>
                <p class="text-muted" style="margin:0 0 1rem 0;line-height:1.5;">
                    This article is for {{ getcong('site_name') ?: config('app.name') }} subscribers.
                    Sign in if you already have an account, or subscribe below.
                </p>
                <div class="cluster" style="justify-content:center;gap:0.75rem;flex-wrap:wrap;">
                    @auth
                        <a class="btn" href="{{ route('subscribe.show') }}">Subscribe</a>
                    @else
                        <a class="btn" href="{{ route('subscribe.show') }}">Subscribe</a>
                        <a class="btn btn--ghost" href="{{ route('user_login') }}">Sign in</a>
                    @endauth

                    {{-- Pay-per-article: only authenticated users can
                         buy (we need a user_id to credit). The
                         controller still re-checks tier + Stripe
                         configuration. --}}
                    @if (\App\Http\Controllers\ArticlePurchaseController::isEnabled() && auth()->check())
                        <form method="post" action="{{ route('articles.buy', ['news' => $news->id]) }}" style="display:inline;">
                            @csrf
                            <button class="btn btn--ghost" type="submit">
                                Buy this article — ${{ number_format(\App\Http\Controllers\ArticlePurchaseController::priceCents() / 100, 2) }}
                            </button>
                        </form>
                    @endif
                </div>
            </aside>
        @endif
    </div>

    {{-- In-article ad slot --}}
    @include('partials.site.ad-slot', ['placement' => 'in_article'])

    {{-- Series strap (when this article belongs to a multi-part series) --}}
    @if ($news->series_id && $news->series)
        @php
            $seriesArticles = $news->series->articles()->published()->get(['id']);
            $position = $seriesArticles->search(fn ($a) => $a->id === $news->id);
            $position = $position === false ? null : $position + 1;
        @endphp
        <aside class="aside-block" style="max-width:var(--container-prose);margin:1.5rem auto;padding:1rem 1.25rem;background:var(--c-bg-soft);border-left:3px solid #0ea5e9;border-radius:6px;">
            <span class="text-muted" style="font-size:0.78rem;letter-spacing:0.06em;text-transform:uppercase;font-weight:700;">
                @if ($position) Part {{ $position }} of {{ $seriesArticles->count() }} · @endif Series
            </span>
            <h3 style="margin:0.25rem 0 0.5rem 0;font-size:var(--t-lg);">
                <a href="{{ route('series.show', ['slug' => $news->series->slug]) }}">{{ $news->series->name }}</a>
            </h3>
            @if ($news->series->description)
                <p class="text-muted" style="margin:0;font-size:0.9rem;">{{ \Illuminate\Support\Str::limit($news->series->description, 200) }}</p>
            @endif
        </aside>
    @endif

    {{-- Sources / citations --}}
    @if ($news->sources->isNotEmpty())
        <aside class="sources" style="max-width: var(--container-prose); margin: 2rem auto;" aria-label="Sources">
            <h3>Sources</h3>
            <ol>
                @foreach ($news->sources as $src)
                    <li>
                        @if (!empty($src->url))
                            <a href="{{ $src->url }}" rel="noopener nofollow" target="_blank">{{ $src->label }}</a>
                        @else
                            {{ $src->label }}
                        @endif
                        @if (!empty($src->publisher))
                            <span class="src-pub">— {{ $src->publisher }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </aside>
    @endif

    {{-- Topics this article belongs to --}}
    @if ($news->relationLoaded('topics') ? $news->topics->isNotEmpty() : $news->topics()->exists())
        <div class="cluster" style="margin-top:1.5rem;">
            <span class="text-muted" style="font-size:var(--t-xs);">More on:</span>
            @foreach ($news->topics as $t)
                <a class="tag" href="{{ route('topics.show', ['slug' => $t->slug]) }}">{{ $t->name }}</a>
            @endforeach
        </div>
    @endif

    {{-- Corrections / updates log --}}
    @if ($news->revisions->isNotEmpty())
        <aside class="aside-block" style="max-width: var(--container-prose); margin: 2rem auto; background: var(--c-paper);" aria-label="Corrections and updates">
            <h3>Corrections &amp; updates</h3>
            <ul>
                @foreach ($news->revisions as $rev)
                    <li>
                        <span class="badge">{{ $rev->label() }}</span>
                        <time datetime="{{ $rev->created_at->toAtomString() }}" class="text-faint" style="font-size: var(--t-xs); margin-left: 0.4rem;">
                            {{ $rev->created_at->format('M j, Y · g:i a') }}
                        </time>
                        <p style="margin-top:0.4rem;">{{ $rev->note }}</p>
                    </li>
                @endforeach
            </ul>
        </aside>
    @endif

    {{-- Author bio strip --}}
    @if ($news->user && ($news->user->bio || $news->authors->count()))
        <aside class="aside-block" style="max-width: var(--container-prose); margin: 2rem auto;" aria-label="About the byline">
            <h3>About the byline</h3>
            <div style="display:flex; gap:1rem; align-items:flex-start;">
                @if ($news->user->image)
                    <img src="{{ image_src($news->user->image) }}" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;flex:0 0 auto;">
                @endif
                <div style="flex:1;">
                    <strong><a href="{{ $news->user->profileUrl() }}">{{ $news->user->name }}</a></strong>
                    @if ($news->user->bio)
                        <p style="margin-top: 0.4rem; color: var(--c-fg-soft);">{{ $news->user->bio }}</p>
                    @endif
                </div>
            </div>
        </aside>
    @endif

    @if (!empty($news->tags))
        <div class="cluster" style="margin-top:2rem;">
            @foreach (array_filter(array_map('trim', explode(',', $news->tags))) as $tag)
                <a class="tag" href="{{ route('tags_news', ['slug' => \Illuminate\Support\Str::slug($tag)]) }}">#{{ $tag }}</a>
            @endforeach
        </div>
    @endif

    {{-- Reactions --}}
    @if (!empty($reactionTypes))
        <div class="reactions" data-reactions>
            @foreach ($reactionTypes as $type)
                <button class="reaction" type="button"
                        data-reaction="{{ $type }}"
                        data-article-id="{{ $news->id }}"
                        data-active="{{ optional($userReaction)->type === $type ? 'true' : 'false' }}"
                        aria-pressed="{{ optional($userReaction)->type === $type ? 'true' : 'false' }}">
                    <span aria-hidden="true">{{ \App\Models\Reaction::getEmoji($type) ?? '👍' }}</span>
                    <span>{{ ucfirst($type) }}</span>
                    <span class="reaction__count">{{ $reactionCounts[$type] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Social share row: full network buttons for desktop, the icon
         row below covers favorite + native share for mobile. --}}
    @include('partials.site.share-row', ['news' => $news])

    {{-- Gift this article: subscriber-only on premium articles. The
         GiftService enforces "active subscription + monthly cap" at
         the controller; this link is a soft hint that disappears when
         the rule wouldn't pass anyway (anonymous + non-subscriber). --}}
    @php
        $_giftUser = auth()->user();
        $_canSeeGift = $news->is_premium
            && $_giftUser
            && method_exists($_giftUser, 'subscribed')
            && $_giftUser->subscribed(config('paywall.subscription_name', 'default'));
    @endphp
    @if ($_canSeeGift)
        <p style="margin-block: 0.75rem 0;">
            <a href="{{ route('articles.gift.show', ['news' => $news->id]) }}" class="btn btn--ghost" style="font-size:0.85rem;">
                🎁 Gift this article
            </a>
        </p>
    @endif

    {{-- Article actions --}}
    <div class="action-row">
        <button class="icon-btn"
                type="button"
                title="Save to favorites"
                aria-pressed="{{ check_favorite($news->id, optional(Auth::user())->id) ? 'true' : 'false' }}"
                data-favorite="{{ $news->id }}"
                data-url="{{ route('ajax_actions') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
        </button>
        <button class="icon-btn" type="button" title="Share"
                onclick="navigator.share ? navigator.share({title:document.title, url:location.href}).catch(()=>{}) : navigator.clipboard.writeText(location.href).then(()=>alert('Link copied'))">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                <circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/>
                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>
            </svg>
        </button>
        @if ($news->category)
            <a class="badge" href="{{ route('category_news', ['slug' => $news->category->slug]) }}">{{ $news->category->name }}</a>
        @endif
    </div>

    {{-- Prev / next --}}
    @if (!empty($previous_news) || !empty($next_news))
        <div class="cluster" style="justify-content:space-between; margin-block:2rem; gap:1rem;">
            @if (!empty($previous_news))
                <a class="btn btn--ghost" href="{{ route('news.details', ['slug' => $previous_news->slug]) }}">← {{ \Illuminate\Support\Str::limit(stripslashes($previous_news->title), 50) }}</a>
            @else
                <span></span>
            @endif
            @if (!empty($next_news))
                <a class="btn btn--ghost" href="{{ route('news.details', ['slug' => $next_news->slug]) }}">{{ \Illuminate\Support\Str::limit(stripslashes($next_news->title), 50) }} →</a>
            @endif
        </div>
    @endif

    {{-- Related --}}
    @if ($related->count())
        <section class="comments">
            <h2 class="comments__title">More from {{ optional($news->category)->name ?: 'this section' }}</h2>
            <div class="grid-cards">
                @foreach ($related as $r)
                    <article class="card">
                        @if ($r->image)
                            <div class="card__media">
                                <a href="{{ route('news.details', ['slug' => $r->slug]) }}">
                                    <img src="{{ image_src($r->image) }}"
                                         alt="{{ $r->image_alt ?: stripslashes($r->title) }}" loading="lazy">
                                </a>
                            </div>
                        @endif
                        <div class="card__body">
                            <h3 class="card__title"><a href="{{ route('news.details', ['slug' => $r->slug]) }}">{{ stripslashes($r->title) }}</a></h3>
                            <div class="card__meta">
                                @if ($r->effectivePublishedAt())
                                    <time datetime="{{ $r->effectivePublishedAt()->toAtomString() }}">{{ $r->effectivePublishedAt()->format('M j, Y') }}</time>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Behavior-aware "Recommended for you" beneath the article.
         Falls back to popularity-weighted picks for anonymous
         readers, so this surface always renders something useful. --}}
    @include('partials.site.recommendations', [
        'items'    => $reco ?? collect(),
        'heading'  => auth()->check() ? 'Recommended for you' : 'You might also like',
        'subtitle' => null,
        'context'  => 'article',
    ])

    {{-- Comments --}}
    <section class="comments">
        <h2 class="comments__title">Discussion <span class="text-muted" style="font-weight:400; font-size:var(--t-base);">({{ count($comment_list) }})</span></h2>

        @php
            $captcha = app(\App\Services\Captcha\CaptchaVerifier::class);
            $guestsAllowed = strtolower((string) getcong('comments_allow_guests')) === '1'
                || strtolower((string) getcong('comments_allow_guests')) === 'true'
                || strtolower((string) getcong('comments_allow_guests')) === 'on'
                || strtolower((string) getcong('comments_allow_guests')) === 'yes';
            $guestsAllowed = $guestsAllowed && $captcha->isEnabled();
        @endphp

        @auth
            <form method="post" action="{{ route('comment_send') }}" style="margin-bottom:1.5rem;">
                @csrf
                <input type="hidden" name="post_id" value="{{ $news->id }}">
                <div class="field">
                    <label for="comment_text" class="sr-only">Add a comment</label>
                    <textarea id="comment_text" name="comment_text" rows="3" required placeholder="Share your thoughts… (will be reviewed before posting)"></textarea>
                </div>
                <button class="btn" type="submit">Post comment</button>
            </form>
        @else
            @if ($guestsAllowed)
                <form method="post" action="{{ route('comment_send') }}" style="margin-bottom:1.5rem;">
                    @csrf
                    <input type="hidden" name="post_id" value="{{ $news->id }}">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:0.75rem;margin-bottom:0.75rem;">
                        <div class="field" style="margin:0;">
                            <label for="guest_name" class="sr-only">Name</label>
                            <input id="guest_name" name="guest_name" type="text" required minlength="2" maxlength="120" placeholder="Your name" value="{{ old('guest_name') }}">
                        </div>
                        <div class="field" style="margin:0;">
                            <label for="guest_email" class="sr-only">Email</label>
                            <input id="guest_email" name="guest_email" type="email" required maxlength="200" placeholder="you@example.com" value="{{ old('guest_email') }}">
                        </div>
                    </div>
                    <div class="field">
                        <label for="comment_text" class="sr-only">Add a comment</label>
                        <textarea id="comment_text" name="comment_text" rows="3" required placeholder="Share your thoughts… (will be reviewed before posting)">{{ old('comment_text') }}</textarea>
                    </div>

                    {{-- CAPTCHA widget. Provider's loader script is added
                         once at the bottom of the page; the widget div
                         here is what the loader binds to. --}}
                    @if ($captcha->provider() === 'recaptcha')
                        <div class="g-recaptcha" data-sitekey="{{ $captcha->siteKey() }}" style="margin-bottom:0.75rem;"></div>
                    @elseif ($captcha->provider() === 'turnstile')
                        <div class="cf-turnstile" data-sitekey="{{ $captcha->siteKey() }}" style="margin-bottom:0.75rem;"></div>
                    @endif

                    <p class="text-muted" style="font-size:0.8rem;margin:0 0 0.75rem 0;">
                        Your email is stored for moderation only and never displayed publicly.
                    </p>
                    <button class="btn" type="submit">Post comment</button>
                </form>

                @push('scripts')
                    @if ($captcha->provider() === 'recaptcha')
                        <script src="https://www.google.com/recaptcha/api.js" async defer nonce="{{ csp_nonce() }}"></script>
                    @elseif ($captcha->provider() === 'turnstile')
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="{{ csp_nonce() }}"></script>
                    @endif
                @endpush
            @else
                <p class="text-muted"><a href="{{ route('user_login') }}">Sign in</a> to join the discussion.</p>
            @endif
        @endauth

        @forelse ($comment_list as $c)
            <div class="comment">
                <div class="comment__head">
                    <span class="comment__author">{{ $c->authorDisplayName() }}@if ($c->isGuest()) <span class="text-muted" style="font-weight:400;font-size:0.8em;">(guest)</span>@endif</span>
                    <span class="comment__date">{{ $c->created_at?->diffForHumans() }}</span>
                </div>
                <div class="comment__body">{{ $c->content }}</div>
                <div class="comment__foot" style="margin-top:0.4rem;">
                    @auth
                        @php
                            $isLiking = $c->isLikedBy(auth()->user());
                            $likeCount = $c->likesCount();
                        @endphp
                        <button type="button"
                                class="comment__like-btn icon-btn"
                                data-comment-like="{{ $c->id }}"
                                aria-pressed="{{ $isLiking ? 'true' : 'false' }}"
                                aria-label="{{ $isLiking ? 'Unlike' : 'Like' }} this comment ({{ $likeCount }} {{ \Illuminate\Support\Str::plural('like', $likeCount) }})"
                                style="font-size:0.85rem; background:transparent; border:0; cursor:pointer; padding:0.15rem 0.4rem; border-radius:4px;">
                            <span aria-hidden="true">{{ $isLiking ? '♥' : '♡' }}</span>
                            <span class="comment__like-count" aria-hidden="true">{{ $likeCount }}</span>
                        </button>
                    @else
                        @php $likes = $c->likesCount(); @endphp
                        @if ($likes > 0)
                            <span class="text-muted" style="font-size:0.85rem;"
                                  aria-label="{{ $likes }} {{ \Illuminate\Support\Str::plural('like', $likes) }}">♡ <span aria-hidden="true">{{ $likes }}</span></span>
                        @endif
                    @endauth
                </div>
            </div>
        @empty
            <p class="text-muted">No comments yet — be the first.</p>
        @endforelse
    </section>
</article>

@endsection
