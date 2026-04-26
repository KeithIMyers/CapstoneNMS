<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use App\Services\Ai\Agent;
use Illuminate\Console\Command;

/**
 * Walks ai_agents, runs any whose schedule next-fire window has passed,
 * and stamps last_run_at on success. Runs serially because shared
 * hosting has no real queue worker; each run is fast (one agent loop)
 * so this is fine for a handful of scheduled agents.
 *
 * DreamHost CRON setup: run every 15 minutes.
 *   /usr/local/php83/bin/php /path/to/install/artisan agents:run-scheduled
 */
class RunScheduledAgentsCommand extends Command
{
    protected $signature = 'agents:run-scheduled
                            {--dry-run : List due agents but don\'t actually run them}';

    protected $description = 'Run any AI agents whose schedule has come due';

    public function handle(Agent $agent): int
    {
        $dueAgents = AiAgent::active()
            ->whereNotNull('schedule_frequency')
            ->whereNotNull('standing_input')
            ->get()
            ->filter(fn ($a) => $a->isDueNow());

        if ($dueAgents->isEmpty()) {
            $this->info('No agents are due.');
            return self::SUCCESS;
        }

        $this->info('Due now: '.$dueAgents->count().' agent(s)');

        if ($this->option('dry-run')) {
            foreach ($dueAgents as $a) {
                $this->line(sprintf(
                    '  - %s (%s) · last run: %s',
                    $a->key,
                    $a->schedule_frequency,
                    optional($a->last_run_at)->diffForHumans() ?? 'never',
                ));
            }
            return self::SUCCESS;
        }

        $errors = 0;
        foreach ($dueAgents as $a) {
            $this->line("→ Running {$a->key}…");
            try {
                $run = $agent->run(
                    agentKey: $a->key,
                    input: $a->standing_input,
                    userId: null,
                );
                if ($run->status === \App\Models\AgentRun::STATUS_DONE) {
                    $a->forceFill(['last_run_at' => now()])->save();
                    $this->info(sprintf(
                        '  ✓ done · %d steps · %s',
                        $run->iterations,
                        number_format($run->tokens_in_total + $run->tokens_out_total).' tokens',
                    ));
                } else {
                    $errors++;
                    $this->error('  ✗ '.($run->error_message ?? 'failed'));
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error('  ✗ '.$e->getMessage());
            }
        }

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
