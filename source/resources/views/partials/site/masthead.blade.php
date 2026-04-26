<div class="masthead">
    <div class="container masthead__row">
        <span class="masthead__date">{{ now()->format('l, F j, Y') }}</span>
        <span class="masthead__meta">
            <a href="{{ url('/feed.xml') }}">RSS</a>
            <a href="{{ route('contact_us') }}">Contact</a>
            @auth
                <a href="{{ route('user_profile') }}">{{ Auth::user()->name }}</a>
            @else
                <a href="{{ route('user_login') }}">Sign in</a>
            @endauth
        </span>
    </div>
</div>
