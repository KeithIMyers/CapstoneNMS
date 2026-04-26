@extends('layouts.site')

@section('head_title', 'Your favorites · '.getcong('site_name'))

@section('content')
<div class="container" style="margin-block: 1.5rem 4rem;">
    <div class="page-intro">
        <h1>Your favorites</h1>
        <p>Articles you've saved.</p>
    </div>

    @if ($favorites_list->count())
        <div class="grid-cards">
            @foreach ($favorites_list as $fav)
                @if ($fav->posts)
                    @include('partials.site.card', ['item' => $fav->posts])
                @endif
            @endforeach
        </div>
        <div>{{ $favorites_list->links('partials.site.pagination') }}</div>
    @else
        <p class="text-muted">You haven't saved any articles yet. Tap the heart icon on any article to save it for later.</p>
    @endif
</div>
@endsection
