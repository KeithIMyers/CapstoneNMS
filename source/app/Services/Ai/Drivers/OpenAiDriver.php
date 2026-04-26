<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Chat Completions adapter. Works with any OpenAI-compatible
 * endpoint (Together, Groq, Azure OpenAI with base_url adjustment). The
 * provider's base_url defaults to https://api.openai.com/v1 when blank.
 *
 * Supports native function calling when $options['tools'] is supplied.
 * The normalized tool definitions translate directly to OpenAI's
 * { "type": "function", "function": {...} } wrapper. The model's
 * tool_calls in the response come back parsed into AiResponse->toolCalls.
 *
 * The agent runner can also pass back tool-result turns in the
 * normalized {role: 'tool', tool_call_id, content} form — that's the
 * native OpenAI shape so we forward it as-is.
 */
class OpenAiDriver implements AiDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    public function supportsTools(): bool { return true; }

    public function complete(array $messages, array $options = []): AiResponse
    {
        $model = $options['model'] ?? $this->provider->default_model ?? 'gpt-4o-mini';
        $base  = rtrim($this->provider->base_url ?: 'https://api.openai.com/v1', '/');

        $payload = array_filter([
            'model'       => $model,
            'messages'    => $this->translateMessages($messages),
            'temperature' => $options['temperature'] ?? 0.4,
            'max_tokens'  => $options['max_tokens'] ?? $this->provider->max_output_tokens,
            'stop'        => $options['stop'] ?? null,
            'tools'       => empty($options['tools']) ? null : $this->translateTools($options['tools']),
        ], fn ($v) => $v !== null);

        $response = Http::withToken($this->provider->api_key ?? '')
            ->timeout(60)
            ->acceptJson()
            ->post($base.'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'OpenAI call failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OPENAI,
            );
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? [];

        $toolCalls = null;
        if (! empty($message['tool_calls'])) {
            $toolCalls = [];
            foreach ($message['tool_calls'] as $tc) {
                $args = $tc['function']['arguments'] ?? '{}';
                // OpenAI returns arguments as a JSON-encoded string.
                $decoded = is_string($args) ? json_decode($args, true) : $args;
                $toolCalls[] = [
                    'id'        => (string) ($tc['id'] ?? ''),
                    'name'      => (string) ($tc['function']['name'] ?? ''),
                    'arguments' => is_array($decoded) ? $decoded : [],
                ];
            }
        }

        return new AiResponse(
            text: (string) ($message['content'] ?? ''),
            tokensIn: $data['usage']['prompt_tokens'] ?? null,
            tokensOut: $data['usage']['completion_tokens'] ?? null,
            model: $data['model'] ?? $model,
            finishReason: $data['choices'][0]['finish_reason'] ?? null,
            raw: $data,
            toolCalls: $toolCalls,
        );
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->provider->default_model ?? 'gpt-4o-mini';
        $base  = rtrim($this->provider->base_url ?: 'https://api.openai.com/v1', '/');

        $payload = array_filter([
            'model'       => $model,
            'messages'    => $this->translateMessages($messages),
            'temperature' => $options['temperature'] ?? 0.4,
            'max_tokens'  => $options['max_tokens'] ?? $this->provider->max_output_tokens,
            'stream'      => true,
            'stream_options' => ['include_usage' => true],
        ], fn ($v) => $v !== null);

        // Use Guzzle's stream option through Laravel HTTP. The body comes
        // back as text/event-stream chunks: "data: {json}\n\n" until the
        // final "data: [DONE]\n\n".
        $response = Http::withToken($this->provider->api_key ?? '')
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->withHeaders(['Accept' => 'text/event-stream'])
            ->post($base.'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'OpenAI stream failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OPENAI,
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $tokensIn = null; $tokensOut = null;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(4096);
            // SSE events terminate on a blank line ("\n\n").
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                foreach (explode("\n", $event) as $line) {
                    if (! str_starts_with($line, 'data:')) continue;
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') continue;

                    $decoded = json_decode($data, true);
                    if (! is_array($decoded)) continue;

                    $delta = $decoded['choices'][0]['delta']['content'] ?? '';
                    if ($delta !== '') yield $delta;

                    // OpenAI sends a final chunk with usage when
                    // include_usage is set; capture it for the audit log.
                    if (isset($decoded['usage'])) {
                        $tokensIn  = $decoded['usage']['prompt_tokens']     ?? $tokensIn;
                        $tokensOut = $decoded['usage']['completion_tokens'] ?? $tokensOut;
                    }
                }
            }
        }

        yield ['done' => true, 'tokensIn' => $tokensIn, 'tokensOut' => $tokensOut, 'model' => $model];
    }

    public function ping(): bool
    {
        $base = rtrim($this->provider->base_url ?: 'https://api.openai.com/v1', '/');
        $response = Http::withToken($this->provider->api_key ?? '')
            ->timeout(10)
            ->get($base.'/models');

        if (! $response->successful()) {
            throw new AiException(
                'OpenAI ping failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OPENAI,
            );
        }
        return true;
    }

    /**
     * Translate normalized messages to OpenAI's wire format. Most fields
     * pass through unchanged because the normalized format is OpenAI-
     * shaped; we only need to ensure assistant tool-call turns serialize
     * arguments back to JSON strings.
     */
    private function translateMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $entry = ['role' => $m['role'] ?? 'user'];

            if (($m['role'] ?? null) === 'tool') {
                // Tool result turn — OpenAI shape.
                $entry['tool_call_id'] = (string) ($m['tool_call_id'] ?? '');
                $entry['content']      = (string) ($m['content'] ?? '');
                $out[] = $entry;
                continue;
            }

            if (! empty($m['tool_calls'])) {
                $entry['tool_calls'] = array_map(fn ($tc) => [
                    'id'   => (string) ($tc['id'] ?? ''),
                    'type' => 'function',
                    'function' => [
                        'name'      => (string) ($tc['name'] ?? ''),
                        'arguments' => json_encode($tc['arguments'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
                    ],
                ], $m['tool_calls']);
                // OpenAI permits null content with tool_calls; coerce to ''.
                $entry['content'] = (string) ($m['content'] ?? '');
                $out[] = $entry;
                continue;
            }

            $entry['content'] = (string) ($m['content'] ?? '');
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Wrap normalized tool definitions in OpenAI's
     * { type: function, function: { name, description, parameters } }
     * envelope.
     */
    private function translateTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name'        => (string) ($t['name'] ?? ''),
                    'description' => (string) ($t['description'] ?? ''),
                    'parameters'  => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $out;
    }
}
