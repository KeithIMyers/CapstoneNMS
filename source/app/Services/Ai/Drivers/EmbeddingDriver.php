<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\EmbeddingResponse;

/**
 * Contract for embedding adapters. Mirrors the chat-completion AiDriver
 * interface but produces a vector instead of text. Anthropic doesn't
 * offer embeddings as of this writing, so only OpenAI- and Ollama-
 * compatible drivers exist.
 */
interface EmbeddingDriver
{
    public function __construct(AiProvider $provider);

    public function embed(string $text, ?string $model = null): EmbeddingResponse;
}
