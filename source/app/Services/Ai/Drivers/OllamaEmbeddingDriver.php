<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\EmbeddingResponse;
use Illuminate\Support\Facades\Http;

/**
 * Ollama /api/embed adapter. Newer Ollama builds expose /api/embed
 * (returns { "embeddings": [[...]], "model": "..." }); older ones used
 * /api/embeddings (singular response shape). We try the new endpoint
 * first and fall back to the old one on 404 so this works against
 * either.
 */
class OllamaEmbeddingDriver implements EmbeddingDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    public function embed(string $text, ?string $model = null): EmbeddingResponse
    {
        $model ??= $this->provider->embedding_model ?: 'nomic-embed-text';
        $base   = rtrim($this->provider->base_url ?: 'http://localhost:11434', '/');

        $http = Http::timeout(60)->acceptJson()
            ->when($this->provider->api_key, fn ($h) => $h->withToken($this->provider->api_key));

        // Try the new endpoint first.
        $response = $http->post($base.'/api/embed', [
            'model' => $model,
            'input' => $text,
        ]);

        if ($response->status() === 404) {
            // Fall back to the legacy endpoint shape.
            $response = $http->post($base.'/api/embeddings', [
                'model'  => $model,
                'prompt' => $text,
            ]);
        }

        if (! $response->successful()) {
            throw new AiException(
                'Ollama embedding failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OLLAMA,
            );
        }

        $data = $response->json();

        // /api/embed   → { "embeddings": [[...]] }
        // /api/embeddings → { "embedding": [...] }
        $vector = $data['embeddings'][0] ?? $data['embedding'] ?? [];

        return new EmbeddingResponse(
            vector: array_map('floatval', (array) $vector),
            model: $data['model'] ?? $model,
            tokensIn: $data['prompt_eval_count'] ?? null,
            raw: $data,
        );
    }
}
