<?php

namespace App\Services\Ai;

/**
 * Normalized image generation response. Carries the raw bytes + mime
 * type so callers can write straight to disk without thinking about
 * the upstream encoding.
 *
 * Most providers return base64-encoded PNG; some return URLs we'd have
 * to fetch separately. The driver normalizes either to the binary blob
 * here so the dispatcher only has one path to handle.
 */
final class ImageGenResponse
{
    public function __construct(
        public readonly string $bytes,        // raw image bytes
        public readonly string $mimeType,     // image/png, image/jpeg, image/webp
        public readonly ?string $model = null,
        public readonly ?string $revisedPrompt = null, // some providers rewrite prompts; capture for audit
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'png',
        };
    }
}
