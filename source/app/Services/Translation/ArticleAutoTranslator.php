<?php

namespace App\Services\Translation;

use App\Models\News;
use App\Services\Ai\Assistant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Auto-translate an article into a list of target locales by reusing
 * the existing Phase G3 translation_group plumbing.
 *
 * For each target locale that doesn't already have a sibling under
 * the same translation_group_id:
 *   1. Run the article.translate Assistant prompt with the title +
 *      excerpt + body wrapped in section markers.
 *   2. Parse the three sections; fall back to the whole response if
 *      markers are missing.
 *   3. Replicate the source row into a new draft News with the
 *      translated content + the target locale.
 *
 * Idempotent: re-running on an article that already has every target
 * locale populated is a no-op.
 */
class ArticleAutoTranslator
{
    public function translate(News $source, array $targetLocales): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];
        if (empty($targetLocales)) return $stats;

        $supported = config('locales.supported', []);
        $defaultLocale = config('locales.default', 'en');
        $sourceLocale  = $source->locale ?: $defaultLocale;
        $groupId = $source->translation_group_id ?: $source->id;

        // Find every locale already covered under this translation group
        // so we don't fan out duplicates on re-publish.
        $existing = News::where('translation_group_id', $groupId)
            ->pluck('locale')
            ->filter()
            ->unique()
            ->all();

        foreach ($targetLocales as $locale) {
            if ($locale === $sourceLocale) { $stats['skipped']++; continue; }
            if (! isset($supported[$locale])) { $stats['skipped']++; continue; }
            if (in_array($locale, $existing, true)) { $stats['skipped']++; continue; }

            try {
                $resp = app(Assistant::class)->run(
                    Assistant::KEY_TRANSLATE,
                    "TARGET LANGUAGE: {$supported[$locale]} ({$locale})\n\n".
                    "---TITLE---\n{$source->title}\n\n".
                    "---EXCERPT---\n{$source->excerpt}\n\n".
                    "---BODY---\n{$source->content}",
                    ['max_tokens' => 6144],
                );

                $text = $resp->text;
                $title   = $this->section($text, 'TITLE')   ?: ('['.$locale.'] '.$source->title);
                $excerpt = $this->section($text, 'EXCERPT') ?: $source->excerpt;
                $body    = $this->section($text, 'BODY')    ?: $text;

                $copy = $source->replicate(['views', 'published_at', 'unpublished_at',
                    'podcast_audio_path', 'podcast_script', 'podcast_generated_at',
                    'auto_translate_locales']);
                $copy->locale = $locale;
                $copy->translation_group_id = $groupId;
                $copy->slug    = Str::slug($source->slug.'-'.$locale);
                $copy->title   = trim($title);
                $copy->excerpt = trim($excerpt);
                $copy->content = trim($body);
                $copy->editorial_status = News::STATUS_PUBLISHED; // mirror parent's published state
                $copy->published_at = $source->published_at ?: now();
                $copy->save();

                $stats['created']++;
            } catch (\Throwable $e) {
                Log::warning('Auto-translate failed', [
                    'source_id' => $source->id,
                    'locale'    => $locale,
                    'error'     => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function section(string $text, string $marker): ?string
    {
        if (preg_match('/---'.$marker.'---\s*\n(.*?)(?=\n---|$)/s', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
