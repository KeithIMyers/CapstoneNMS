<?php

namespace App\Services\Images\Drivers;

use App\Services\Images\Contracts\ImageDriver;

/**
 * imgix driver. Source URLs become:
 *   https://{source}.imgix.net/{path}?w=…&fm=…&q=…
 *
 * We query-encode our params; imgix handles the rest. `fm=` accepts
 * avif/webp/jpg; we let `auto=format` decide when no explicit
 * format is requested. Configured via Site Settings → Image CDN:
 *
 *   image_cdn_imgix_source   the subdomain (e.g. "mysite-prod")
 *   image_cdn_quality        1..100 (default 80 — imgix recommended)
 */
class ImgixImageDriver implements ImageDriver
{
    public function key(): string
    {
        return 'imgix';
    }

    public function transform(string $sourceUrl, array $opts = []): string
    {
        $source = function_exists('getcong') ? trim((string) getcong('image_cdn_imgix_source')) : '';
        if ($source === '') return $sourceUrl;

        $parsed = parse_url($sourceUrl);
        if (! $parsed || empty($parsed['path'])) return $sourceUrl;

        $params = [];
        if (! empty($opts['width']))   $params['w']  = (int) $opts['width'];
        if (! empty($opts['height']))  $params['h']  = (int) $opts['height'];
        $fmt = $opts['format'] ?? null;
        if ($fmt && $fmt !== 'auto') {
            $params['fm'] = $fmt;
        } else {
            $params['auto'] = 'format,compress';
        }
        $q = (int) ($opts['quality'] ?? $this->defaultQuality());
        if ($q > 0) $params['q'] = $q;
        if (! empty($opts['fit'])) {
            $params['fit'] = match ($opts['fit']) {
                'cover'      => 'crop',
                'contain'    => 'max',
                'scale-down' => 'max',
                default      => $opts['fit'],
            };
        }

        return 'https://'.$source.'.imgix.net'.$parsed['path'].'?'.http_build_query($params);
    }

    private function defaultQuality(): int
    {
        $val = function_exists('getcong') ? getcong('image_cdn_quality') : null;
        if ($val !== null && $val !== '' && preg_match('/^\d+$/', (string) $val)) {
            return max(1, min(100, (int) $val));
        }
        return 80;
    }
}
