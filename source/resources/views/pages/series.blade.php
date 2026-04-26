@extends('layouts.site')

@section('head_title', $series->name.' · '.getcong('site_name'))
@section('head_description', $series->description ?: 'Multi-part series: '.$series->name)

@section('content')
<div class="container">
    @if ($series->hero_image)
        <figure class="topic-hero">
            <img src="{{ image_src($series->hero_image) }}" alt="{{ $series->name }}" loading="eager">
        </figure>
    @endif

    <div class="page-intro">
        <span class="card__kicker">Series</span>
        <h1>{{ $series->name }}</h1>
        @if (!empty($series->description))
            <p>{{ $series->description }}</p>
        @endif
        <p class="text-muted" style="margin-top:0.5rem;font-size:0.9rem;">{{ $articles->count() }} part{{ $articles->count() === 1 ? '' : 's' }} so far</p>
    </div>

    @if ($articles->count())
        <ol class="series-list" style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:1.25rem;">
            @foreach ($articles as $i => $item)
                <li>
                    @include('partials.site.card', ['item' => $item])
                </li>
            @endforeach
        </ol>
    @else
        <p class="text-muted" style="padding: 2rem 0;">No published articles in this series yet.</p>
    @endif
</div>
@endsection
