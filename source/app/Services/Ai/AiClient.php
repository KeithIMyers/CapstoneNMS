<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiRequest;
use App\Services\Ai\Drivers\AiDriver;
use App\Services\Ai\Drivers\AnthropicDriver;
use App\Services\Ai\Drivers\OllamaDriver;
use App\Services\Ai\Drivers\OpenAiDriver;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point every assistant / agent calls. Resolves the active
 * provider, picks the right driver, runs the completion, and logs the
 * request to ai_requests for audit + cost accounting.
 *
 * Usage:
 *   $client = app(AiClient::class);
 *   $response = $client->complete(
 *       messages: [
 *           ['role' => 'system', 'content' => 'You are a copy editor…'],
 *           ['role' => 'user',   'content' => $bodyText],
 *       ],
 *       purpose: 'article.copyedit',
 *   );
 *   echo $response->text;
 *
 * Specific providers can be pinned per call with `provider:` — handy
 * when one agent uses Claude for writing and a cheaper Ollama model
 * for classification.
 */
class AiClient
{
    /**
     * Pricing table in **nano-USD per token** (1 nano-USD = 0.001 micro-USD).
     * The numbers therefore read back directly as the listed "$/1M tokens"
     * amount: gpt-4o-mini at [150, 600] means $0.15 input / $0.60 output
     * per million tokens.
     */
    private const PRICE_NANOUSD_PER_TOKEN = [
        'gpt-4o'                   => [2_500, 10_000],
        'gpt-4o-mini'              => [150,    600],
        'gpt-4.1'                  => [2_000,  8_000],
        'claude-3-5-sonnet'        => [3_000, 15_000],
        'claude-3-5-haiku'         => [800,   4_000],
        'claude-sonnet-4'          => [3_000, 15_000],
        'claude-opus'              => [15_000, 75_000],
        // Ollama is free; nothing to charge.
    ];

    /**
     * @param array<int, array{role:string,content:string}> $messages
     */
    public function complete(
        array $messages,
        ?AiProvider $provider = null,
        ?string $purpose = null,
        array $options = [],
    ): AiResponse {
        $provider ??= AiProvider::defaultProvider();
        if (! $provider) {
            throw new AiException('No AI provider configured. Add one under Admin → AI → Providers.');
        }

        // Per-editor gate runs first — it's the cheaper check and the
        // more meaningful one to surface to the calling editor. Skipped
        // for unauthenticated dispatches (CRON, webhook).
        $user = auth()->user();
        if ($user && ($userBlock = $user->aiBlockReason())) {
            $this->logBudgetBlock($provider, $purpose, $userBlock);
            throw new AiException($userBlock, httpStatus: 402, providerKind: $provider->kind);
        }

        // Per-provider gate: hard budget cap on the org's spend through
        // this backend. Refuses the call when over its daily/monthly cap.
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
            $response = $driver->complete($messages, $options);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logRequest($provider, $purpose, $response, $durationMs, status: 'ok');

            return $response;
        } catch (AiException $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logFailure($provider, $purpose, $durationMs, $e);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('AiClient unexpected failure', ['exception' => $e]);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logFailure($provider, $purpose, $durationMs, new AiException($e->getMessage(), previous: $e));
            throw $e;
        }
    }

    /**
     * Stream a completion. Yields text deltas to the caller; consumes
     * the driver's terminator chunk to log a single audit row covering
     * the whole stream once it completes.
     *
     * Use for in-editor "watch the answer arrive" UX. Agents still call
     * complete() because the loop needs the full response (tool_calls
     * included) before dispatching the next step.
     *
     * @return \Generator<int, string, void, void>
     */
    public function stream(
        array $messages,
        ?AiProvider $provider = null,
        ?string $purpose = null,
        array $options = [],
    ): \Generator {
        $provider ??= AiProvider::defaultProvider();
        if (! $provider) {
            throw new AiException('No AI provider configured.');
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
        $tokensIn = null; $tokensOut = null; $modelUsed = null;

        try {
            foreach ($driver->stream($messages, $options) as $chunk) {
                if (is_array($chunk) && ($chunk['done'] ?? false)) {
                    $tokensIn  = $chunk['tokensIn']  ?? null;
                    $tokensOut = $chunk['tokensOut'] ?? null;
                    $modelUsed = $chunk['model']     ?? null;
                    continue;
                }
                if (is_string($chunk)) yield $chunk;
            }
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logFailure($provider, $purpose, $durationMs,
                $e instanceof AiException ? $e : new AiException($e->getMessage(), previous: $e));
            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $synthetic = new AiResponse(
            text: '',
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            model: $modelUsed ?? $provider->default_model,
        );
        $this->logRequest($provider, $purpose, $synthetic, $durationMs, status: 'ok');
    }

    /** Build the right driver for a provider row. */
    public function driverFor(AiProvider $provider): AiDriver
    {
        return match ($provider->kind) {
            AiProvider::KIND_OPENAI    => new OpenAiDriver($provider),
            AiProvider::KIND_ANTHROPIC => new AnthropicDriver($provider),
            AiProvider::KIND_OLLAMA    => new OllamaDriver($provider),
            default => throw new AiException("Unknown AI provider kind: {$provider->kind}"),
        };
    }

    private function logBudgetBlock(AiProvider $provider, ?string $purpose, string $reason): void
    {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $provider->default_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => 0,
            'error_message' => 'Budget block: '.$reason,
            'created_at'    => now(),
        ]);
    }

    private function logRequest(
        AiProvider $provider,
        ?string $purpose,
        AiResponse $response,
        int $durationMs,
        string $status = 'ok',
    ): void {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $response->model,
            'purpose'       => $purpose,
            'status'        => $status,
            'tokens_in'     => $response->tokensIn,
            'tokens_out'    => $response->tokensOut,
            'duration_ms'   => $durationMs,
            'cost_microusd' => $this->estimateCostMicroUsd($response),
            'created_at'    => now(),
        ]);
    }

    private function logFailure(
        AiProvider $provider,
        ?string $purpose,
        int $durationMs,
        AiException $e,
    ): void {
        AiRequest::create([
            'provider_id'   => $provider->id,
            'user_id'       => optional(auth()->user())->id,
            'model'         => $provider->default_model,
            'purpose'       => $purpose,
            'status'        => 'error',
            'duration_ms'   => $durationMs,
            'error_message' => mb_substr($e->getMessage(), 0, 2000),
            'created_at'    => now(),
        ]);
    }

    /**
     * Cost estimate in integer micro-dollars. Uses a crude substring match
     * against the known price table; rows we can't price (Ollama, unknown
     * models) return null rather than a misleading zero.
     */
    private function estimateCostMicroUsd(AiResponse $response): ?int
    {
        if ($response->tokensIn === null || $response->tokensOut === null) {
            return null;
        }
        $model = strtolower((string) $response->model);
        foreach (self::PRICE_NANOUSD_PER_TOKEN as $fragment => [$inRate, $outRate]) {
            if (str_contains($model, $fragment)) {
                // tokens * (nano-USD/token) = total nano-USD; /1000 → micro-USD.
                return (int) round(
                    ($response->tokensIn  * $inRate  + $response->tokensOut * $outRate)
                  / 1_000
                );
            }
        }
        return null;
    }
}
