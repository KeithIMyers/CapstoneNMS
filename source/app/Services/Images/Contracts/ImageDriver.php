<?php

namespace App\Services\Images\Contracts;

/**
 * Image-CDN driver contract. Each provider takes an absolute or
 * site-relative source URL plus a constraint set (width, format,
 * quality, fit) and returns a CDN URL that delivers the transformed
 * variant. The local driver returns the input unchanged.
 *
 * The shared shape is `transform(url, opts)` so the upstream service
 * doesn't have to know which provider is configured.
 */
interface ImageDriver
{
    /**
     * @param string $sourceUrl absolute https URL to the original image
     * @param array{
     *   width?: int|null,
     *   height?: int|null,
     *   format?: 'avif'|'webp'|'auto'|'jpg'|null,
     *   quality?: int|null,
     *   fit?: 'cover'|'contain'|'scale-down'|null,
     * } $opts
     */
    public function transform(string $sourceUrl, array $opts = []): string;

    /** Stable provider key — local | cloudflare | imgix. */
    public function key(): string;
}
