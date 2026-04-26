@extends('layouts.site')

@section('head_title', $author->name.' · '.getcong('site_name'))
@section('head_description', $author->bio ? \Illuminate\Support\Str::limit(strip_tags($author->bio), 160) : 'Articles by '.$author->name.' on '.getcong('site_name'))

@section('content')
<div class="container" style="margin-block: 2rem 4rem;">
    <header class="page-intro" style="display:flex; gap:1.25rem; align-items:flex-start; flex-wrap:wrap;">
        @if ($author->image)
            <img src="{{ image_src($author->image) }}" alt=""
                 style="width:80px; height:80px; border-radius:50%; object-fit:cover; flex:0 0 auto;">
        @else
            <div aria-hidden="true"
                 style="width:80px; height:80px; border-radius:50%; background:var(--c-bg-muted); display:grid; place-items:center; color:var(--c-fg-faint); font-family:var(--font-display); font-weight:800; font-size:1.75rem;">
                {{ strtoupper(mb_substr($author->name, 0, 1)) }}
            </div>
        @endif
        <div style="flex:1; min-width:14rem;">
            <h1 style="margin-bottom:0.25rem;">{{ $author->name }}</h1>
            @if ($author->bio)
                <p style="max-width:60ch;">{{ $author->bio }}</p>
            @else
                <p class="text-muted">{{ $articles->total() }} {{ \Illuminate\Support\Str::plural('article', $articles->total()) }} on {{ getcong('site_name') ?: config('app.name') }}.</p>
            @endif
            @if ($author->twitter_handle)
                <p class="text-muted" style="font-size:var(--t-sm); margin-top:0.5rem;">
                    <a href="https://twitter.com/{{ ltrim($author->twitter_handle, '@') }}" rel="noopener">@{{ ltrim($author->twitter_handle, '@') }}</a>
                </p>
            @endif
        </div>
    </header>

    @if ($articles->count())
        <div class="grid-cards">
            @foreach ($articles as $item)
                @include('partials.site.card', ['item' => $item])
            @endforeach
        </div>
        <div>{{ $articles->links('partials.site.pagination') }}</div>
    @else
        <p class="text-muted" style="padding: 2rem 0;">{{ $author->name }} hasn't published any articles yet.</p>
    @endif
</div>
@endsection
