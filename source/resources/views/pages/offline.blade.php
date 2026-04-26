@extends('layouts.site')

@section('head_title', 'Offline · '.getcong('site_name'))

@section('content')
<div class="container">
    <div class="page-intro">
        <h1>You're offline</h1>
        <p class="text-muted">
            Looks like the network's dropped. Pages you've already loaded should still
            work; this is a generic fallback for anything not in the offline cache.
        </p>
    </div>
    <p>
        <a class="btn" href="{{ url('/') }}">Try again</a>
    </p>
</div>
@endsection
