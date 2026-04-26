@extends('emails._layout')

@section('title', $subject)

@section('content')
    @if (! empty($recipientName))
        <p>Hi {{ $recipientName }},</p>
    @endif
    {!! \Illuminate\Support\Str::markdown($bodyMarkdown) !!}
@endsection
