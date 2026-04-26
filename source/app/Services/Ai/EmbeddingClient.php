<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Services\Ai\Drivers\EmbeddingDriver;
use App\Services\Ai\Drivers\OllamaEmbeddingDriver;
use App\Services\Ai\Drivers\OpenAiEmbeddingDriver;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for embedding requests. Resolves the active
 * embedding-capable provider, picks the right driver, runs the call,
 * and logs the request to ai_requests for cost / audit accounting —
 * same audit table as completions, with a distinct "purpose" prefix
 * (embedding.…) so the usage log can filter to embedding spend.
 *
 * Anthropic doesn't offer embeddings; rows with kind=anthropic are
 * automatically excluded by the embedding_model filter.
 */
class EmbeddingClient
{
    /** Per-million-token nano-USD prices for known embedding models. */
    private const PRICE_NANOUSD_PER_TOKEN = [
        'text-embedding-3-small' => 20,
        'text-embedding-3-large' => 130,
        'text-embedding-ada-002' => 100,
        // Local Ollama embedding models cost nothing.
    ];

    public function embed(
        string $text,
        ?AiProvider $provider = null,
        ?string $purpose = null,
    ): EmbeddingResponse {
        $provider ??= AiProvider::defaultEmbeddingProvider();
        if (! $provider) {
            throw new AiException('No embedding-capable AI provider is configured. Set an "embedding model" on a provider under Admin → AI → Providers.');
        }

        $user = auth()->user();
        if ($user && ($userBlock = $user->aiBlockReason())) {
            $this->logBudgetBlock($provider, $purpose, $userBlock);
            throw new AiException($userBlock, httpStatus: 402, providerKind: $provider->kind);
        }

        if ($block = $provider->budgetBlockReason()) {
            $this->logBudgetBlock($provider, $purpose, $block);
            throw new AiException(
                "Provider \"{$provider->name}\" is paused: {$block}",
                httpStatus: 402,
                providerKind: $provider->kind,
            );
        }

        $driver = $this->driverFor($provider);
        $startedAt = microtime(true);

        try {
            $response = $driver->embed($text);
            $this->logRequest($provider, $purpose ?: 'embedding', $response,
                (int) round((microtime(true) - $startedAt) * 1000));
            return $response;
        } catch (\Throwable $e) {
            Log::warning('EmbeddingClient call failed', ['provider' => $provider->name, 'error' => $e->getMessage()]);
            $this->logFailure($provider, $purpose ?: 'embedding',
                (int) round((microtime(true) - $startedAt) * 1000),
                $e instanceof AiException ? $e : new AiException($e->getMessage(), previous: $e));
            throw $e;
        }
    }

    public function driverFor(AiProvider $provider): EmbeddingDriver
    {
        return match ($provider->kind) {
            AiProvider::KIND_OPENAI => new OpenAiEmbeddingDriver($provider),
            AiProvider::KIND_OLLAMA => new OllamaEmbeddingDriver($provider),
            default => throw new AiException("Provider kind \"{$provider->kind}\" doesn't support embeddings."),
        };
    }

    private function logRequest(AiProvider $provider, string $purpose, EmbeddingResponse $response, int $durationMs): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $response->model,
            'purpose'       => $purpose,
            'status'        => 'ok',
            'tokens_in'     => $response->tokensIn,
            'tokens_out'    => 0,
            'duration_ms'   => $durationMs,
            'cost_microusd' => $this->estimateCostMicroUsd($response),
            'created_at'    => now(),
        ]);
    }

    private function logFailure(AiProvider $provider, string $purpose, int $durationMs, AiException $e): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $provider->embedding_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => $durationMs,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'created_at'    => now(),
        ]);
    }

    private function logBudgetBlock(AiProvider $provider, ?string $purpose, string $reason): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $provider->embedding_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => 0,
            'error_message' => 'Budget block: '.$reason,
            'created_at'    => now(),
        ]);
    }

    private function estimateCostMicroUsd(EmbeddingResponse $response): ?int
    {
        if ($response->tokensIn === null) return null;
        $model = strtolower((string) $response->model);
        foreach (self::PRICE_NANOUSD_PER_TOKEN as $fragment => $rate) {
            if (str_contains($model, $fragment)) {
                return (int) round($response->tokensIn * $rate / 1_000);
            }
        }
        return null;
    }
}
