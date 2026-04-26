<?php

namespace App\Services\Ai;

use App\Models\ArticleLintResult;
use App\Models\News;

/**
 * Wraps the article.lint Assistant in a persisting facade. Both the
 * in-editor "Run lint" button and the articles:lint-recent CRON go
 * through this so findings always end up in two places: the per-article
 * row in article_lint_results (for cross-article reporting) and, when
 * called from the editor, the session for the live findings panel.
 */
class ArticleLinter
{
    public function __construct(private readonly Assistant $assistant) {}

    /**
     * Run lint on one article. Returns the parsed findings array, or
     * null when the model output couldn't be parsed (a row is still
     * persisted with an empty findings array + an info-level entry
     * carrying the raw text so editors can debug).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function lint(News $article): ?array
    {
        $body = trim(strip_tags((string) $article->content));
        if ($body === '') return [];

        $resp = $this->assistant->run(
            Assistant::KEY_LINT,
            "Title: {$article->title}\n\nKicker: {$article->kicker}\nDateline: {$article->dateline}\n\nExcerpt: {$article->excerpt}\n\nBody:\n{$body}",
            ['max_tokens' => 1024, 'temperature' => 0.1],
        );

        $raw = trim($resp->text);
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?: $raw;
        $findings = json_decode($raw, true);

        if (! is_array($findings)) {
            $stub = [['severity' => 'info', 'category' => 'parser',
                'message' => 'Could not parse lint output as JSON',
                'fix' => \Illuminate\Support\Str::limit($raw, 800)]];
            $this->persist($article, $stub, $resp->model);
            return null;
        }

        $normalized = collect($findings)->map(fn ($f) => [
            'severity' => in_array($f['severity'] ?? null, ['critical', 'warn', 'info'], true) ? $f['severity'] : 'info',
            'category' => (string) ($f['category'] ?? 'general'),
            'message'  => (string) ($f['message'] ?? ''),
            'fix'      => isset($f['fix']) ? (string) $f['fix'] : null,
        ])->filter(fn ($f) => $f['message'] !== '')->values()->all();

        $this->persist($article, $normalized, $resp->model);

        return $normalized;
    }

    private function persist(News $article, array $findings, ?string $model): void
    {
        $counts = ['critical' => 0, 'warn' => 0, 'info' => 0];
        foreach ($findings as $f) {
            $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
        }

        ArticleLintResult::updateOrCreate(
            ['news_id' => $article->id],
            [
                'findings'       => $findings,
                'critical_count' => $counts['critical'],
                'warn_count'     => $counts['warn'],
                'info_count'     => $counts['info'],
                'model'          => $model,
                'ran_at'         => now(),
            ]
        );
    }
}
