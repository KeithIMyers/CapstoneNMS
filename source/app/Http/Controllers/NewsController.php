<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Comments;
use App\Models\News;
use App\Models\NewsGallery;
use App\Models\Reaction;
use App\Models\Reports;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class NewsController extends Controller
{
    public function category_news($slug)
    {
        $cat_info = Category::where('slug', $slug)->firstOrFail();

        // Pull the category's full descendant tree so a section landing
        // page (e.g., "Politics") includes articles tagged into its
        // sub-sections ("Congress", "White House", etc.).
        $categoryIds = $cat_info->descendantIds();

        $news = News::published()
            ->whereIn('category_id', $categoryIds)
            ->orderByDesc('published_at')
            ->paginate(10);

        return view('pages.category', compact('cat_info', 'news'));
    }

    public function tags_news($tag)
    {
        // Prefer the new pivot. Fall back to the legacy CSV column for
        // articles created before the tag-split migration runs.
        $tagModel = Tag::where('slug', $tag)->orWhere('name', $tag)->first();
        $tagL = $this->escapeLike($tag);

        $query = News::published()
            ->where(function ($q) use ($tag, $tagL, $tagModel) {
                if ($tagModel) {
                    $q->whereHas('tagsRelation', fn ($qq) => $qq->where('tags.id', $tagModel->id));
                }
                $q->orWhere('tags', 'LIKE', "{$tagL},%")
                    ->orWhere('tags', 'LIKE', "%,{$tagL},%")
                    ->orWhere('tags', 'LIKE', "%,{$tagL}")
                    ->orWhere('tags', $tag);
            })
            ->orderByDesc('published_at');

        $news = $query->paginate(10);

        return view('pages.tags', compact('tag', 'news'));
    }

    /**
     * Escape LIKE-special characters in a user-supplied substring so
     * `%`, `_`, and `\` in the input act as literals rather than
     * wildcards. Parameter binding keeps SQL injection out either
     * way; this protects against pattern-matching surprises (e.g. a
     * search for `100%` matching `100` followed by anything).
     */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    public function search(Request $request)
    {
        $search_term = trim((string) $request->query('s', ''));

        // Faceted filters layered on top of the term match. Each
        // filter is applied as a `where`-on-the-base-paginator
        // (semantic + keyword paths share the same base query), so
        // the candidate pool stays small enough to compute facet
        // counts without an O(N) recount per request.
        $filters = [
            'category' => trim((string) $request->query('cat', '')),
            'tag'      => trim((string) $request->query('tag', '')),
            'author'   => trim((string) $request->query('author', '')),
            'from'     => trim((string) $request->query('from', '')),
            'to'       => trim((string) $request->query('to', '')),
        ];

        // Two-tier search: try semantic (embedding cosine) when an
        // embedding provider is configured AND the index has rows;
        // fall back to LIKE-based keyword search otherwise.
        $news = $search_term !== ''
            ? ($this->semanticSearch($search_term, $filters) ?? $this->keywordSearch($search_term, $filters))
            : News::published()
                ->when($filters['category'] !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $filters['category'])))
                ->when($filters['tag'] !== '', fn ($q) => $q->whereHas('tagsRelation', fn ($t) => $t->where('slug', $filters['tag'])))
                ->when($filters['author'] !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('slug', $filters['author'])))
                ->when($filters['from'] !== '', fn ($q) => $q->where('published_at', '>=', $filters['from']))
                ->when($filters['to'] !== '', fn ($q) => $q->where('published_at', '<=', $filters['to'].' 23:59:59'))
                ->orderByDesc('published_at')
                ->paginate(10)
                ->appends($request->query());

        $facets = $this->searchFacets($search_term, $filters);

        return view('pages.search', compact('search_term', 'news', 'filters', 'facets'));
    }

    /**
     * Per-facet counts for the search sidebar. Computes against the
     * search term + every filter EXCEPT the one whose facet we're
     * counting (so users see "what would happen if I added this
     * filter"). Bounded to top-10 per facet so massive tag spaces
     * don't blow up the rendering.
     *
     * @param  array<string,string> $filters
     * @return array{categories: array, tags: array, authors: array}
     */
    private function searchFacets(string $term, array $filters): array
    {
        $base = $this->facetBaseQuery($term);

        $applyExcept = function ($q, string $exclude) use ($filters) {
            if ($exclude !== 'category' && $filters['category'] !== '') {
                $q->whereHas('category', fn ($c) => $c->where('slug', $filters['category']));
            }
            if ($exclude !== 'tag' && $filters['tag'] !== '') {
                $q->whereHas('tagsRelation', fn ($t) => $t->where('slug', $filters['tag']));
            }
            if ($exclude !== 'author' && $filters['author'] !== '') {
                $q->whereHas('user', fn ($u) => $u->where('slug', $filters['author']));
            }
            if ($filters['from'] !== '') $q->where('published_at', '>=', $filters['from']);
            if ($filters['to'] !== '')   $q->where('published_at', '<=', $filters['to'].' 23:59:59');
        };

        $catQ    = (clone $base); $applyExcept($catQ, 'category');
        $tagQ    = (clone $base); $applyExcept($tagQ, 'tag');
        $authorQ = (clone $base); $applyExcept($authorQ, 'author');

        $categories = $catQ->select('category_id')
            ->groupBy('category_id')
            ->selectRaw('category_id, COUNT(*) as c')
            ->orderByDesc('c')
            ->limit(10)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->category_id => $r->c]);

        $catNames = \App\Models\Category::whereIn('id', $categories->keys())->get(['id', 'name', 'slug'])->keyBy('id');
        $categories = $categories->map(fn ($count, $id) => [
            'name' => $catNames[$id]->name ?? '—', 'slug' => $catNames[$id]->slug ?? null, 'count' => $count,
        ])->filter(fn ($r) => $r['slug'])->values();

        $termL = $this->escapeLike($term);
        $tagRows = \DB::table('news_tag')
            ->join('news', 'news.id', '=', 'news_tag.news_id')
            ->where('news.editorial_status', \App\Models\News::STATUS_PUBLISHED)
            ->when($term !== '', fn ($q) => $q->where('news.title', 'like', "%{$termL}%"))
            ->select('news_tag.tag_id', \DB::raw('COUNT(*) as c'))
            ->groupBy('news_tag.tag_id')
            ->orderByDesc('c')
            ->limit(10)
            ->get();
        $tagNames = \App\Models\Tag::whereIn('id', $tagRows->pluck('tag_id'))->get(['id', 'name', 'slug'])->keyBy('id');
        $tags = $tagRows->map(fn ($r) => [
            'name' => $tagNames[$r->tag_id]->name ?? '—',
            'slug' => $tagNames[$r->tag_id]->slug ?? null,
            'count' => $r->c,
        ])->filter(fn ($r) => $r['slug'])->values();

        $authors = $authorQ->select('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as c')
            ->orderByDesc('c')
            ->limit(10)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->user_id => $r->c]);
        $authorRows = \App\Models\User::whereIn('id', $authors->keys())->get(['id', 'name', 'slug'])->keyBy('id');
        $authors = $authors->map(fn ($count, $id) => [
            'name' => $authorRows[$id]->name ?? '—', 'slug' => $authorRows[$id]->slug ?? null, 'count' => $count,
        ])->filter(fn ($r) => $r['slug'])->values();

        return ['categories' => $categories, 'tags' => $tags, 'authors' => $authors];
    }

    private function facetBaseQuery(string $term): \Illuminate\Database\Eloquent\Builder
    {
        $termL = $this->escapeLike($term);
        return News::published()
            ->when($term !== '', fn ($q) => $q->where('title', 'like', "%{$termL}%"));
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|null
     *   Null when embeddings aren't configured / index is empty —
     *   caller falls back to keyword search.
     */
    private function semanticSearch(string $term, array $filters = []): ?\Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $provider = \App\Models\AiProvider::defaultEmbeddingProvider();
        if (! $provider) return null;

        try {
            // Cache the query → vector mapping for an hour so a popular
            // search doesn't embed the same string repeatedly. The
            // cosine pass against the index runs each time (cheap).
            $queryVec = \Illuminate\Support\Facades\Cache::store('file')->remember(
                'search.embed.'.hash('sha256', mb_strtolower($term)),
                3600,
                fn () => app(\App\Services\Ai\EmbeddingClient::class)
                    ->embed($term, $provider, purpose: 'embedding.search')
                    ->vector,
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Semantic search embed failed', ['error' => $e->getMessage()]);
            return null;
        }
        if (empty($queryVec)) return null;

        $rows = \App\Models\ArticleEmbedding::query()
            ->where('model', $provider->embedding_model)
            ->get(['news_id', 'vector']);
        if ($rows->isEmpty()) return null;

        $queryNorm = $this->vecNorm($queryVec);
        if ($queryNorm <= 0.0) return null;

        $scored = [];
        foreach ($rows as $row) {
            $vec = $row->vector;
            if (count($vec) !== count($queryVec)) continue;
            $scored[] = ['news_id' => $row->news_id, 'score' => $this->vecCosine($queryVec, $queryNorm, $vec)];
        }
        if (empty($scored)) return null;

        // Drop near-zero scores so totally-unrelated articles don't
        // pad the results page.
        $scored = array_filter($scored, fn ($r) => $r['score'] > 0.15);
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $perPage = 10;
        $page = (int) (request()->query('page', 1));
        $total = count($scored);
        $offset = max(0, ($page - 1) * $perPage);
        $pageIds = array_column(array_slice($scored, $offset, $perPage), 'news_id');

        // Fetch the page's worth of articles, preserving similarity
        // order, and apply the active facet filters as `where`-on-id
        // so the page is exactly what the filter combination matches.
        $articles = News::published()
            ->whereIn('id', $pageIds)
            ->when(! empty($filters['category']), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $filters['category'])))
            ->when(! empty($filters['tag']), fn ($q) => $q->whereHas('tagsRelation', fn ($t) => $t->where('slug', $filters['tag'])))
            ->when(! empty($filters['author']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('slug', $filters['author'])))
            ->when(! empty($filters['from']), fn ($q) => $q->where('published_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->where('published_at', '<=', $filters['to'].' 23:59:59'))
            ->with(['category', 'user'])
            ->get()
            ->keyBy('id');
        $ordered = collect($pageIds)->map(fn ($id) => $articles[$id] ?? null)->filter()->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            items: $ordered,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path'     => request()->url(),
                'pageName' => 'page',
                'query'    => ['s' => $term],
            ],
        );
    }

    private function keywordSearch(string $term, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $termL = $this->escapeLike($term);
        return News::published()
            ->where(function ($qq) use ($termL) {
                $qq->where('title', 'LIKE', "%{$termL}%")
                    ->orWhere('excerpt', 'LIKE', "%{$termL}%")
                    ->orWhere('content', 'LIKE', "%{$termL}%");
            })
            ->when(! empty($filters['category']), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $filters['category'])))
            ->when(! empty($filters['tag']), fn ($q) => $q->whereHas('tagsRelation', fn ($t) => $t->where('slug', $filters['tag'])))
            ->when(! empty($filters['author']), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('slug', $filters['author'])))
            ->when(! empty($filters['from']), fn ($q) => $q->where('published_at', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->where('published_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('updated_at')
            ->paginate(10)
            ->appends(array_filter(array_merge(['s' => $term], $filters)));
    }

    /**
     * Search-as-you-type endpoint. Returns up to 5 article-title +
     * 5 tag + 3 category matches as JSON for the header search box.
     * Cached 60 s per (lowercased) query term to keep refresh-spam
     * off the DB.
     *
     *   GET /search/autocomplete?q=…
     */
    public function autocomplete(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['articles' => [], 'tags' => [], 'categories' => []]);
        }

        $cacheKey = 'search:auto:'.hash('sha256', mb_strtolower($term));
        $payload = \Illuminate\Support\Facades\Cache::store('file')->remember(
            $cacheKey,
            60,
            function () use ($term) {
                $termL = $this->escapeLike($term);
                $articles = News::published()
                    ->where('title', 'LIKE', "%{$termL}%")
                    ->orderByDesc('published_at')
                    ->limit(5)
                    ->get(['id', 'title', 'slug'])
                    ->map(fn ($a) => ['title' => (string) $a->title, 'slug' => (string) $a->slug])
                    ->values();

                $tags = \App\Models\Tag::where('name', 'LIKE', "%{$termL}%")
                    ->orderBy('name')
                    ->limit(5)
                    ->get(['id', 'name', 'slug'])
                    ->map(fn ($t) => ['name' => (string) $t->name, 'slug' => (string) $t->slug])
                    ->values();

                $categories = \App\Models\Category::where('name', 'LIKE', "%{$termL}%")
                    ->orderBy('name')
                    ->limit(3)
                    ->get(['id', 'name', 'slug'])
                    ->map(fn ($c) => ['name' => (string) $c->name, 'slug' => (string) $c->slug])
                    ->values();

                return ['articles' => $articles, 'tags' => $tags, 'categories' => $categories];
            },
        );

        return response()->json($payload);
    }

    public function details($slug, $locale = null)
    {
        // When called via the /{locale}/news/{slug} route, Laravel passes
        // the locale as the first positional arg. Swap the parameters so
        // `$slug` always holds the slug regardless of route.
        if ($locale !== null) {
            [$slug, $locale] = [$locale, $slug];
        }

        // Preview tokens: editors can share an unpublished article with
        // a recipient by appending ?preview=<sha256(article->slug + APP_KEY)>
        // to the URL. Validated in canBeViewed() below.
        $isPreview = $this->verifyPreviewToken(request()->query('preview'), $slug);

        // Localized URLs scope the slug lookup to that locale so identical
        // slugs across locales don't collide. The canonical English URL
        // (no prefix) continues to look up without a locale filter.
        $defaultLocale = config('locales.default', 'en');
        $targetLocale = $locale ?: $defaultLocale;

        // Two URL shapes are supported:
        //   1. Pure slug (e.g. /news/congress-passes-energy-bill)
        //   2. Legacy slug-with-trailing-id (e.g. /news/congress-passes-energy-bill-42)
        // We try (1) first; on miss, fall back to (2) for old links.
        $with = ['user', 'category', 'reactions', 'tagsRelation', 'authors', 'revisions.editor', 'sources', 'topics', 'series'];

        $news = News::with($with)
            ->where('slug', $slug)
            ->where(function ($q) use ($targetLocale, $defaultLocale, $locale) {
                // When visiting the canonical path we accept any row whose
                // locale is the default; when visiting a localized path we
                // require that specific locale.
                if ($locale === null) {
                    $q->where('locale', $defaultLocale)->orWhereNull('locale');
                } else {
                    $q->where('locale', $targetLocale);
                }
            })
            ->first();

        if (! $news) {
            $tail = last(explode('-', $slug));
            if (is_numeric($tail)) {
                $news = News::with($with)->find((int) $tail);
            }
        }

        if (! $news) {
            abort(404);
        }

        if ($news->slug !== $slug) {
            $redirectRoute = $locale ? 'news.details.localized' : 'news.details';
            $redirectParams = $locale
                ? ['locale' => $locale, 'slug' => $news->slug]
                : ['slug' => $news->slug];
            return redirect()->route($redirectRoute, $redirectParams, 301);
        }

        // Block draft / scheduled / archived from public visitors. Authors
        // and editors can preview their own drafts when authenticated;
        // anyone with a valid ?preview= token can read regardless of status.
        if (! $isPreview && ! $this->canBeViewed($news)) {
            abort(404);
        }

        $this->trackView($news);

        $gallery_images = NewsGallery::where('news_id', $news->id)->orderBy('id', 'desc')->get();

        $previous_news = News::published()
            ->where('id', '<', $news->id)
            ->orderBy('id', 'desc')
            ->first();

        $next_news = News::published()
            ->where('id', '>', $news->id)
            ->orderBy('id', 'asc')
            ->first();

        $comment_list = Comments::with(['user'])
            ->where('post_id', $news->id)
            ->approved()
            ->orderBy('id', 'DESC')
            ->get();

        $reactionCounts = $news->getReactionCounts();
        $userReaction = $news->getUserReaction();
        $reactionTypes = Reaction::getTypes();   // array of type keys: ['like','love',...]

        $related = $this->relatedArticles($news);

        // Behavior-aware recommendations beneath the article. Falls
        // back to popularity-weighted picks for anonymous readers,
        // so this surface always renders something useful.
        $reco = app(\App\Services\Recommendations\RecommendationService::class)
            ->relatedTo($news, Auth::user(), 4);

        // Gift-article redemption: a subscriber-minted `?gift={token}`
        // grants this browser full access to this premium article.
        // Redemption marks the gift used and writes a session flag, so
        // canRead() picks it up on this request and every refresh.
        if (request()->filled('gift') && $news->is_premium) {
            $gift = app(\App\Services\Paywall\GiftService::class);
            if ($gift->redeem($news, (string) request()->query('gift'), request())) {
                Session::flash('flash_message', 'A friend gifted you this article. You\'re all set.');
            }
        }

        // Paywall gate — when can_read_full is false, the view renders
        // a preview body + Subscribe CTA in place of the full content.
        // We tick the meter only after the full-body render is decided
        // and only for premium articles, so refreshes of an already-
        // counted piece don't double-count.
        // Reading history: ticked only for authenticated readers.
        // Cheap upsert + counter bump per article view; powers the
        // "continue reading" surface on /profile/history.
        if ($u = Auth::user()) {
            \App\Models\ReadingHistory::record($u->id, $news->id);
        }

        $paywall = app(\App\Services\Paywall\Paywall::class);
        $can_read_full = $paywall->canRead($news);
        if ($can_read_full && $news->is_premium) {
            $paywall->meterTick($news);
        }
        $preview_body = $can_read_full ? null : $paywall->previewBody($news->content);

        return view('pages.details', compact(
            'news', 'gallery_images', 'previous_news', 'next_news',
            'comment_list', 'reactionCounts', 'userReaction', 'reactionTypes',
            'related', 'reco', 'can_read_full', 'preview_body',
        ));
    }

    /**
     * Score-based related-article picker. Articles get points for sharing
     * the current article's tags (heaviest), being in the same category,
     * and being recent. Returns up to four results, falling back to plain
     * "same category" if the tag-overlap pool is empty.
     */
    private function relatedArticles(News $news): \Illuminate\Support\Collection
    {
        // Cache per-article-id for 1 hour. The related list rarely changes
        // within that window — and the embedding cosine pass over the
        // full index is the most expensive operation on this view.
        return \Illuminate\Support\Facades\Cache::store('file')->remember(
            "news.related.{$news->id}",
            3600,
            fn () => $this->semanticRelated($news) ?? $this->keywordRelated($news),
        );
    }

    /**
     * Embedding-driven similarity, falling back to null when the index
     * is empty / no embedding provider is configured. Computed in PHP
     * over the full article_embeddings table — fine up to ~10k rows.
     */
    private function semanticRelated(News $news): ?\Illuminate\Support\Collection
    {
        $self = \App\Models\ArticleEmbedding::where('news_id', $news->id)->first();
        if (! $self) return null;
        $queryVec = $self->vector;
        if (empty($queryVec)) return null;

        $queryNorm = $this->vecNorm($queryVec);
        if ($queryNorm <= 0.0) return null;

        $rows = \App\Models\ArticleEmbedding::query()
            ->where('model', $self->model)
            ->where('news_id', '!=', $news->id)
            ->get(['news_id', 'vector']);

        if ($rows->isEmpty()) return null;

        $scored = [];
        foreach ($rows as $row) {
            $vec = $row->vector;
            if (count($vec) !== count($queryVec)) continue;
            $scored[] = ['news_id' => $row->news_id, 'score' => $this->vecCosine($queryVec, $queryNorm, $vec)];
        }
        if (empty($scored)) return null;

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $topIds = collect($scored)->take(20)->pluck('news_id')->all();

        $articles = News::published()
            ->whereIn('id', $topIds)
            ->with(['category'])
            ->get()
            ->keyBy('id');

        // Preserve similarity order; cap to 4 like the legacy version.
        $out = collect();
        foreach ($topIds as $id) {
            if ($out->count() >= 4) break;
            if (isset($articles[$id])) $out->push($articles[$id]);
        }
        return $out->isEmpty() ? null : $out->values();
    }

    /**
     * Original tag-overlap + same-category scoring. Used when no
     * embeddings exist for the article (fresh import, embeddings
     * provider not configured, or index hasn't caught up yet).
     */
    private function keywordRelated(News $news): \Illuminate\Support\Collection
    {
        $tagIds = $news->tagsRelation->pluck('id')->all();

        $candidates = News::published()
            ->where('id', '!=', $news->id)
            ->when(! empty($tagIds), function ($q) use ($tagIds) {
                $q->whereHas('tagsRelation', fn ($qq) => $qq->whereIn('tags.id', $tagIds));
            })
            ->orWhere(function ($q) use ($news) {
                $q->where('category_id', $news->category_id)
                  ->where('id', '!=', $news->id)
                  ->where('editorial_status', News::STATUS_PUBLISHED);
            })
            ->with(['category', 'tagsRelation:id'])
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return $candidates->map(function (News $c) use ($news, $tagIds) {
            $sharedTags = ! empty($tagIds)
                ? $c->tagsRelation->pluck('id')->intersect($tagIds)->count()
                : 0;
            $sameCategory = (int) ($c->category_id === $news->category_id);
            $recencyDays = $c->effectivePublishedAt()
                ? max(0, $c->effectivePublishedAt()->diffInDays(now()))
                : 9999;
            $score = ($sharedTags * 3) + ($sameCategory * 2) - min($recencyDays / 30, 1.5);
            $c->setAttribute('_score', $score);
            return $c;
        })->sortByDesc('_score')->take(4)->values();
    }

    private function vecCosine(array $a, float $aNorm, array $b): float
    {
        $dot = 0.0;
        $bSq = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $bSq += $b[$i] * $b[$i];
        }
        $bNorm = sqrt($bSq);
        return $bNorm > 0.0 ? $dot / ($aNorm * $bNorm) : 0.0;
    }

    private function vecNorm(array $v): float
    {
        $sq = 0.0;
        foreach ($v as $x) $sq += $x * $x;
        return sqrt($sq);
    }

    /**
     * Stable per-article preview token. Editors generate one in the
     * Filament edit page and share the resulting URL with sources /
     * lawyers / outside readers. The token is a HMAC of the slug with
     * the APP_KEY so it's stable across requests but unforgeable, and
     * rotates automatically when the slug changes.
     */
    public static function previewTokenFor(News $news): string
    {
        return hash_hmac('sha256', 'preview:'.$news->slug, (string) config('app.key'));
    }

    private function verifyPreviewToken(?string $token, string $slug): bool
    {
        if (! $token || strlen($token) !== 64) return false;
        $expected = hash_hmac('sha256', 'preview:'.$slug, (string) config('app.key'));
        return hash_equals($expected, $token);
    }

    private function canBeViewed(News $news): bool
    {
        if ($news->editorial_status === News::STATUS_PUBLISHED) {
            $publishedReached = is_null($news->published_at) || $news->published_at->isPast();
            $unpublished = $news->unpublished_at && $news->unpublished_at->isPast();
            return $publishedReached && ! $unpublished;
        }

        // Authors / editors / admins can preview their own draft / in-review
        // items by hitting the URL directly.
        if (auth()->check() && auth()->user()->isAuthor()) {
            if (auth()->user()->isEditor() || $news->user_id === auth()->id()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Increment the per-article view counter, but only once per session
     * and only if the request doesn't look like a crawler. Bots routinely
     * inflate "trending" otherwise.
     */
    private function trackView(News $news): void
    {
        $sessionKey = "viewed_news_{$news->id}";
        if (session()->has($sessionKey)) {
            return;
        }

        $userAgent = strtolower((string) request()->header('User-Agent'));
        $isBot = $userAgent === '' || preg_match(
            '/bot|crawl|spider|slurp|preview|fetcher|scrape|wget|curl|python-requests|httpclient/i',
            $userAgent
        );

        if ($isBot) {
            return;
        }

        // We use the DB query builder rather than $news->increment() so
        // we don't kick the model's saving lifecycle (which would
        // recompute reading_time_minutes etc. on every view).
        \DB::table('news')->where('id', $news->id)->increment('views');
        session()->put($sessionKey, true);

        // Country aggregate. Fail-soft when GeoIP isn't configured —
        // countryFor() returns null and we skip the bump. The session
        // gate above already dedupes per visitor per article, so the
        // counter is reload-safe.
        try {
            $country = app(\App\Services\GeoIp\GeoIp::class)->countryFor(request()->ip());
            if ($country) {
                \DB::statement(
                    'INSERT INTO article_country_views (article_id, country_code, day, views, updated_at)
                     VALUES (?, ?, CURDATE(), 1, NOW())
                     ON DUPLICATE KEY UPDATE views = views + 1, updated_at = NOW()',
                    [$news->id, $country],
                );
            }
        } catch (\Throwable $e) {
            // Never fail the view request because of geo accounting.
        }
    }

    public function report_news(Request $request)
    {
        if (! Auth::check()) {
            Session::flash('error_flash_message', trans('words.login_req'));
            return redirect()->back();
        }

        $validator = Validator::make($request->all(), [
            'report_text' => 'required|string|max:2000',
            'post_id' => 'required|integer|exists:news,id',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator->messages());
        }

        $report = new Reports;
        $report->user_id = Auth::user()->id;
        $report->post_id = (int) $request->input('post_id');
        $report->message = (string) $request->input('report_text');
        $report->date = now()->getTimestamp();
        $report->save();

        Session::flash('flash_message', trans('words.reports_success'));
        return redirect()->back();
    }

    public function comment_send(Request $request)
    {
        // Guest commenting is gated by two settings:
        //   comments_allow_guests  → site-wide opt-in
        //   captcha_provider       → must be configured for guest mode
        // When BOTH are present, anonymous visitors can submit a comment
        // by passing the CAPTCHA challenge. Otherwise we fall back to
        // the legacy "must be signed in" behavior.
        $guestsAllowed = $this->guestCommentsAllowed();

        if (! Auth::check() && ! $guestsAllowed) {
            Session::flash('error_flash_message', trans('words.login_req'));
            return redirect()->back();
        }

        $rules = [
            'comment_text' => 'required|string|max:4000',
            'post_id'      => 'required|integer|exists:news,id',
        ];
        if (! Auth::check()) {
            $rules['guest_name']  = 'required|string|min:2|max:120';
            $rules['guest_email'] = 'required|email|max:200';
        }
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator->messages())->withInput();
        }

        // Guests must pass CAPTCHA. Authenticated users skip — they're
        // already accountable to the moderator.
        if (! Auth::check()) {
            $captcha = app(\App\Services\Captcha\CaptchaVerifier::class);
            if (! $captcha->isEnabled()) {
                Session::flash('error_flash_message', 'Guest comments aren\'t available right now.');
                return redirect()->back()->withInput();
            }
            if (! $captcha->verify($request)) {
                Session::flash('error_flash_message', 'CAPTCHA verification failed. Please try again.');
                return redirect()->back()->withInput();
            }
        }

        $comment = new Comments;
        $comment->user_id    = Auth::check() ? Auth::user()->id : null;
        $comment->guest_name = Auth::check() ? null : (string) $request->input('guest_name');
        $comment->guest_email= Auth::check() ? null : (string) $request->input('guest_email');
        $comment->ip_address = (string) $request->ip();
        $comment->post_id    = (int) $request->input('post_id');
        $comment->content    = (string) $request->input('comment_text');
        // Default into the moderation queue; the AI moderator below may
        // immediately escalate to approved or spam based on its verdict.
        $comment->status     = Comments::STATUS_PENDING;
        $comment->save();

        // Synchronous classification keeps the user-visible flow simple
        // (one redirect, immediate flash). The moderator is fail-soft —
        // if the provider is down, the comment stays pending exactly as
        // it would without AI moderation enabled.
        try {
            app(\App\Services\Ai\CommentModerator::class)->classify($comment);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Comment moderation threw', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);
        }

        Session::flash('flash_message', trans('words.comment_submitted_success'));
        return redirect()->back();
    }

    private function guestCommentsAllowed(): bool
    {
        $val = function_exists('getcong') ? getcong('comments_allow_guests') : null;
        if (! in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true)) {
            return false;
        }
        return app(\App\Services\Captcha\CaptchaVerifier::class)->isEnabled();
    }
}
