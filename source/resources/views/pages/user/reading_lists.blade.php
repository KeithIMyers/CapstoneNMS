@extends('layouts.site')

@section('head_title', 'My reading lists · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2.5rem 4rem;">
    <h1>Reading lists</h1>
    <p class="text-muted" style="margin-bottom: 1.5rem;">
        Save articles for later. Create as many lists as you like — by topic, by reading mood, whatever works.
    </p>

    @if (session('flash_message'))
        <div class="flash flash--ok">{{ session('flash_message') }}</div>
    @endif

    <ul style="list-style:none; padding:0; display:grid; gap:0.75rem; margin-bottom:1.5rem;">
        @foreach ($lists as $list)
            <li class="card" style="padding:1rem; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <a href="{{ route('reading_lists.show', ['slug' => $list->slug]) }}" style="font-weight:600;">{{ $list->name }}</a>
                    @if ($list->is_default)
                        <span class="text-muted" style="font-size:0.8rem; margin-left:0.5rem;">default</span>
                    @endif
                </div>
                <span class="text-muted">{{ $list->items_count }} {{ \Illuminate\Support\Str::plural('article', $list->items_count) }}</span>
            </li>
        @endforeach
    </ul>

    <form method="post" action="{{ route('reading_lists.store') }}" class="card" style="padding:1.25rem;">
        @csrf
        <div class="field">
            <label for="name">New list name</label>
            <input id="name" type="text" name="name" maxlength="120" required placeholder="e.g. Climate, Long reads, Election '26">
        </div>
        <button class="btn" type="submit">Create list</button>
    </form>

    <p class="text-muted" style="margin-top:1.5rem;">
        Looking for partial reads? <a href="{{ route('reading_history') }}">View your reading history</a>.
    </p>
</div>
@endsection
