<?php

namespace App\Observers;

use App\Models\News;
use App\Services\Push\WebPusher;
use Illuminate\Support\Facades\Log;

/**
 * Send a Web Push notification when an article transitions to is_breaking
 * = true while published. Fires on the rising edge only — flipping the
 * flag back and forth doesn't repeat the broadcast (already-pushed
 * articles set news.broadcast_breaking_at via this observer's update).
 *
 * Fail-soft on every layer: missing VAPID config = no-op, missing
 * web-push library = no-op, individual subscription failure = pruned.
 */
class NewsBreakingPushObserver
{
    public function updated(News $news): void
    {
        if (! $news->is_breaking) return;
        if (! $news->wasChanged('is_breaking') && ! $news->wasChanged('editorial_status')) return;

        // Only push when published or transitioning to published.
        if ($news->editorial_status !== News::STATUS_PUBLISHED) return;

        // Use the activity log as the dedup key — if we've already
        // logged a "breaking_push" for this article, skip. Cheap query
        // and reuses an existing audit channel.
        $alreadyPushed = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', News::class)
            ->where('subject_id', $news->id)
            ->where('description', 'breaking_push')
            ->exists();
        if ($alreadyPushed) return;

        $pusher = app(WebPusher::class);
        if (! $pusher->isConfigured()) return;

        try {
            $stats = $pusher->broadcast([
                'title' => 'Breaking · '.(getcong('site_name') ?: config('app.name')),
                'body'  => \Illuminate\Support\Str::limit((string) $news->title, 240),
                'url'   => route('news.details', ['slug' => $news->slug]),
                'tag'   => 'usnt-breaking-'.$news->id,
            ]);
            activity('news')
                ->performedOn($news)
                ->withProperties($stats)
                ->log('breaking_push');
        } catch (\Throwable $e) {
            Log::warning('Breaking push observer failed', ['news_id' => $news->id, 'error' => $e->getMessage()]);
        }
    }
}
