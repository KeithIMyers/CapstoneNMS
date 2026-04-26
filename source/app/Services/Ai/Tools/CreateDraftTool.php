<?php

namespace App\Services\Ai\Tools;

use App\Models\Category;
use App\Models\News;
use Illuminate\Support\Str;

/**
 * Create a new article in draft status. The agent supplies title +
 * excerpt + body; the runner sets editorial_status = draft and stamps
 * the agent's user as author so the activity log captures provenance.
 *
 * Returns "OK | id={n} | url=…" on success or "ERROR: …" on validation
 * failure.
 */
class CreateDraftTool implements Tool
{
    public function key(): string { return 'create_draft'; }

    public function description(): string
    {
        return 'Create a new article as a draft. ALWAYS the safe destination for AI-generated content — never auto-publishes.';
    }

    public function arguments(): array
    {
        return [
            'title'         => 'string — headline',
            'excerpt'       => 'string — one-sentence summary (under 500 chars)',
            'body'          => 'string — article body, plain text or simple HTML',
            'category_slug' => 'string (optional) — slug of an existing category',
        ];
    }

    public function execute(array $args): string
    {
        // Auth gate: refuse the call when there's no authenticated
        // actor rather than silently attributing the draft to user 1
        // (the prior `?: 1` fallback). Ghost-agent users authenticate
        // themselves before invoking the agent runner, so this
        // tightens the contract without breaking the autonomous flow.
        $actor = auth()->user();
        if (! $actor) {
            return 'ERROR: create_draft requires an authenticated session';
        }

        $title = trim((string) ($args['title'] ?? ''));
        $excerpt = trim((string) ($args['excerpt'] ?? ''));
        $body = trim((string) ($args['body'] ?? ''));

        if ($title === '' || $excerpt === '' || $body === '') {
            return 'ERROR: title, excerpt, and body are all required';
        }
        if (mb_strlen($title) > 255) {
            return 'ERROR: title must be 255 characters or fewer';
        }
        if (mb_strlen($excerpt) > 1000) {
            return 'ERROR: excerpt must be 1000 characters or fewer';
        }

        $categoryId = null;
        if (! empty($args['category_slug'])) {
            $cat = Category::where('slug', (string) $args['category_slug'])->first();
            if (! $cat) {
                return "ERROR: no category with slug \"{$args['category_slug']}\"";
            }
            $categoryId = $cat->id;
        } else {
            $categoryId = Category::query()->orderBy('id')->value('id');
            if (! $categoryId) {
                return 'ERROR: no categories exist; create one before drafting';
            }
        }

        $news = News::create([
            'title'            => $title,
            'slug'             => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt'          => $excerpt,
            'content'          => $body,
            'category_id'      => $categoryId,
            'editorial_status' => News::STATUS_DRAFT,
            'user_id'          => $actor->id,
        ]);

        return sprintf(
            "OK | id=%d | edit_url=/admin/news/%d/edit | slug=%s",
            $news->id, $news->id, $news->slug,
        );
    }
}
