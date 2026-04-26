@extends('layouts.site')

@section('head_title', 'Page not found · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="text-align:center; padding-block: 6rem;">
    <p class="card__kicker" style="font-size: var(--t-sm);">Error 404</p>
    <h1 style="font-size: var(--t-5xl); margin-block: 0.5rem 1rem;">Page not found</h1>
    <p class="text-muted" style="margin-bottom: 2rem;">The page you're looking for doesn't exist or has been moved.</p>
    <a class="btn" href="{{ url('/') }}">Back to home</a>
</div>
@endsection
