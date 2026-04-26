<?php

namespace App\Services\Images\Drivers;

use App\Services\Images\Contracts\ImageDriver;

/**
 * Cloudflare Image Resizing driver. Works on any zone proxied by
 * Cloudflare with the Image Resizing add-on enabled — we don't
 * need to upload images to Cloudflare Images first. The URL pattern is:
 *
 *   https://{zone}/cdn-cgi/image/{params}/{path-to-original}
 *
 * Params are comma-separated key=value pairs: `width=`, `height=`,
 * `format=auto`, `quality=`, `fit=`. `format=auto` returns AVIF /
 * WebP / JPEG based on the request's Accept header.
 *
 * Configured via Site Settings → Image CDN tab:
 *   image_cdn_zone     defaults to APP_URL host
 *   image_cdn_quality  default quality (1..100, default 85)
 */
class CloudflareImageDriver implements ImageDriver
{
    public function key(): string
    {
        return 'cloudflare';
    }

    public function transform(string $sourceUrl, array $opts = []): string
    {
        $zone = $this->zone();
        if ($zone === null) return $sourceUrl;

        // Source URLs from our own host get rewritten to a relative
        // path so Cloudflare's resizer fetches from the same origin.
        // External URLs go through CF as full https origin URLs.
        $parsed = parse_url($sourceUrl);
        if (! $parsed || empty($parsed['host'])) {
            return $sourceUrl;
        }
        $appHost = strtolower((string) parse_url(config('app.url') ?: '', PHP_URL_HOST));
        $isOwn = strtolower((string) $parsed['host']) === $appHost;

        $params = [];
        if (! empty($opts['width']))   $params[] = 'width='.(int) $opts['width'];
        if (! empty($opts['height']))  $params[] = 'height='.(int) $opts['height'];
        $fmt = $opts['format'] ?? 'auto';
        if ($fmt) $params[] = 'format='.$fmt;
        $q = (int) ($opts['quality'] ?? $this->defaultQuality());
        if ($q > 0) $params[] = 'quality='.$q;
        if (! empty($opts['fit']))     $params[] = 'fit='.$opts['fit'];

        $paramStr = implode(',', $params) ?: 'format=auto';

        if ($isOwn) {
            $path = ltrim((string) ($parsed['path'] ?? '/'), '/');
            return rtrim($zone, '/').'/cdn-cgi/image/'.$paramStr.'/'.$path;
        }
        // Off-zone: pass the full URL after the params.
        return rtrim($zone, '/').'/cdn-cgi/image/'.$paramStr.'/'.$sourceUrl;
    }

    private function zone(): ?string
    {
        $val = function_exists('getcong') ? trim((string) getcong('image_cdn_zone')) : '';
        if ($val === '') {
            $val = (string) (config('app.url') ?: '');
        }
        return $val !== '' ? $val : null;
    }

    private function defaultQuality(): int
    {
        $val = function_exists('getcong') ? getcong('image_cdn_quality') : null;
        if ($val !== null && $val !== '' && preg_match('/^\d+$/', (string) $val)) {
            return max(1, min(100, (int) $val));
        }
        return 85;
    }
}
