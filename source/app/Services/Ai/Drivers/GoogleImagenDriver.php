<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\ImageGenResponse;
use Illuminate\Support\Facades\Http;

/**
 * Google "NanoBanana" / Gemini image-generation adapter. Uses the
 * Generative Language API:
 *
 *   POST {base}/models/{model}:generateContent?key={api_key}
 *   { "contents": [{ "parts": [{ "text": "<prompt>" }] }] }
 *
 * Response carries the generated image as base64 in
 * candidates[0].content.parts[*].inlineData.data alongside its
 * mimeType. Multiple candidates are possible; we take the first
 * inlineData part we see.
 *
 * The provider's api_key is sent both as a query parameter (Google's
 * documented form) and as the x-goog-api-key header so accounts that
 * route through different auth paths still work.
 */
class GoogleImagenDriver implements ImageGenDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    public function generate(string $prompt, string $size = '1024x1024', ?string $model = null): ImageGenResponse
    {
        $model ??= $this->provider->image_model ?: 'gemini-2.5-flash-image-preview';
        $base   = rtrim($this->provider->base_url ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $key    = $this->provider->api_key ?? '';

        if ($key === '') {
            throw new AiException(
                'Google image provider requires an api_key.',
                401,
                AiProvider::KIND_GOOGLE_IMAGEN,
            );
        }

        $url = $base.'/models/'.urlencode($model).':generateContent?key='.urlencode($key);

        $response = Http::withHeaders([
                'x-goog-api-key' => $key,
                'content-type'   => 'application/json',
            ])
            ->timeout(120)
            ->acceptJson()
            ->post($url, [
                'contents' => [[
                    'parts' => [['text' => $prompt]],
                ]],
            ]);

        if (! $response->successful()) {
            throw new AiException(
                'Google image gen failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_GOOGLE_IMAGEN,
            );
        }

        $data = $response->json();

        // Walk candidates → content.parts → inlineData.
        $bytes = null;
        $mime  = 'image/png';
        foreach (($data['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
                if ($inline && ! empty($inline['data'])) {
                    $bytes = base64_decode($inline['data']);
                    $mime  = $inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png';
                    break 2;
                }
            }
        }

        if ($bytes === null) {
            throw new AiException(
                'Google response had no inline image data',
                500,
                AiProvider::KIND_GOOGLE_IMAGEN,
            );
        }

        return new ImageGenResponse(
            bytes: $bytes,
            mimeType: $mime,
            model: $model,
            raw: $data,
        );
    }
}
