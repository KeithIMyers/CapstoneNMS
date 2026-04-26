{{--
    Reusable "For you" / "Recommended" widget. Caller passes:
      $items     a Collection<News>
      $heading   string heading (defaults to "For you")
      $subtitle  optional explanatory line
      $context   one of: 'home', 'article', 'inline' (controls layout)

    Renders nothing when $items is empty so the layout collapses
    cleanly for users with no signal yet.
--}}
@php
    $items    = $items    ?? collect();
    $heading  = $heading  ?? 'For you';
    $subtitle = $subtitle ?? null;
    $context  = $context  ?? 'home';
@endphp

@if ($items->isNotEmpty())
    <section class="reco-row reco-row--{{ $context }}" aria-label="{{ $heading }}" style="margin-block: 2rem;">
        <header style="display:flex; justify-content:space-between; align-items:baseline; gap:1rem; margin-bottom:0.85rem;">
            <h2 style="font-family:var(--font-display); font-size:var(--t-lg); margin:0;">{{ $heading }}</h2>
            @if ($subtitle)
                <span class="text-muted" style="font-size:0.85rem;">{{ $subtitle }}</span>
            @endif
        </header>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(15rem, 1fr)); gap:1.25rem;">
            @foreach ($items as $a)
                <article class="card reco-card" style="padding:0; overflow:hidden; display:flex; flex-direction:column;">
                    @php
                        $heroSrc = $a->image
                            ? (preg_match('~^https?://~i', $a->image)
                                ? $a->image
                                : Storage::disk(getcong('site_storage') ?: 'public')->url($a->image))
                            : null;
                    @endphp
                    @if ($heroSrc)
                        {{-- The hero image link is decorative — the
                             headline link below has the same href. We
                             hide it from assistive tech with
                             aria-hidden so screen readers don't
                             encounter the same article twice. --}}
                        <a href="{{ route('news.details', ['slug' => $a->slug]) }}" tabindex="-1" aria-hidden="true">
                            <img src="{{ $heroSrc }}" alt=""
                                 loading="lazy"
                                 style="display:block; width:100%; aspect-ratio:16/9; object-fit:cover;">
                        </a>
                    @endif
                    <div style="padding:0.85rem 1rem 1rem;">
                        @if ($a->category)
                            <span class="card__kicker" style="font-size:0.7rem; letter-spacing:0.05em; text-transform:uppercase; color:var(--c-fg-soft);">{{ $a->category->name }}</span>
                        @endif
                        <h3 style="font-size:1rem; line-height:1.35; margin:0.25rem 0 0.4rem;">
                            <a href="{{ route('news.details', ['slug' => $a->slug]) }}" style="color:inherit; text-decoration:none;">
                                {{ \Illuminate\Support\Str::limit(strip_tags($a->title), 90) }}
                            </a>
                        </h3>
                        <div style="font-size:0.8rem; color:var(--c-fg-soft);">
                            @if ($a->user)
                                {{ $a->user->name }} ·
                            @endif
                            {{ optional($a->published_at)->diffForHumans() }}
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
