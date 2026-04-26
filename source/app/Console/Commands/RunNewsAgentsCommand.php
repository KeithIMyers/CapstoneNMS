<?php

namespace App\Console\Commands;

use App\Models\AgentStory;
use App\Models\NewsAgent;
use App\Services\News\NewsAgentDispatcher;
use Illuminate\Console\Command;

/**
 * Walks active news agents and processes their queued stories. For
 * each agent that's "due" by its schedule, picks up to posts_per_run
 * stories ordered by priority + scheduled_for and runs them through
 * the dispatcher.
 *
 * Idempotent: stories that succeed are stamped published; failures
 * stay queued but record their error so a re-run picks them up.
 *
 * DreamHost CRON: every 15 minutes.
 *
 *   /usr/local/php83/bin/php /path/to/install/artisan news-agents:run
 */
class RunNewsAgentsCommand extends Command
{
    protected $signature = 'news-agents:run
                            {--agent= : Run only the named agent by id}
                            {--story= : Run a single story by id (overrides --agent)}
                            {--force : Ignore schedule and run any agent that has queued stories}';

    protected $description = 'Process the news-agent story queue: research, draft, image, publish.';

    public function handle(NewsAgentDispatcher $dispatcher): int
    {
        if ($storyId = $this->option('story')) {
            $story = AgentStory::find((int) $storyId);
            if (! $story || ! $story->agent) {
                $this->error("No story id={$storyId} or its agent is missing.");
                return self::FAILURE;
            }
            $this->line("Dispatching story {$story->id} for agent {$story->agent->id}…");
            $r = $dispatcher->dispatchStory($story->agent, $story);
            $this->info($r['ok'] ? '✓ '.$r['message'] : '✗ '.$r['message']);
            return $r['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $agents = NewsAgent::active()->with('user')->get();
        if ($id = $this->option('agent')) {
            $agents = $agents->where('id', (int) $id);
        }

        $force = (bool) $this->option('force');
        $errors = 0;
        $dispatched = 0;

        foreach ($agents as $agent) {
            if (! $force && $agent->schedule_frequency && ! $agent->isDueNow()) {
                continue;
            }

            $stories = AgentStory::ready()
                ->where('agent_id', $agent->id)
                ->orderBy('priority')
                ->orderBy('scheduled_for')
                ->limit(max(1, (int) $agent->posts_per_run))
                ->get();

            if ($stories->isEmpty()) continue;

            $this->line("Agent {$agent->id} ({$agent->user?->name}): {$stories->count()} stor".($stories->count() === 1 ? 'y' : 'ies').' queued.');

            foreach ($stories as $story) {
                $this->line("  → {$story->topic}");
                $r = $dispatcher->dispatchStory($agent, $story);
                if ($r['ok']) {
                    $this->info('    ✓ '.$r['message']);
                    $dispatched++;
                } else {
                    $this->error('    ✗ '.$r['message']);
                    $errors++;
                }
            }

            $agent->forceFill(['last_run_at' => now()])->save();
        }

        $this->info("Done. Dispatched {$dispatched} story(ies) · {$errors} error(s).");
        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
