@extends('layouts.site')

@section('head_title', 'Podcasts · '.getcong('site_name'))
@section('head_description', 'Audio shows from '.getcong('site_name'))

@section('content')
<div class="container">
    <div class="page-intro">
        <h1>Podcasts</h1>
        <p>Audio shows from the newsroom.</p>
    </div>

    @if ($shows->count())
        <div class="grid-cards">
            @foreach ($shows as $show)
                <article class="card">
                    @if ($show->artwork_path)
                        <a class="card__media" href="{{ route('podcasts.show', ['slug' => $show->slug]) }}">
                            <img src="{{ image_src($show->artwork_path) }}" alt="{{ $show->title }}" loading="lazy">
                        </a>
                    @endif
                    <div class="card__body">
                        <span class="card__kicker">Podcast</span>
                        <h3 class="card__title"><a href="{{ route('podcasts.show', ['slug' => $show->slug]) }}">{{ $show->title }}</a></h3>
                        @if ($show->description)
                            <p class="card__excerpt">{{ \Illuminate\Support\Str::limit($show->description, 160) }}</p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <p class="text-muted">No podcast shows yet.</p>
    @endif
</div>
@endsection
