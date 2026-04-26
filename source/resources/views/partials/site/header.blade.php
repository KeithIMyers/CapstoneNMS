@php
    use App\Models\Category;
    $navCategories = \Illuminate\Support\Facades\Cache::remember('site.nav_categories', 300, function () {
        return Category::active()->whereNull('parent_id')->orderBy('cat_order')->take(7)->get(['id', 'name', 'slug']);
    });
    $logoUrl = logo_src(getcong('site_logo'));
    $hasPodcasts = \Illuminate\Support\Facades\Cache::remember('site.has_podcasts', 300, fn () => \App\Models\PodcastShow::active()->exists());
    $hasAsk      = \Illuminate\Support\Facades\Cache::remember('site.has_ask', 300, fn () => \App\Models\AiProvider::active()->exists() && \App\Models\AiAgent::where('key', 'editorial.research')->where('is_active', true)->exists());
@endphp

<header class="site-header">
    <div class="container site-header__row">
        <a href="{{ url('/') }}" class="site-header__brand" aria-label="{{ getcong('site_name') ?: config('app.name') }} home">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ getcong('site_name') ?: config('app.name') }}">
            @else
                <span class="site-header__brand-text">{{ getcong('site_name') ?: config('app.name') }}</span>
            @endif
        </a>

        {{-- Desktop nav: inline strip. Hidden ≤56rem; the same links
             render inside the mobile drawer below for narrow viewports. --}}
        <nav class="site-nav site-nav--desktop" aria-label="Primary">
            <a class="site-nav__link {{ isActiveRoute('home') }}" href="{{ url('/') }}">Home</a>
            @foreach ($navCategories as $cat)
                <a class="site-nav__link {{ request()->is('category/'.$cat->slug) ? 'is-active' : '' }}" href="{{ route('category_news', ['slug' => $cat->slug]) }}">{{ $cat->name }}</a>
            @endforeach
            @if ($hasPodcasts)
                <a class="site-nav__link {{ request()->is('podcasts*') ? 'is-active' : '' }}" href="{{ route('podcasts.index') }}">Podcasts</a>
            @endif
            @if ($hasAsk)
                <a class="site-nav__link {{ request()->is('ask*') ? 'is-active' : '' }}" href="{{ route('ask.show') }}">Ask</a>
            @endif
        </nav>

        <div class="site-header__tools">
            {{-- Search field: desktop only. Mobile gets the same form
                 inside the drawer below. --}}
            <form class="site-search site-search--desktop" action="{{ route('search') }}" method="get" role="search" aria-label="Site search">
                <button type="submit" aria-label="Search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
                    </svg>
                </button>
                <input type="search" name="s" placeholder="Search articles…" aria-label="Search query" autocomplete="off" data-search-autocomplete value="{{ request()->query('s') }}">
            </form>

            <button class="icon-btn site-tool--desktop" type="button" data-theme-toggle aria-label="Toggle theme">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>

            @auth
                <a class="icon-btn site-tool--desktop" href="{{ route('user_profile') }}" aria-label="Your profile">
                    @if (Auth::user()->image)
                        <img src="{{ image_src(Auth::user()->image) }}" alt="" style="width:24px;height:24px;border-radius:50%;object-fit:cover;">
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                            <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
                        </svg>
                    @endif
                </a>
            @endauth

            <button class="icon-btn menu-toggle" type="button" data-menu-toggle aria-label="Open menu" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>
            </button>
        </div>
    </div>

    {{-- Mobile drawer: holds nav links AND tools (search, theme, profile)
         that hide from the top row at narrow widths. Keeps the visible
         phone header to just brand + hamburger. --}}
    <div class="site-drawer" data-site-nav>
        <form class="site-search site-search--mobile" action="{{ route('search') }}" method="get" role="search" aria-label="Site search">
            <input type="search" name="s" placeholder="Search articles…" aria-label="Search query" autocomplete="off" data-search-autocomplete value="{{ request()->query('s') }}">
            <button type="submit" aria-label="Search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
                </svg>
            </button>
        </form>

        <nav class="site-drawer__nav" aria-label="Primary (mobile)">
            <a class="site-drawer__link {{ isActiveRoute('home') }}" href="{{ url('/') }}">Home</a>
            @foreach ($navCategories as $cat)
                <a class="site-drawer__link {{ request()->is('category/'.$cat->slug) ? 'is-active' : '' }}" href="{{ route('category_news', ['slug' => $cat->slug]) }}">{{ $cat->name }}</a>
            @endforeach
            @if ($hasPodcasts)
                <a class="site-drawer__link {{ request()->is('podcasts*') ? 'is-active' : '' }}" href="{{ route('podcasts.index') }}">Podcasts</a>
            @endif
            @if ($hasAsk)
                <a class="site-drawer__link {{ request()->is('ask*') ? 'is-active' : '' }}" href="{{ route('ask.show') }}">Ask</a>
            @endif
        </nav>

        <div class="site-drawer__tools">
            <button class="site-drawer__tool" type="button" data-theme-toggle aria-label="Toggle theme">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <span>Theme</span>
            </button>
            @auth
                <a class="site-drawer__tool" href="{{ route('user_profile') }}">
                    @if (Auth::user()->image)
                        <img src="{{ image_src(Auth::user()->image) }}" alt="" style="width:18px;height:18px;border-radius:50%;object-fit:cover;">
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                            <circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>
                        </svg>
                    @endif
                    <span>{{ Auth::user()->name }}</span>
                </a>
            @else
                <a class="site-drawer__tool" href="{{ route('user_login') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18" aria-hidden="true">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    <span>Sign in</span>
                </a>
            @endauth
        </div>
    </div>
</header>
