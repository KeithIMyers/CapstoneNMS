<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\ImageGenResponse;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI image-generation adapter. Supports gpt-image-1 (the modern
 * model, returns base64 by default) and dall-e-3 (legacy, returns
 * url unless we ask for b64_json).
 */
class OpenAiImageDriver implements ImageGenDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    public function generate(string $prompt, string $size = '1024x1024', ?string $model = null): ImageGenResponse
    {
        $model ??= $this->provider->image_model ?: 'gpt-image-1';
        $base   = rtrim($this->provider->base_url ?: 'https://api.openai.com/v1', '/');

        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'size'   => $size,
            'n'      => 1,
        ];
        // gpt-image-1 returns b64_json by default; dall-e-3 needs the
        // explicit response_format flag.
        if (str_contains($model, 'dall-e')) {
            $payload['response_format'] = 'b64_json';
        }

        $response = Http::withToken($this->provider->api_key ?? '')
            ->timeout(120)
            ->acceptJson()
            ->post($base.'/images/generations', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'OpenAI image gen failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OPENAI,
            );
        }

        $data = $response->json();
        $b64  = $data['data'][0]['b64_json'] ?? null;
        if (! $b64) {
            // Fallback: dall-e-3 with no response_format may have
            // returned a url; fetch it.
            $url = $data['data'][0]['url'] ?? null;
            if (! $url) {
                throw new AiException('OpenAI returned no image data', 500, AiProvider::KIND_OPENAI);
            }
            $imgResp = Http::timeout(60)->get($url);
            if (! $imgResp->successful()) {
                throw new AiException('OpenAI image URL fetch failed: '.$imgResp->status(), $imgResp->status(), AiProvider::KIND_OPENAI);
            }
            return new ImageGenResponse(
                bytes: $imgResp->body(),
                mimeType: $imgResp->header('Content-Type') ?: 'image/png',
                model: $model,
                revisedPrompt: $data['data'][0]['revised_prompt'] ?? null,
                raw: $data,
            );
        }

        return new ImageGenResponse(
            bytes: base64_decode($b64),
            mimeType: 'image/png',
            model: $model,
            revisedPrompt: $data['data'][0]['revised_prompt'] ?? null,
            raw: $data,
        );
    }
}
