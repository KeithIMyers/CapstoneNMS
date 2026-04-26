@extends('layouts.site')

@section('head_title', 'Your profile · '.getcong('site_name'))

@section('content')
<div class="container-narrow" style="margin-block: 2rem 4rem;">
    <h1 style="margin-bottom: 1.5rem;">Your profile</h1>

    @if ($errors->any())
        <div class="flash flash--err">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('user_profile_update') }}" enctype="multipart/form-data" class="card" style="padding: 1.5rem;">
        @csrf

        <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">
            @if ($user->image)
                <img src="{{ Storage::disk(getcong('site_storage'))->url('/'.$user->image) }}" alt="" style="width:64px; height:64px; border-radius:50%; object-fit:cover;">
            @else
                <div style="width:64px; height:64px; border-radius:50%; background: var(--c-bg-muted); display:grid; place-items:center; color:var(--c-fg-faint);">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</div>
            @endif
            <div>
                <strong>{{ $user->name }}</strong>
                <div class="text-muted" style="font-size: var(--t-sm);">{{ $user->email }}</div>
            </div>
        </div>

        <div class="field">
            <label for="name">Name</label>
            <input id="name" type="text" name="name" required value="{{ old('name', $user->name) }}">
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input id="email" type="email" name="email" required value="{{ old('email', $user->email) }}">
        </div>

        <div class="field">
            <label for="phone">Phone</label>
            <input id="phone" type="tel" name="phone" value="{{ old('phone', $user->phone) }}">
        </div>

        <div class="field">
            <label for="user_image">Profile photo</label>
            <input id="user_image" type="file" name="user_image" accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="help">JPG, PNG, GIF, or WebP. Max 2 MB.</span>
        </div>

        <div class="field">
            <label for="password">New password (optional)</label>
            <input id="password" type="password" name="password" minlength="8" autocomplete="new-password">
            <span class="help">Leave blank to keep current password.</span>
        </div>

        <div class="cluster" style="justify-content: space-between;">
            <button class="btn" type="submit">Save changes</button>
            <a class="btn btn--ghost" href="{{ route('user_logout') }}">Sign out</a>
        </div>
    </form>
</div>
@endsection
