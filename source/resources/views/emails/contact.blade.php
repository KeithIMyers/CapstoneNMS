@extends('emails._layout')

@section('title', 'New contact message')

@section('content')
    <h1>New contact message</h1>
    <p>{{ $name }} ({{ $email }}@if (!empty($phone)), {{ $phone }}@endif) sent a message via the contact form.</p>

    <p><strong>Subject:</strong> {{ $subject }}</p>

    <div style="background:#f8fafc;border-left:3px solid #0ea5e9;padding:12px 16px;margin:12px 0;white-space:pre-wrap;">{{ $user_message }}</div>

    <p class="muted">Reply directly to <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
@endsection
