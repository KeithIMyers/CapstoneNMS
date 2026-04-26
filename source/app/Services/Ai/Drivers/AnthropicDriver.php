<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API adapter (Claude). Requires the anthropic-version
 * header and splits the system prompt out of the messages array.
 *
 * Supports native tool use:
 *   - Normalized tools translate to Anthropic's
 *     { name, description, input_schema } shape.
 *   - Response content blocks of type "tool_use" come back parsed into
 *     AiResponse->toolCalls.
 *   - Tool-result continuations from the agent runner (role 'tool',
 *     tool_call_id, content) translate to Anthropic's user-message
 *     {type: tool_result, tool_use_id, content} content block.
 */
class AnthropicDriver implements AiDriver
{
    private const API_VERSION = '2023-06-01';

    public function __construct(private readonly AiProvider $provider) {}

    public function supportsTools(): bool { return true; }

    public function complete(array $messages, array $options = []): AiResponse
    {
        $model = $options['model'] ?? $this->provider->default_model ?? 'claude-3-5-sonnet-latest';
        $base  = rtrim($this->provider->base_url ?: 'https://api.anthropic.com/v1', '/');

        // Anthropic takes the system prompt as a top-level field. Pull
        // the first system message(s) out; the rest become messages.
        $systemParts = [];
        $chat = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                $systemParts[] = (string) ($m['content'] ?? '');
                continue;
            }
            $chat[] = $this->translateMessage($m);
        }

        $payload = array_filter([
            'model'          => $model,
            'system'         => $systemParts ? implode("\n\n", $systemParts) : null,
            'messages'       => $chat,
            'max_tokens'     => $options['max_tokens'] ?? $this->provider->max_output_tokens,
            'temperature'    => $options['temperature'] ?? 0.4,
            'stop_sequences' => $options['stop'] ?? null,
            'tools'          => empty($options['tools']) ? null : $this->translateTools($options['tools']),
        ], fn ($v) => $v !== null);

        $response = Http::withHeaders([
                'x-api-key'          => $this->provider->api_key ?? '',
                'anthropic-version'  => self::API_VERSION,
                'content-type'       => 'application/json',
            ])
            ->timeout(60)
            ->acceptJson()
            ->post($base.'/messages', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'Anthropic call failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_ANTHROPIC,
            );
        }

        $data = $response->json();

        // Response is a list of content blocks: text and/or tool_use.
        $text = '';
        $toolCalls = null;
        foreach ($data['content'] ?? [] as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'text') {
                $text .= $block['text'] ?? '';
            } elseif ($type === 'tool_use') {
                $toolCalls ??= [];
                $toolCalls[] = [
                    'id'        => (string) ($block['id'] ?? ''),
                    'name'      => (string) ($block['name'] ?? ''),
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        return new AiResponse(
            text: $text,
            tokensIn: $data['usage']['input_tokens'] ?? null,
            tokensOut: $data['usage']['output_tokens'] ?? null,
            model: $data['model'] ?? $model,
            finishReason: $data['stop_reason'] ?? null,
            raw: $data,
            toolCalls: $toolCalls,
        );
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->provider->default_model ?? 'claude-3-5-sonnet-latest';
        $base  = rtrim($this->provider->base_url ?: 'https://api.anthropic.com/v1', '/');

        // Same system / message split as the sync path.
        $systemParts = [];
        $chat = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? null) === 'system') {
                $systemParts[] = (string) ($m['content'] ?? '');
                continue;
            }
            $chat[] = $this->translateMessage($m);
        }

        $payload = array_filter([
            'model'          => $model,
            'system'         => $systemParts ? implode("\n\n", $systemParts) : null,
            'messages'       => $chat,
            'max_tokens'     => $options['max_tokens'] ?? $this->provider->max_output_tokens,
            'temperature'    => $options['temperature'] ?? 0.4,
            'stream'         => true,
        ], fn ($v) => $v !== null);

        $response = Http::withHeaders([
                'x-api-key'         => $this->provider->api_key ?? '',
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
                'accept'            => 'text/event-stream',
            ])
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->post($base.'/messages', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'Anthropic stream failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_ANTHROPIC,
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $tokensIn = null; $tokensOut = null;
        $buffer = '';
        $currentEvent = null;

        // Anthropic SSE events have an event: line followed by data:.
        // We care about content_block_delta (text deltas) and
        // message_delta (which carries final usage).
        while (! $body->eof()) {
            $buffer .= $body->read(4096);
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $eventType = null;
                $dataJson  = null;
                foreach (explode("\n", $event) as $line) {
                    if (str_starts_with($line, 'event:')) {
                        $eventType = trim(substr($line, 6));
                    } elseif (str_starts_with($line, 'data:')) {
                        $dataJson = trim(substr($line, 5));
                    }
                }
                if (! $dataJson) continue;
                $decoded = json_decode($dataJson, true);
                if (! is_array($decoded)) continue;

                if ($eventType === 'content_block_delta') {
                    $deltaType = $decoded['delta']['type'] ?? null;
                    if ($deltaType === 'text_delta') {
                        $text = $decoded['delta']['text'] ?? '';
                        if ($text !== '') yield $text;
                    }
                } elseif ($eventType === 'message_start') {
                    $tokensIn = $decoded['message']['usage']['input_tokens'] ?? $tokensIn;
                } elseif ($eventType === 'message_delta') {
                    $tokensOut = $decoded['usage']['output_tokens'] ?? $tokensOut;
                }
            }
        }

        yield ['done' => true, 'tokensIn' => $tokensIn, 'tokensOut' => $tokensOut, 'model' => $model];
    }

    public function ping(): bool
    {
        // Anthropic has no public list-models endpoint on all accounts;
        // a single-token completion is the most reliable ping.
        $resp = $this->complete(
            [['role' => 'user', 'content' => 'ok']],
            ['max_tokens' => 5, 'temperature' => 0]
        );
        return $resp->text !== '';
    }

    /**
     * Translate one normalized message into Anthropic's wire shape.
     * Covers four cases:
     *   - role 'user' or 'assistant' with plain text content
     *   - role 'assistant' with tool_calls → assistant message with
     *     tool_use content blocks
     *   - role 'tool' (our internal tool-result form) → user message
     *     with a tool_result content block
     */
    private function translateMessage(array $m): array
    {
        $role = $m['role'] ?? 'user';

        if ($role === 'tool') {
            return [
                'role' => 'user',
                'content' => [[
                    'type'        => 'tool_result',
                    'tool_use_id' => (string) ($m['tool_call_id'] ?? ''),
                    'content'     => (string) ($m['content'] ?? ''),
                ]],
            ];
        }

        if ($role === 'assistant' && ! empty($m['tool_calls'])) {
            $blocks = [];
            if (! empty($m['content'])) {
                $blocks[] = ['type' => 'text', 'text' => (string) $m['content']];
            }
            foreach ($m['tool_calls'] as $tc) {
                $blocks[] = [
                    'type'  => 'tool_use',
                    'id'    => (string) ($tc['id'] ?? ''),
                    'name'  => (string) ($tc['name'] ?? ''),
                    'input' => (object) ($tc['arguments'] ?? []),
                ];
            }
            return ['role' => 'assistant', 'content' => $blocks];
        }

        return ['role' => $role, 'content' => (string) ($m['content'] ?? '')];
    }

    /**
     * Translate normalized tool defs to Anthropic's
     * { name, description, input_schema } shape.
     */
    private function translateTools(array $tools): array
    {
        $out = [];
        foreach ($tools as $t) {
            $out[] = [
                'name'         => (string) ($t['name'] ?? ''),
                'description'  => (string) ($t['description'] ?? ''),
                'input_schema' => $t['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $out;
    }
}
