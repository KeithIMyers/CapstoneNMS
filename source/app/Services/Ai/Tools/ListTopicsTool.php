<?php

namespace App\Services\Ai\Tools;

use App\Models\Topic;

/**
 * Enumerate active topic landing pages. Pairs with AssignTopicTool so
 * the topic-classifier agent can see what topics exist before deciding
 * which to attach an article to.
 */
class ListTopicsTool implements Tool
{
    public function key(): string { return 'list_topics'; }

    public function description(): string
    {
        return 'List all active topic landing pages with their id, slug, name, and short description.';
    }

    public function arguments(): array
    {
        return [];
    }

    public function execute(array $args): string
    {
        $topics = Topic::active()->orderBy('name')->get(['id', 'slug', 'name', 'description']);

        if ($topics->isEmpty()) {
            return 'No active topics. Create some in /admin/topics first.';
        }

        return $topics->map(fn ($t) => sprintf(
            '%d | %s | %s | %s',
            $t->id,
            $t->slug,
            $t->name,
            \Illuminate\Support\Str::limit((string) $t->description, 120)
        ))->implode("\n");
    }
}
