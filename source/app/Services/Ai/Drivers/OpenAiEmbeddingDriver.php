<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\EmbeddingResponse;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI /v1/embeddings adapter. Works against any OpenAI-compatible
 * embeddings endpoint by overriding the provider's base_url (Together,
 * LiteLLM, Azure, etc.).
 */
class OpenAiEmbeddingDriver implements EmbeddingDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    public function embed(string $text, ?string $model = null): EmbeddingResponse
    {
        $model ??= $this->provider->embedding_model ?: 'text-embedding-3-small';
        $base   = rtrim($this->provider->base_url ?: 'https://api.openai.com/v1', '/');

        $response = Http::withToken($this->provider->api_key ?? '')
            ->timeout(30)
            ->acceptJson()
            ->post($base.'/embeddings', [
                'model' => $model,
                'input' => $text,
            ]);

        if (! $response->successful()) {
            throw new AiException(
                'OpenAI embedding failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OPENAI,
            );
        }

        $data = $response->json();
        $vector = $data['data'][0]['embedding'] ?? [];

        return new EmbeddingResponse(
            vector: array_map('floatval', (array) $vector),
            model: $data['model'] ?? $model,
            tokensIn: $data['usage']['prompt_tokens'] ?? null,
            raw: $data,
        );
    }
}
