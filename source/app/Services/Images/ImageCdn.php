<?php

namespace App\Services\Images;

use App\Services\Images\Contracts\ImageDriver;
use App\Services\Images\Drivers\CloudflareImageDriver;
use App\Services\Images\Drivers\ImgixImageDriver;
use App\Services\Images\Drivers\LocalImageDriver;

/**
 * Front for the image-CDN drivers. Reads the configured provider
 * (image_cdn_provider site setting; defaults to "local") and routes
 * URL transformations through it. Convenience methods build a srcset
 * string for responsive images and a picture() helper that returns
 * the AVIF + WebP + JPEG sources for inline `<picture>` markup.
 *
 * Default widths are tuned to the common breakpoints the public
 * site uses. Override per-call when a layout needs something else.
 */
class ImageCdn
{
    private const DEFAULT_WIDTHS = [320, 480, 640, 960, 1280, 1920];

    public function driver(): ImageDriver
    {
        $key = function_exists('getcong') ? trim((string) getcong('image_cdn_provider')) : '';
        if ($key === '') $key = (string) config('images.provider', 'local');
        return match (strtolower($key)) {
            'cloudflare' => app(CloudflareImageDriver::class),
            'imgix'      => app(ImgixImageDriver::class),
            default      => app(LocalImageDriver::class),
        };
    }

    public function url(string $sourceUrl, array $opts = []): string
    {
        return $this->driver()->transform($sourceUrl, $opts);
    }

    /**
     * Build a `srcset` value with `widthDescriptor` candidates.
     *
     * @param  array<int, int> $widths
     */
    public function srcset(string $sourceUrl, array $widths = self::DEFAULT_WIDTHS, array $opts = []): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $opts['width'] = (int) $w;
            $parts[] = $this->url($sourceUrl, $opts).' '.$w.'w';
        }
        return implode(', ', $parts);
    }

    /**
     * Pre-built sources for `<picture>` rendering. Returns:
     *
     *   [
     *     ['format' => 'avif', 'srcset' => '...', 'type' => 'image/avif'],
     *     ['format' => 'webp', 'srcset' => '...', 'type' => 'image/webp'],
     *     'fallback' => '...',  // single jpg/png URL
     *   ]
     *
     * The local driver returns the same URL for every variant; the
     * browser still gets the original asset and the partial degrades
     * cleanly. CDN drivers emit different URLs per format so the
     * browser picks the smallest format it understands.
     *
     * @return array{sources: array<int, array{format:string,srcset:string,type:string}>, fallback: string}
     */
    public function picture(string $sourceUrl, array $widths = self::DEFAULT_WIDTHS, array $opts = []): array
    {
        $sources = [];
        foreach (['avif', 'webp'] as $fmt) {
            $sources[] = [
                'format' => $fmt,
                'type'   => 'image/'.$fmt,
                'srcset' => $this->srcset($sourceUrl, $widths, array_merge($opts, ['format' => $fmt])),
            ];
        }
        $fallback = $this->url($sourceUrl, array_merge($opts, [
            'width'  => $opts['width']  ?? max($widths),
            'format' => 'auto',
        ]));
        return ['sources' => $sources, 'fallback' => $fallback];
    }
}
