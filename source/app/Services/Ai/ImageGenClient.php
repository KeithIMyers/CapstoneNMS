<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Services\Ai\Drivers\GoogleImagenDriver;
use App\Services\Ai\Drivers\ImageGenDriver;
use App\Services\Ai\Drivers\OpenAiImageDriver;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for image generation. Resolves an image-capable
 * provider (caller-pinned or the global default), dispatches to the
 * right driver, logs the call to ai_requests for cost / audit
 * accounting alongside chat + embedding requests.
 *
 * Cost accounting is rough — per-image pricing is published per model
 * (e.g., gpt-image-1 is roughly $0.04 standard / $0.17 HD, Gemini 2.5
 * Flash Image is roughly $0.04). The PRICE_NANOUSD_PER_IMAGE table
 * captures conservative ballparks; refine when the operators care
 * enough to need to.
 */
class ImageGenClient
{
    /** Best-effort per-image nano-USD rates for cost auditing. */
    private const PRICE_NANOUSD_PER_IMAGE = [
        'gpt-image-1'                       => 40_000_000,  // ~$0.040
        'dall-e-3'                          => 40_000_000,
        'gemini-2.5-flash-image'            => 40_000_000,
        'imagen'                            => 40_000_000,
    ];

    public function generate(
        string $prompt,
        ?AiProvider $provider = null,
        string $size = '1024x1024',
        ?string $model = null,
        ?string $purpose = null,
    ): ImageGenResponse {
        $provider ??= AiProvider::defaultImageProvider();
        if (! $provider) {
            throw new AiException('No image-capable AI provider configured. Set an "image model" on a provider under Admin → AI → Providers.');
        }

        $user = auth()->user();
        if ($user && ($block = $user->aiBlockReason())) {
            $this->logBlock($provider, $purpose, $block);
            throw new AiException($block, httpStatus: 402, providerKind: $provider->kind);
        }
        if ($block = $provider->budgetBlockReason()) {
            $this->logBlock($provider, $purpose, $block);
            throw new AiException(
                "Provider \"{$provider->name}\" is paused: {$block}",
                httpStatus: 402,
                providerKind: $provider->kind,
            );
        }

        $driver = $this->driverFor($provider);
        $startedAt = microtime(true);

        try {
            $response = $driver->generate($prompt, $size, $model);
            $this->logRequest($provider, $purpose ?: 'image.generate', $response,
                (int) round((microtime(true) - $startedAt) * 1000));
            return $response;
        } catch (\Throwable $e) {
            Log::warning('ImageGenClient call failed', [
                'provider' => $provider->name,
                'error' => $e->getMessage(),
            ]);
            $this->logFailure($provider, $purpose ?: 'image.generate',
                (int) round((microtime(true) - $startedAt) * 1000), $e);
            throw $e;
        }
    }

    public function driverFor(AiProvider $provider): ImageGenDriver
    {
        return match ($provider->kind) {
            AiProvider::KIND_OPENAI         => new OpenAiImageDriver($provider),
            AiProvider::KIND_GOOGLE_IMAGEN  => new GoogleImagenDriver($provider),
            default => throw new AiException("Provider kind \"{$provider->kind}\" doesn't support image generation."),
        };
    }

    private function logRequest(AiProvider $provider, string $purpose, ImageGenResponse $response, int $durationMs): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $response->model,
            'purpose'       => $purpose,
            'status'        => 'ok',
            'duration_ms'   => $durationMs,
            'cost_microusd' => $this->estimateCostMicroUsd($response),
            'created_at'    => now(),
        ]);
    }

    private function logFailure(AiProvider $provider, string $purpose, int $durationMs, \Throwable $e): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $provider->image_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => $durationMs,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'created_at'    => now(),
        ]);
    }

    private function logBlock(AiProvider $provider, ?string $purpose, string $reason): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $provider->image_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => 0,
            'error_message' => 'Budget block: '.$reason,
            'created_at'    => now(),
        ]);
    }

    private function estimateCostMicroUsd(ImageGenResponse $response): ?int
    {
        $model = strtolower((string) $response->model);
        foreach (self::PRICE_NANOUSD_PER_IMAGE as $fragment => $rate) {
            if (str_contains($model, $fragment)) {
                // Single image → rate is per-image already, in nano-USD.
                // Convert to micro-USD.
                return (int) round($rate / 1_000);
            }
        }
        return null;
    }
}
