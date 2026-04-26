<?php

namespace App\Services\Ai\Drivers;

use App\Models\AiProvider;
use App\Services\Ai\AiException;
use App\Services\Ai\AiResponse;
use Illuminate\Support\Facades\Http;

/**
 * Ollama local/self-hosted adapter. Talks to /api/chat; no auth required
 * by default (Ollama expects trust at the network layer). stream=false
 * because we want a single JSON response back rather than NDJSON chunks.
 */
class OllamaDriver implements AiDriver
{
    public function __construct(private readonly AiProvider $provider) {}

    /**
     * Ollama does have a tools parameter on /api/chat in recent builds,
     * but the implementation varies by model — many smaller models
     * silently ignore tools and return prose. We default to false so
     * the Agent runner falls back to its JSON-in-prompt envelope, which
     * works reliably on every model that can follow instructions.
     */
    public function supportsTools(): bool { return false; }

    public function complete(array $messages, array $options = []): AiResponse
    {
        $model = $options['model'] ?? $this->provider->default_model ?? 'llama3.1';
        $base  = rtrim($this->provider->base_url ?: 'http://localhost:11434', '/');

        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => array_filter([
                'temperature' => $options['temperature'] ?? 0.4,
                'num_predict' => $options['max_tokens'] ?? $this->provider->max_output_tokens,
                'stop'        => $options['stop'] ?? null,
            ], fn ($v) => $v !== null),
        ];

        $response = Http::timeout(120) // local can be slow on larger models
            ->acceptJson()
            ->when(
                $this->provider->api_key,
                fn ($http) => $http->withToken($this->provider->api_key) // for gateways in front of Ollama
            )
            ->post($base.'/api/chat', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'Ollama call failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OLLAMA,
            );
        }

        $data = $response->json();
        return new AiResponse(
            text: (string) ($data['message']['content'] ?? ''),
            // Ollama returns prompt_eval_count / eval_count when the model
            // supports it; map to the OpenAI-style fields.
            tokensIn: $data['prompt_eval_count'] ?? null,
            tokensOut: $data['eval_count'] ?? null,
            model: $data['model'] ?? $model,
            finishReason: $data['done_reason'] ?? ($data['done'] ?? null ? 'stop' : null),
            raw: $data,
        );
    }

    public function stream(array $messages, array $options = []): \Generator
    {
        $model = $options['model'] ?? $this->provider->default_model ?? 'llama3.1';
        $base  = rtrim($this->provider->base_url ?: 'http://localhost:11434', '/');

        $payload = [
            'model'    => $model,
            'messages' => $messages,
            'stream'   => true,
            'options'  => array_filter([
                'temperature' => $options['temperature'] ?? 0.4,
                'num_predict' => $options['max_tokens'] ?? $this->provider->max_output_tokens,
                'stop'        => $options['stop'] ?? null,
            ], fn ($v) => $v !== null),
        ];

        // Ollama streams NDJSON: one JSON object per line until done=true.
        $response = Http::withOptions(['stream' => true])
            ->timeout(120)
            ->when($this->provider->api_key, fn ($h) => $h->withToken($this->provider->api_key))
            ->post($base.'/api/chat', $payload);

        if (! $response->successful()) {
            throw new AiException(
                'Ollama stream failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OLLAMA,
            );
        }

        $body = $response->toPsrResponse()->getBody();
        $tokensIn = null; $tokensOut = null;
        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(4096);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '') continue;

                $decoded = json_decode($line, true);
                if (! is_array($decoded)) continue;

                $delta = $decoded['message']['content'] ?? '';
                if ($delta !== '') yield $delta;

                if (! empty($decoded['done'])) {
                    $tokensIn  = $decoded['prompt_eval_count'] ?? $tokensIn;
                    $tokensOut = $decoded['eval_count']        ?? $tokensOut;
                }
            }
        }

        yield ['done' => true, 'tokensIn' => $tokensIn, 'tokensOut' => $tokensOut, 'model' => $model];
    }

    public function ping(): bool
    {
        $base = rtrim($this->provider->base_url ?: 'http://localhost:11434', '/');
        $response = Http::timeout(5)->get($base.'/api/tags');

        if (! $response->successful()) {
            throw new AiException(
                'Ollama ping failed: '.$response->body(),
                $response->status(),
                AiProvider::KIND_OLLAMA,
            );
        }
        return true;
    }
}
