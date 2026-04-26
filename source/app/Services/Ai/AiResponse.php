<?php

namespace App\Services\Ai;

/**
 * Normalized completion response returned by every driver. Keeps call
 * sites provider-agnostic: they read $response->text regardless of
 * whether the underlying API was OpenAI chat.completions, Anthropic
 * messages, or Ollama chat.
 *
 * When a driver natively supports function calling and the caller
 * passed tools, $toolCalls carries the model's structured tool-call
 * intents in a normalized shape:
 *
 *   [
 *     ['id' => 'call_abc', 'name' => 'search_articles', 'arguments' => [...]],
 *     ...
 *   ]
 *
 * Drivers that don't support native tool calls (Ollama) leave this
 * null; the Agent runner falls back to its JSON-in-prompt protocol.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly ?int $tokensIn = null,
        public readonly ?int $tokensOut = null,
        public readonly ?string $model = null,
        public readonly ?string $finishReason = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
        /** @var array<int, array{id?:string,name:string,arguments:array<string,mixed>}>|null */
        public readonly ?array $toolCalls = null,
    ) {}
}
