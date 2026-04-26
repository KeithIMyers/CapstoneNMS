<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorController extends Controller
{
    /**
     * Public author landing page at /author/{slug}. Falls back to ID
     * lookup so old links keep working.
     */
    public function show(string $slug)
    {
        $author = User::where('slug', $slug)
            ->orWhere('id', is_numeric($slug) ? (int) $slug : null)
            ->firstOrFail();

        // Include both primary-byline articles and co-authored ones.
        $articles = News::published()
            ->where(function (Builder $q) use ($author) {
                $q->where('user_id', $author->id)
                  ->orWhereHas('authors', fn ($qq) => $qq->where('users.id', $author->id));
            })
            ->orderByDesc('published_at')
            ->paginate(12);

        return view('pages.author', compact('author', 'articles'));
    }
}
