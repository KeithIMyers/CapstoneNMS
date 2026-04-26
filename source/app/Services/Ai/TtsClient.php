<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Services\Ai\Drivers\OpenAiTtsDriver;
use App\Services\Ai\Drivers\TtsDriver;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for TTS calls. Resolves an active provider with
 * tts_model set, dispatches to the right driver, and logs to
 * ai_requests under purpose="tts.*" so podcast spend shows up in the
 * usage log next to chat / image / embedding spend.
 *
 * Cost rough-estimate: OpenAI charges per million input characters
 * (gpt-4o-mini-tts ≈ $0.60 / 1M chars). Captured in nano-USD per char.
 */
class TtsClient
{
    private const PRICE_NANOUSD_PER_CHAR = [
        'gpt-4o-mini-tts' => 600,   // $0.60 / 1M chars
        'tts-1'           => 15_000,// $15 / 1M chars
        'tts-1-hd'        => 30_000,
    ];

    public function speak(
        string $text,
        string $voice,
        ?AiProvider $provider = null,
        string $format = 'mp3',
        ?string $model = null,
        ?string $purpose = null,
    ): TtsResponse {
        $provider ??= AiProvider::defaultTtsProvider();
        if (! $provider) {
            throw new AiException('No TTS-capable AI provider configured. Set a "tts model" on a provider under Admin → AI → Providers.');
        }

        $user = auth()->user();
        if ($user && ($block = $user->aiBlockReason())) {
            throw new AiException($block, httpStatus: 402, providerKind: $provider->kind);
        }
        if ($block = $provider->budgetBlockReason()) {
            throw new AiException(
                "Provider \"{$provider->name}\" is paused: {$block}",
                httpStatus: 402,
                providerKind: $provider->kind,
            );
        }

        $driver = $this->driverFor($provider);
        $startedAt = microtime(true);

        try {
            $response = $driver->speak($text, $voice, $format, $model);
        } catch (\Throwable $e) {
            Log::warning('TTS call failed', ['provider' => $provider->name, 'error' => $e->getMessage()]);
            $this->logFailure($provider, $purpose ?: 'tts.speak',
                (int) round((microtime(true) - $startedAt) * 1000), $e);
            throw $e;
        }

        $this->logRequest($provider, $purpose ?: 'tts.speak', $response,
            (int) round((microtime(true) - $startedAt) * 1000));
        return $response;
    }

    public function driverFor(AiProvider $provider): TtsDriver
    {
        return match ($provider->kind) {
            AiProvider::KIND_OPENAI => new OpenAiTtsDriver($provider),
            default => throw new AiException("Provider kind \"{$provider->kind}\" doesn't support TTS yet."),
        };
    }

    private function logRequest(AiProvider $provider, string $purpose, TtsResponse $response, int $durationMs): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $response->model,
            'purpose'       => $purpose,
            'status'        => 'ok',
            'tokens_in'     => $response->bytesIn,   // input chars; close enough proxy for the audit log
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
            'model'         => $provider->tts_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => $durationMs,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'created_at'    => now(),
        ]);
    }

    private function estimateCostMicroUsd(TtsResponse $response): ?int
    {
        $model = strtolower((string) $response->model);
        foreach (self::PRICE_NANOUSD_PER_CHAR as $fragment => $rate) {
            if (str_contains($model, $fragment)) {
                return (int) round(($response->bytesIn * $rate) / 1_000);
            }
        }
        return null;
    }
}
