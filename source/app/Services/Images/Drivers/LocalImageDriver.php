<?php

namespace App\Services\Images\Drivers;

use App\Services\Images\Contracts\ImageDriver;

/**
 * Pass-through driver. No transformation, no CDN — used when no
 * remote provider is configured. The browser still gets the
 * original asset; responsive `<picture>` partials degrade to the
 * single jpg/png source with the same URL repeated across srcset.
 */
class LocalImageDriver implements ImageDriver
{
    public function key(): string
    {
        return 'local';
    }

    public function transform(string $sourceUrl, array $opts = []): string
    {
        return $sourceUrl;
    }
}
