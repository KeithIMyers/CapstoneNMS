<?php

namespace App\Services\Ai\Tools;

use App\Models\News;
use App\Models\Topic;

/**
 * Pin an article to a topic via the topic_news pivot. Idempotent: if
 * the article is already pinned to the topic this is a no-op.
 *
 * Returns "OK | …" on success or "ERROR: …" if either record can't be
 * located.
 */
class AssignTopicTool implements Tool
{
    public function key(): string { return 'assign_topic'; }

    public function description(): string
    {
        return 'Pin an article to a topic. Both records must exist; idempotent on duplicate calls.';
    }

    public function arguments(): array
    {
        return [
            'article_id' => 'integer — article id from search_articles',
            'topic_id'   => 'integer — topic id from list_topics',
            'sort'       => 'integer (optional) — pin order, lower first (default 0)',
        ];
    }

    public function execute(array $args): string
    {
        $articleId = (int) ($args['article_id'] ?? 0);
        $topicId   = (int) ($args['topic_id'] ?? 0);
        $sort      = (int) ($args['sort'] ?? 0);

        if ($articleId <= 0 || $topicId <= 0) {
            return 'ERROR: article_id and topic_id are both required positive integers';
        }

        $article = News::find($articleId);
        $topic   = Topic::find($topicId);
        if (! $article) return "ERROR: no article with id {$articleId}";
        if (! $topic)   return "ERROR: no topic with id {$topicId}";

        $topic->manualArticles()->syncWithoutDetaching([$articleId => ['sort' => $sort]]);

        return sprintf('OK | article_id=%d pinned to topic_id=%d (%s)',
            $articleId, $topicId, $topic->slug);
    }
}
