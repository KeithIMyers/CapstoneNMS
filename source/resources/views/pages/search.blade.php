@extends('layouts.site')

@section('head_title', ($search_term ? '"'.$search_term.'" · ' : '').'Search · '.getcong('site_name'))

@section('content')
<div class="container" style="margin-block: 1.5rem 0;">
    <form action="{{ route('search') }}" method="get" role="search" class="field" style="max-width: 32rem;">
        <label for="search-input" class="sr-only">Search</label>
        <input id="search-input" type="search" name="s" value="{{ $search_term }}"
               placeholder="Search articles…" autofocus
               autocomplete="off"
               data-search-autocomplete>
        {{-- Active filters carry through pagination + form re-submit. --}}
        @foreach (($filters ?? []) as $k => $v)
            @if ($v !== '')
                <input type="hidden" name="{{ $k === 'category' ? 'cat' : $k }}" value="{{ $v }}">
            @endif
        @endforeach
    </form>
</div>

<div class="container" style="display:grid; grid-template-columns: minmax(0, 1fr) minmax(180px, 240px); gap: 2rem; margin-top: 1.5rem; align-items: start;">
    <div>
        @php
            $activeFilters = collect($filters ?? [])->filter(fn ($v) => $v !== '');
        @endphp
        @if ($activeFilters->isNotEmpty())
            <div style="margin-bottom: 0.85rem; display:flex; flex-wrap:wrap; gap:0.4rem; align-items:center;">
                <span class="text-muted" style="font-size:0.85rem;">Filtering by</span>
                @foreach ($activeFilters as $k => $v)
                    <a href="{{ url()->current().'?'.http_build_query(array_merge(request()->except(['page', $k === 'category' ? 'cat' : $k]))) }}"
                       class="text-muted"
                       style="font-size:0.8rem; padding:0.2rem 0.55rem; border-radius:999px; border:1px solid var(--c-border); text-decoration:none;"
                       aria-label="Remove {{ $k }} filter">
                        {{ $k }}: {{ $v }} ✕
                    </a>
                @endforeach
            </div>
        @endif

        @include('partials.site.article-list', [
            'items'    => $news,
            'title'    => $search_term ? 'Results for "'.$search_term.'"' : 'Search',
            'subtitle' => $search_term
                ? $news->total().' result'.($news->total() === 1 ? '' : 's')
                : 'Type a query to search articles.',
            'emptyMessage' => $search_term ? 'No results matched your query. Try different keywords or remove a filter.' : '',
        ])
    </div>

    <aside aria-label="Refine results" style="position:sticky; top: 1rem;">
        @php $facets = $facets ?? ['categories' => collect(), 'tags' => collect(), 'authors' => collect()]; @endphp

        @if ($facets['categories']->isNotEmpty())
            <section class="aside-block" style="margin-bottom: 1rem; padding: 0.85rem 1rem;">
                <h3 style="font-size:0.8rem; letter-spacing:0.05em; text-transform:uppercase; color:var(--c-fg-soft); margin: 0 0 0.5rem;">Category</h3>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:0.25rem;">
                    @foreach ($facets['categories'] as $f)
                        @php $url = url()->current().'?'.http_build_query(array_merge(request()->except('page'), ['cat' => $f['slug']])); @endphp
                        <li style="display:flex; justify-content:space-between; gap:0.4rem; align-items:center;">
                            <a href="{{ $url }}"
                               @if (($filters['category'] ?? '') === $f['slug']) aria-current="true" style="font-weight:600;" @endif>{{ $f['name'] }}</a>
                            <span class="text-muted" style="font-size:0.8rem;">{{ $f['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($facets['tags']->isNotEmpty())
            <section class="aside-block" style="margin-bottom: 1rem; padding: 0.85rem 1rem;">
                <h3 style="font-size:0.8rem; letter-spacing:0.05em; text-transform:uppercase; color:var(--c-fg-soft); margin: 0 0 0.5rem;">Tag</h3>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:0.25rem;">
                    @foreach ($facets['tags'] as $f)
                        @php $url = url()->current().'?'.http_build_query(array_merge(request()->except('page'), ['tag' => $f['slug']])); @endphp
                        <li style="display:flex; justify-content:space-between; gap:0.4rem; align-items:center;">
                            <a href="{{ $url }}"
                               @if (($filters['tag'] ?? '') === $f['slug']) aria-current="true" style="font-weight:600;" @endif>{{ $f['name'] }}</a>
                            <span class="text-muted" style="font-size:0.8rem;">{{ $f['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($facets['authors']->isNotEmpty())
            <section class="aside-block" style="margin-bottom: 1rem; padding: 0.85rem 1rem;">
                <h3 style="font-size:0.8rem; letter-spacing:0.05em; text-transform:uppercase; color:var(--c-fg-soft); margin: 0 0 0.5rem;">Author</h3>
                <ul style="list-style:none; padding:0; margin:0; display:grid; gap:0.25rem;">
                    @foreach ($facets['authors'] as $f)
                        @php $url = url()->current().'?'.http_build_query(array_merge(request()->except('page'), ['author' => $f['slug']])); @endphp
                        <li style="display:flex; justify-content:space-between; gap:0.4rem; align-items:center;">
                            <a href="{{ $url }}"
                               @if (($filters['author'] ?? '') === $f['slug']) aria-current="true" style="font-weight:600;" @endif>{{ $f['name'] }}</a>
                            <span class="text-muted" style="font-size:0.8rem;">{{ $f['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="aside-block" style="padding: 0.85rem 1rem;">
            <h3 style="font-size:0.8rem; letter-spacing:0.05em; text-transform:uppercase; color:var(--c-fg-soft); margin: 0 0 0.5rem;">Date range</h3>
            <form method="get" action="{{ route('search') }}" style="display:grid; gap:0.5rem;">
                <input type="hidden" name="s" value="{{ $search_term }}">
                @foreach (['cat' => $filters['category'] ?? '', 'tag' => $filters['tag'] ?? '', 'author' => $filters['author'] ?? ''] as $k => $v)
                    @if ($v !== '') <input type="hidden" name="{{ $k }}" value="{{ $v }}"> @endif
                @endforeach
                <label style="font-size:0.85rem;">From <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" style="width:100%;"></label>
                <label style="font-size:0.85rem;">To <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" style="width:100%;"></label>
                <button class="btn btn--ghost" type="submit" style="font-size:0.85rem;">Apply</button>
            </form>
        </section>
    </aside>
</div>
@endsection
