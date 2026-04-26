{{--
    Responsive <picture> partial. Caller passes:
      $src       string — relative path or absolute URL of the source
      $alt       string — alt text (required by template; pass '' if
                          the image is decorative)
      $sizes     string — `sizes` attribute (e.g. "(max-width: 600px) 100vw, 60vw")
      $widths    array<int> — optional widths for srcset
      $loading   "eager" | "lazy" (default "lazy")
      $fetchpriority  "high" | "low" | "auto" (default unset)
      $class     CSS class on <img>
      $style     inline style on <img>
      $opts      driver passthrough: ['fit' => 'cover', 'quality' => 80, ...]

    Resolves the src through image_src() first (so MediaLibrary +
    direct URLs both work) then runs ImageCdn::picture() to build
    AVIF + WebP + fallback variants. Local-driver installs degrade
    to a single source; CDN-backed installs get the full ladder.
--}}
@php
    $src    = $src    ?? null;
    $alt    = $alt    ?? '';
    $sizes  = $sizes  ?? '100vw';
    $widths = $widths ?? [320, 480, 640, 960, 1280, 1920];
    $opts   = $opts   ?? [];
    $loading       = $loading       ?? 'lazy';
    $class         = $class         ?? '';
    $style         = $style         ?? '';
    $fetchpriority = $fetchpriority ?? null;

    $resolved = $src ? image_src($src) : null;
    $picture  = $resolved ? app(\App\Services\Images\ImageCdn::class)->picture($resolved, $widths, $opts) : null;
@endphp

@if ($resolved && $picture)
    <picture>
        @foreach ($picture['sources'] as $source)
            <source type="{{ $source['type'] }}" srcset="{{ $source['srcset'] }}" sizes="{{ $sizes }}">
        @endforeach
        <img src="{{ $picture['fallback'] }}"
             alt="{{ $alt }}"
             loading="{{ $loading }}"
             @if ($fetchpriority) fetchpriority="{{ $fetchpriority }}" @endif
             decoding="async"
             @if ($class) class="{{ $class }}" @endif
             @if ($style) style="{{ $style }}" @endif>
    </picture>
@endif
