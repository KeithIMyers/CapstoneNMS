@extends('layouts.site')

@section('head_title', 'Thank you · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 3rem 4rem; text-align:center;">
    <div style="font-size:3rem; line-height:1; margin-bottom:1rem;" aria-hidden="true">🙏</div>
    <h1>Thank you</h1>
    <p class="text-muted" style="margin-block:1rem 1.5rem;">
        Your donation supports the reporting we publish. You'll get a receipt from Stripe in a moment.
    </p>
    <a class="btn" href="{{ url('/') }}">Back to the front page</a>
</div>
@endsection
