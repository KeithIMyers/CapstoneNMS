<?php

namespace App\Http\Controllers;

use App\Models\Topic;

/**
 * Editorially curated topic landing pages. Combines a manual pinned-article
 * list with auto-included articles driven by the topic's auto_rules JSON.
 */
class TopicController extends Controller
{
    public function show(string $slug)
    {
        $topic = Topic::active()->where('slug', $slug)->firstOrFail();

        // Topic::articles() merges manual + auto and dedupes by id. We ask
        // for more than one page's worth so pagination below has something
        // to slice.
        $articles = $topic->articles(60);

        return view('pages.topic', [
            'topic' => $topic,
            'articles' => $articles,
        ]);
    }
}
