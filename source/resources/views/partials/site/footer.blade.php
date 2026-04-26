@php
    use App\Models\Category;
    use App\Models\Pages;
    $footCats = \Illuminate\Support\Facades\Cache::remember('site.foot_cats', 300, function () {
        return Category::active()->whereNull('parent_id')->orderBy('cat_order')->take(8)->get(['id', 'name', 'slug']);
    });
    $staticPages = \Illuminate\Support\Facades\Cache::remember('site.foot_pages', 300, function () {
        return Pages::where('status', 1)->orderBy('page_order')->get(['page_title', 'page_slug']);
    });
@endphp

<footer class="site-footer">
    <div class="container">
        <div class="site-footer__top">
            <div>
                <div class="site-footer__brand">{{ getcong('site_name') ?: config('app.name') }}</div>
                <p>{{ getcong('site_description') }}</p>
            </div>

            @if ($footCats->isNotEmpty())
                <div>
                    <h4>Sections</h4>
                    <ul>
                        @foreach ($footCats as $cat)
                            <li><a href="{{ route('category_news', ['slug' => $cat->slug]) }}">{{ $cat->name }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h4>About</h4>
                <ul>
                    @foreach ($staticPages as $page)
                        <li><a href="{{ route('page_details', ['slug' => $page->page_slug]) }}">{{ stripslashes($page->page_title) }}</a></li>
                    @endforeach
                    <li><a href="{{ route('contact_us') }}">Contact us</a></li>
                </ul>
            </div>

            <div>
                <h4>Follow</h4>
                <ul>
                    <li><a href="{{ url('/feed.xml') }}">RSS feed</a></li>
                    @if (getcong('twitter_url'))<li><a href="{{ getcong('twitter_url') }}" rel="noopener">Twitter / X</a></li>@endif
                    @if (getcong('facebook_url'))<li><a href="{{ getcong('facebook_url') }}" rel="noopener">Facebook</a></li>@endif
                    @if (getcong('instagram_url'))<li><a href="{{ getcong('instagram_url') }}" rel="noopener">Instagram</a></li>@endif
                    @if (getcong('youtube_url'))<li><a href="{{ getcong('youtube_url') }}" rel="noopener">YouTube</a></li>@endif
                </ul>
            </div>
        </div>

        @if (config('webpush.vapid_public'))
            <div class="site-footer__push" style="text-align:center;padding:0.85rem 0;border-top:1px solid var(--c-border);">
                <button type="button" class="btn btn--ghost" data-push-subscribe style="font-size:0.85rem;">
                    Get breaking-news alerts
                </button>
            </div>
        @endif

        @php $supportedLocales = config('locales.supported', []); @endphp
        @if (count($supportedLocales) > 1)
            <div class="site-footer__locales" style="text-align:center;padding:0.5rem 0;font-size:0.82rem;color:var(--c-fg-muted);border-top:1px solid var(--c-border);">
                <span style="margin-right:0.4rem;">Read in:</span>
                @foreach ($supportedLocales as $code => $label)
                    <a href="{{ route('set_locale', ['locale' => $code]) }}"
                       hreflang="{{ $code }}"
                       @if ($code === app()->getLocale()) aria-current="true" style="font-weight:700;text-decoration:underline;" @endif
                       style="margin:0 0.35rem;color:var(--c-fg-muted);">{{ $label }}</a>
                @endforeach
            </div>
        @endif

        <div class="site-footer__bottom">
            <span>© {{ date('Y') }} {{ getcong('site_name') ?: config('app.name') }}. All rights reserved.</span>
            <span>{{ getcong('copyright_text') }}</span>
            {{-- Always-visible link to the accessibility surface so
                 screen-reader users discover it without hunting
                 through navigation. Open to anonymous + authed
                 readers; localStorage prefs work without sign-in. --}}
            <span><a href="{{ route('accessibility.show') }}">Accessibility preferences</a></span>
            @include('partials.site.powered-by')
        </div>
    </div>
</footer>
