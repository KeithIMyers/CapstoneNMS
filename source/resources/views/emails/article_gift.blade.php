@extends('emails._layout')

@section('title', 'A gift article from '.($gifterName ?: getcong('site_name')))

@section('content')
    <h1>{{ $gifterName ?: 'A friend' }} sent you an article</h1>
    <p>{{ $gifterName ?: 'A friend' }} gifted you full access to this {{ getcong('site_name') ?: config('app.name') }} story:</p>

    <p style="margin: 1.25rem 0; padding: 1rem; border-left: 3px solid #0ea5e9; background: #f8fafc;">
        <strong>{{ $article->title }}</strong>
        @if ($article->excerpt)
            <br><span style="color:#475569; font-size:14px;">{{ \Illuminate\Support\Str::limit(strip_tags($article->excerpt), 220) }}</span>
        @endif
    </p>

    <p style="text-align:center;">
        <a class="btn" href="{{ $giftUrl }}">Read the article</a>
    </p>

    <p class="muted">The link works once and expires in {{ $expiresIn }} days. After you open it, the article stays unlocked on your browser.</p>
    <p class="muted">Trouble with the button? Paste this URL into your browser:<br>
        <code>{{ $giftUrl }}</code>
    </p>
@endsection
