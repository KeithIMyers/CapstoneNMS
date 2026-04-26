<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>
@php
    $feedUrl = route('podcasts.feed', ['slug' => $show->slug]);
    $showUrl = route('podcasts.show', ['slug' => $show->slug]);
    $artwork = $show->artwork_path
        ? \Illuminate\Support\Facades\Storage::disk(getcong('site_storage') ?: 'public')->url($show->artwork_path)
        : asset('site/img/og-default.png');
    $ownerName = $show->owner_name ?: $show->author ?: getcong('site_name');
    $ownerEmail = $show->owner_email ?: ('podcast@'.parse_url(config('app.url'), PHP_URL_HOST));
    $category = $show->itunes_category ?: 'News';
@endphp
<rss version="2.0"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{{ $show->title }}</title>
        <link>{{ $showUrl }}</link>
        <atom:link href="{{ $feedUrl }}" rel="self" type="application/rss+xml"/>
        <language>{{ $show->language ?: 'en-us' }}</language>
        <description>{{ $show->description }}</description>
        <itunes:summary>{{ $show->description }}</itunes:summary>
        <itunes:author>{{ $show->author ?: $ownerName }}</itunes:author>
        <itunes:type>episodic</itunes:type>
        <itunes:explicit>{{ $show->explicit ? 'true' : 'false' }}</itunes:explicit>
        <itunes:owner>
            <itunes:name>{{ $ownerName }}</itunes:name>
            <itunes:email>{{ $ownerEmail }}</itunes:email>
        </itunes:owner>
        <itunes:image href="{{ $artwork }}"/>
        <image>
            <url>{{ $artwork }}</url>
            <title>{{ $show->title }}</title>
            <link>{{ $showUrl }}</link>
        </image>
        <itunes:category text="{{ $category }}">
            @if ($show->itunes_subcategory)
                <itunes:category text="{{ $show->itunes_subcategory }}"/>
            @endif
        </itunes:category>

        @foreach ($episodes as $ep)
            @php
                $epUrl = route('podcasts.episode', ['showSlug' => $show->slug, 'episodeSlug' => $ep->slug]);
                $explicit = $ep->explicit ?? $show->explicit;
            @endphp
            <item>
                <title>{{ $ep->title }}</title>
                <link>{{ $epUrl }}</link>
                <guid isPermaLink="false">podcast-ep-{{ $ep->id }}</guid>
                <pubDate>{{ $ep->published_at?->toRfc2822String() }}</pubDate>
                <description>{{ $ep->description }}</description>
                <content:encoded><![CDATA[{!! $ep->show_notes ?: $ep->description !!}]]></content:encoded>
                <enclosure url="{{ $ep->media_url }}"
                           length="{{ (int) ($ep->media_size_bytes ?? 0) }}"
                           type="{{ $ep->media_mime ?: 'audio/mpeg' }}"/>
                <itunes:duration>{{ $ep->durationFormatted() }}</itunes:duration>
                <itunes:explicit>{{ $explicit ? 'true' : 'false' }}</itunes:explicit>
                <itunes:episodeType>{{ $ep->episode_type ?: 'full' }}</itunes:episodeType>
                @if ($ep->season_number)<itunes:season>{{ $ep->season_number }}</itunes:season>@endif
                @if ($ep->episode_number)<itunes:episode>{{ $ep->episode_number }}</itunes:episode>@endif
            </item>
        @endforeach
    </channel>
</rss>
