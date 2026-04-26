@extends('emails._layout')

@section('title', $subject ?? 'Today\'s brief')

@section('content')
    {{-- The body comes back from the AI as Markdown. We render it through
         Str::markdown so editors don't have to write HTML by hand, and
         we relax HTML escape because the AI controls the output and the
         editor reviews before sending. --}}
    {!! \Illuminate\Support\Str::markdown($bodyMarkdown ?? '', [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]) !!}

    <p class="muted" style="margin-top:24px;">
        You're receiving this because you subscribed to {{ getcong('site_name') ?: config('app.name') }}.
        @if (! empty($unsubscribeUrl))
            <a href="{{ $unsubscribeUrl }}">Unsubscribe</a>
        @endif
    </p>
@endsection
