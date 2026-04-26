@extends('layouts.site')

@section('head_title', 'Newsletter · '.(getcong('site_name') ?: config('app.name')))

@section('content')
<div class="container-narrow" style="margin-block: 4rem 5rem; text-align:center;">
    @if (! empty($ok))
        @if (! empty($unsubscribed))
            <h1>You're unsubscribed.</h1>
            <p class="text-muted" style="margin-top:0.75rem;">We've removed your address from the list. If this was a mistake, you can subscribe again from the homepage.</p>
        @else
            <h1>You're confirmed. 🎉</h1>
            <p class="text-muted" style="margin-top:0.75rem;">Look for the morning brief in your inbox starting with the next send.</p>
        @endif
    @else
        <h1>That link looks expired</h1>
        <p class="text-muted" style="margin-top:0.75rem;">It was either already used or has been rotated. If you meant to subscribe, just sign up again from the homepage and we'll send a fresh confirmation.</p>
    @endif
    <p style="margin-top:2rem;"><a class="btn" href="{{ url('/') }}">Back to home</a></p>
</div>
@endsection
