@extends('layouts.site')

@section('head_title', $list->name.' · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <p style="margin-bottom:0.4rem;"><a href="{{ route('reading_lists.index') }}" class="text-muted">← All lists</a></p>
    <h1>{{ $list->name }}</h1>

    @if ($items->isEmpty())
        <p class="text-muted" style="margin-block:1.5rem;">Nothing here yet. Save an article from any story page to add it.</p>
    @else
        <ul style="list-style:none; padding:0; display:grid; gap:0.75rem; margin-block:1.5rem;">
            @foreach ($items as $item)
                @if ($item->article)
                    <li class="card" style="padding:1rem;">
                        <a href="{{ route('news.details', ['slug' => $item->article->slug]) }}" style="font-weight:600;">{{ $item->article->title }}</a>
                        <p class="text-muted" style="margin:0.25rem 0 0; font-size:0.9rem;">
                            {{ \Illuminate\Support\Str::limit(strip_tags($item->article->excerpt), 160) }}
                        </p>
                        <p class="text-muted" style="margin:0.5rem 0 0; font-size:0.8rem;">
                            Saved {{ optional($item->added_at)->diffForHumans() ?: '—' }}
                            @if ($item->note)
                                · <em>{{ $item->note }}</em>
                            @endif
                        </p>
                    </li>
                @endif
            @endforeach
        </ul>
        {{ $items->links() }}
    @endif
</div>
@endsection
