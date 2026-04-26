<?php

namespace App\Observers;

use App\Models\News;
use App\Services\Translation\ArticleAutoTranslator;
use Illuminate\Support\Facades\Log;

/**
 * On the rising-edge transition into the published state, walk the
 * article's auto_translate_locales list and produce sibling translations
 * via the ArticleAutoTranslator. Idempotent thanks to the translator's
 * own existing-sibling check.
 *
 * Translation siblings inherit the source's translation_group_id and
 * land as their own published rows so the existing /{locale}/news/{slug}
 * routes pick them up.
 */
class NewsAutoTranslateObserver
{
    public function updated(News $news): void
    {
        if (! $news->wasChanged('editorial_status')) return;
        if ($news->editorial_status !== News::STATUS_PUBLISHED) return;
        if ($news->getOriginal('editorial_status') === News::STATUS_PUBLISHED) return;

        $targets = $news->auto_translate_locales;
        if (! is_array($targets) || empty($targets)) return;

        // Skip when this article is itself a translation sibling — only
        // canonical articles drive translation. A sibling has a different
        // id from its translation_group_id; the canonical's group_id
        // equals its id (auto-set by News::booted).
        if ($news->translation_group_id && (int) $news->translation_group_id !== (int) $news->id) {
            return;
        }

        try {
            app(ArticleAutoTranslator::class)->translate($news, $targets);
        } catch (\Throwable $e) {
            Log::warning('Auto-translate observer threw', [
                'news_id' => $news->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
