{{--
   Responsive article image. Prefers spatie/laravel-medialibrary when the
   article has a 'lead' media item registered with WebP conversions; falls
   back to the legacy `image` column when not. Pass `:news="$news"`.
--}}
@php
    $lead = $news->getFirstMedia('lead') ?? null;
    $alt = $news->image_alt ?: strip_tags(stripslashes((string) $news->title));
@endphp

@if ($lead)
    <picture>
        <source media="(min-width: 1280px)" srcset="{{ $lead->getUrl('hero') }}" type="image/webp">
        <source media="(min-width: 768px)" srcset="{{ $lead->getUrl('large') }}" type="image/webp">
        <source media="(min-width: 480px)" srcset="{{ $lead->getUrl('medium') }}" type="image/webp">
        <img src="{{ $lead->getUrl('thumb') }}" alt="{{ $alt }}" loading="lazy" decoding="async">
    </picture>
@elseif (!empty($news->image))
    {{-- Route through the responsive-picture partial so a configured
         CDN (Cloudflare Image Resizing / imgix) emits AVIF + WebP
         srcsets. Local-driver installs degrade to a single-source
         render with the original URL. --}}
    @include('partials.site.responsive-picture', [
        'src'           => $news->image,
        'alt'           => $alt,
        'sizes'         => '(max-width: 600px) 100vw, (max-width: 1024px) 80vw, 60vw',
        'widths'        => [480, 768, 1024, 1280, 1600, 1920],
        'loading'       => 'eager',
        'fetchpriority' => 'high',
        'opts'          => ['fit' => 'cover'],
    ])
@endif

@if (!empty($news->image_caption) || !empty($news->image_credit))
    <figcaption class="article-image-caption">
        @if (!empty($news->image_caption)){{ stripslashes($news->image_caption) }}@endif
        @if (!empty($news->image_credit))<span class="article-image-credit">— {{ stripslashes($news->image_credit) }}</span>@endif
    </figcaption>
@endif
