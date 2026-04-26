<?php

namespace App\Observers;

use App\Models\News;
use App\Services\Social\PosterRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Auto-post articles to every configured + enabled social channel on
 * the published transition. The PosterRegistry walks each registered
 * channel; per-channel failures are isolated so one misconfigured
 * service can't block the others.
 *
 * Idempotency lives in the social_posts table's unique key on
 * (news_id, channel), so a republish or status flip doesn't fan out
 * duplicate posts.
 */
class NewsSocialPostObserver
{
    public function updated(News $news): void
    {
        if (! $news->wasChanged('editorial_status')) return;
        if ($news->editorial_status !== News::STATUS_PUBLISHED) return;
        if ($news->getOriginal('editorial_status') === News::STATUS_PUBLISHED) return;

        // Sponsored content: skip social auto-post by default. Editors
        // who want it shared can post manually from the article page.
        if ($news->is_sponsored) return;

        try {
            app(PosterRegistry::class)->dispatch($news);
        } catch (\Throwable $e) {
            Log::warning('Social PosterRegistry threw', [
                'news_id' => $news->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
