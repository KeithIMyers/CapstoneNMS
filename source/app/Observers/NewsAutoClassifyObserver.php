<?php

namespace App\Observers;

use App\Models\AiAgent;
use App\Models\AiProvider;
use App\Models\News;
use App\Services\Ai\Agent;
use Illuminate\Support\Facades\Log;

/**
 * Auto-classify articles into topics when they transition into the
 * "published" state. Runs the editorial.topic_classifier agent inline
 * — fast enough not to bother queueing on shared hosting, and the
 * runtime sits inside whatever request triggered the save (admin
 * publish click, scheduled-publish CRON, API call).
 *
 * Gate: Site Settings → AI moderation → "Auto-classify articles into
 * topics on publish". Defaults OFF; admins opt in once the topic
 * roster is curated.
 *
 * Fail-soft on every error path. We never want a flaky LLM to break
 * an editor's publish flow.
 */
class NewsAutoClassifyObserver
{
    private const AGENT_KEY = 'editorial.topic_classifier';

    public function updated(News $news): void
    {
        if (! $this->shouldRun($news)) return;

        try {
            // Run inline. The classifier reads the article and pins it
            // to one topic; ~2-3 LLM calls in total.
            app(Agent::class)->run(
                agentKey: self::AGENT_KEY,
                input: "Classify article id={$news->id} into the most appropriate active topic, or recommend creating a new one.",
                userId: optional(auth()->user())->id,
            );
        } catch (\Throwable $e) {
            Log::warning('Auto-classify failed', [
                'news_id' => $news->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function shouldRun(News $news): bool
    {
        $enabled = function_exists('getcong') ? getcong('ai_auto_classify_enabled') : null;
        if (! in_array(strtolower((string) $enabled), ['1', 'true', 'on', 'yes'], true)) {
            return false;
        }

        // Only fire on the published transition. isDirty('editorial_status')
        // is true the instant the column flipped; getOriginal() reads the
        // previous DB value.
        if (! $news->wasChanged('editorial_status')) return false;
        if ($news->editorial_status !== News::STATUS_PUBLISHED) return false;
        $previous = $news->getOriginal('editorial_status');
        if ($previous === News::STATUS_PUBLISHED) return false;

        // Skip if already pinned to a topic.
        if ($news->topics()->exists()) return false;

        // Skip when no provider or no agent is configured.
        if (! AiProvider::active()->exists()) return false;
        if (! AiAgent::where('key', self::AGENT_KEY)->where('is_active', true)->exists()) return false;

        return true;
    }
}
