@extends('layouts.site')

@section('head_title', $topic->name.' · '.getcong('site_name'))
@section('head_description', $topic->description ?: ('Latest coverage on '.$topic->name))

@section('content')
<div class="container">
    @if ($topic->hero_image)
        <figure class="topic-hero">
            <img src="{{ image_src($topic->hero_image) }}" alt="{{ $topic->name }}" loading="eager">
        </figure>
    @endif

    <div class="page-intro">
        <span class="card__kicker">Topic</span>
        <h1>{{ $topic->name }}</h1>
        @if (!empty($topic->description))
            <p>{{ $topic->description }}</p>
        @endif
    </div>

    @if ($articles->count())
        <div class="grid-cards">
            @foreach ($articles as $item)
                @include('partials.site.card', ['item' => $item])
            @endforeach
        </div>
    @else
        <p class="text-muted" style="padding: 2rem 0;">No coverage yet for this topic.</p>
    @endif
</div>
@endsection
