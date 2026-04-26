<?php

namespace App\Services\Ai;

/**
 * Normalized embedding response. Tokens-in is reported by OpenAI and
 * usually-not by Ollama; nullable handles both.
 */
final class EmbeddingResponse
{
    public function __construct(
        /** @var array<int, float> */
        public readonly array $vector,
        public readonly string $model,
        public readonly ?int $tokensIn = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    public function dimensions(): int
    {
        return count($this->vector);
    }
}
