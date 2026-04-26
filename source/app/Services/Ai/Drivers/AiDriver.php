<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiResponse;

/**
 * Contract every LLM backend adapter must implement. Keep the surface
 * minimal so adding a new provider is just a new driver class, not
 * another round of call-site changes.
 *
 *   $messages is an ordered list of ['role' => 'system'|'user'|'assistant', 'content' => '...']
 *   $options supports: model, temperature, max_tokens, stop (array).
 */
interface AiDriver
{
    public function __construct(AiProvider $provider);

    /**
     * @param array<int, array<string, mixed>> $messages Normalized chat
     *     history. Each entry has at minimum role + content; assistant
     *     turns that requested tools may also carry tool_calls (an
     *     array of {id, name, arguments}); tool-result turns use role
     *     'tool' with tool_call_id + content.
     * @param array<string, mixed> $options Recognized keys: model,
     *     temperature, max_tokens, stop, tools (normalized list of
     *     tool definitions). tools is silently ignored on drivers that
     *     don't support native function calling.
     */
    public function complete(array $messages, array $options = []): AiResponse;

    /**
     * Whether this driver speaks the provider-native function-calling
     * protocol. When false, the Agent runner falls back to its
     * JSON-in-prompt envelope. We default Ollama to false because tool
     * support across local models is inconsistent.
     */
    public function supportsTools(): bool;

    /**
     * Stream a completion. Yields text deltas as the provider produces
     * them, then a final ['done' => true, 'tokensIn' => …, 'tokensOut' => …]
     * marker so the AiClient can log usage.
     *
     * Streaming is currently only used for in-editor assistant calls
     * — the agent loop still uses sync complete() because it needs the
     * full response (and tool_calls) before it can dispatch the next
     * step.
     *
     * @return \Generator<int, string|array<string,mixed>, void, void>
     */
    public function stream(array $messages, array $options = []): \Generator;

    /**
     * Lightweight connectivity check. Most drivers ping a list-models or
     * tiny-completion endpoint. Returns true on 2xx; throws AiException
     * on failure so callers can surface the reason.
     */
    public function ping(): bool;
}
