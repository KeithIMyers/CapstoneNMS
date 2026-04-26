<?php

namespace App\Console\Commands;

use App\Models\AiProvider;
use App\Services\Ai\AiClient;
use App\Services\Ai\EmbeddingClient;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Console\Command;

/**
 * Surface schema drift and broken integrations early. Walks every
 * configured AiProvider and runs four small checks against each:
 *
 *   1. ping        — driver->ping() against the provider endpoint
 *   2. complete    — minimal sync completion (verifies auth + base URL)
 *   3. tool call   — single-tool completion to confirm native function
 *                    calling still parses (skipped on Ollama)
 *   4. embedding   — embedding round-trip when the provider has an
 *                    embedding_model configured
 *
 * Designed for either ad-hoc debugging or a low-frequency CRON
 * (e.g., weekly) that emails the maintainer if any check fails.
 *
 *   php artisan ai:diagnose
 *   php artisan ai:diagnose --provider=anthropic-prod
 *   php artisan ai:diagnose --skip-embeddings
 */
class DiagnoseAiCommand extends Command
{
    protected $signature = 'ai:diagnose
                            {--provider= : Run only against the named provider}
                            {--skip-embeddings : Skip embedding round-trip checks}
                            {--skip-tools : Skip native tool-call checks}';

    protected $description = 'Run health checks against each configured AI provider and report schema drift';

    public function handle(AiClient $client, EmbeddingClient $embedClient, ToolRegistry $tools): int
    {
        $query = AiProvider::query();
        if ($name = $this->option('provider')) {
            $query->where('name', $name);
        }
        $providers = $query->get();

        if ($providers->isEmpty()) {
            $this->error('No matching providers configured.');
            return self::FAILURE;
        }

        $allOk = true;

        foreach ($providers as $provider) {
            $this->line('');
            $this->line("=== {$provider->name} [{$provider->kind}] ===");

            // 1. Ping
            $allOk = $this->runCheck('ping', function () use ($client, $provider) {
                $client->driverFor($provider)->ping();
            }) && $allOk;

            // 2. Sync completion (skip if provider isn't active — keeps us
            //    from burning tokens on rows the user has paused).
            if ($provider->is_active) {
                $allOk = $this->runCheck('complete (sync)', function () use ($client, $provider) {
                    $resp = $client->complete(
                        messages: [
                            ['role' => 'system', 'content' => 'Answer in exactly one word.'],
                            ['role' => 'user',   'content' => 'Reply with: ok'],
                        ],
                        provider: $provider,
                        purpose: 'diagnose.complete',
                        options: ['max_tokens' => 5, 'temperature' => 0],
                    );
                    if (trim($resp->text) === '') {
                        throw new \RuntimeException('Empty completion text');
                    }
                }) && $allOk;
            } else {
                $this->line('  · complete (sync): skipped (provider inactive)');
            }

            // 3. Native tool call. Skipped for drivers that don't support
            //    tools or when --skip-tools is set.
            if (! $this->option('skip-tools') && $provider->is_active) {
                $driver = $client->driverFor($provider);
                if (! $driver->supportsTools()) {
                    $this->line('  · tool call:      skipped ('.$provider->kind.' uses JSON-in-prompt)');
                } else {
                    $allOk = $this->runCheck('tool call', function () use ($client, $provider) {
                        $resp = $client->complete(
                            messages: [
                                ['role' => 'system', 'content' => 'You MUST call the search_articles tool with query="ok" exactly once before answering.'],
                                ['role' => 'user',   'content' => 'Run the tool now.'],
                            ],
                            provider: $provider,
                            purpose: 'diagnose.tool_call',
                            options: [
                                'max_tokens' => 100,
                                'temperature' => 0,
                                'tools' => [[
                                    'name' => 'search_articles',
                                    'description' => 'Search published articles by keyword.',
                                    'parameters' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'query' => ['type' => 'string', 'description' => 'keywords'],
                                        ],
                                        'required' => ['query'],
                                    ],
                                ]],
                            ],
                        );
                        if (empty($resp->toolCalls)) {
                            throw new \RuntimeException('Driver did not return tool_calls — schema drift?');
                        }
                        if (($resp->toolCalls[0]['name'] ?? '') !== 'search_articles') {
                            throw new \RuntimeException('Tool call name mismatch: '.($resp->toolCalls[0]['name'] ?? '(empty)'));
                        }
                    }) && $allOk;
                }
            }

            // 4. Embedding round-trip.
            if (! $this->option('skip-embeddings') && $provider->is_active && $provider->embedding_model) {
                $allOk = $this->runCheck('embedding', function () use ($embedClient, $provider) {
                    $resp = $embedClient->embed('diagnostic round-trip', $provider, purpose: 'diagnose.embedding');
                    if ($resp->dimensions() === 0) {
                        throw new \RuntimeException('Empty vector returned');
                    }
                }) && $allOk;
            } elseif ($provider->is_active) {
                $this->line('  · embedding:      skipped (no embedding_model on this provider)');
            }
        }

        $this->line('');
        if ($allOk) {
            $this->info('All checks passed.');
            return self::SUCCESS;
        }
        $this->error('One or more checks failed — see above for the offending provider.');
        return self::FAILURE;
    }

    /** Returns true on success, false on caught failure. Always prints a status line. */
    private function runCheck(string $label, \Closure $check): bool
    {
        try {
            $startedAt = microtime(true);
            $check();
            $ms = (int) round((microtime(true) - $startedAt) * 1000);
            $this->info(sprintf('  ✓ %s%s· %d ms',
                $label,
                str_repeat(' ', max(1, 16 - strlen($label))),
                $ms));
            return true;
        } catch (\Throwable $e) {
            $this->error(sprintf('  ✗ %s%s· %s',
                $label,
                str_repeat(' ', max(1, 16 - strlen($label))),
                \Illuminate\Support\Str::limit($e->getMessage(), 200)));
            return false;
        }
    }
}
