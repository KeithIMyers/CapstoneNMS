<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ getcong('maintenance_title') ?: 'Under maintenance' }}</title>
    <link rel="icon" href="{{ asset('site/img/favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('site/site.css') }}">
    <style>
        body { display: grid; place-items: center; min-height: 100dvh; background: linear-gradient(135deg, #0ea5e9 0%, #0f172a 100%); color: #fff; }
        .maintenance { max-width: 32rem; padding: 2rem; text-align: center; }
        .maintenance h1 { font-size: clamp(2rem, 5vw, 3rem); margin-bottom: 1rem; }
        .maintenance svg { margin: 0 auto 1.5rem; }
    </style>
</head>
<body>
    <div class="maintenance">
        <svg width="96" height="96" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 6v6l4 2"/>
        </svg>
        <h1>{{ getcong('maintenance_title') ?: 'We will be right back' }}</h1>
        <p>{!! getcong('maintenance_description') ?: '<p>The site is undergoing scheduled maintenance and will return shortly.</p>' !!}</p>
    </div>
</body>
</html>
