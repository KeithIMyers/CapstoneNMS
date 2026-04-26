<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\News;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'read');

        $query = News::query()->with(['category:id,name,slug', 'user:id,name']);

        if ($search = $request->query('q')) {
            $query->where('title', 'like', "%{$search}%");
        }
        if ($category = $request->query('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }
        if ($request->query('status') !== null) {
            $query->where('status', (int) $request->query('status'));
        }

        // Authors only see their own drafts; editors+ see everything.
        $user = $request->user();
        if ($user && $user->role === 'author') {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('status', 1);
            });
        }

        $articles = $query->orderByDesc('id')->paginate(
            perPage: min(100, max(1, (int) $request->query('per_page', 25))),
        );

        return response()->json($articles);
    }

    public function show(Request $request, News $article): JsonResponse
    {
        $this->authorizeAbility($request, 'read');
        $article->load(['category:id,name,slug', 'user:id,name']);

        return response()->json($article);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAbility($request, 'write');

        $validator = Validator::make($request->all(), $this->writeRules(creating: true));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $user = $request->user();
        $isAuthor = $user->role === 'author';

        $article = News::create([
            'title' => $data['title'],
            'slug' => $data['slug'] ?? $this->uniqueSlug($data['title']),
            'excerpt' => $data['excerpt'],
            'content' => sanitize_rich_html($data['content']),
            'image' => isset($data['image_url']) ? $this->safeImagePath($data['image_url']) : null,
            'tags' => isset($data['tags']) ? implode(',', $data['tags']) : null,
            'category_id' => $data['category_id'],
            'user_id' => $user->id,
            // Authors can never publish or feature their own drafts; editors+ may.
            'status' => $isAuthor ? 0 : (int) ($data['status'] ?? 1),
            'is_featured' => $isAuthor ? false : (bool) ($data['is_featured'] ?? false),
            'date' => Carbon::now()->getTimestamp(),
        ]);

        return response()->json($article, 201);
    }

    public function update(Request $request, News $article): JsonResponse
    {
        $this->authorizeAbility($request, 'write');
        $user = $request->user();

        // Authors can only edit their own articles.
        if ($user->role === 'author' && $article->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validator = Validator::make($request->all(), $this->writeRules(creating: false));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (isset($data['content'])) {
            $data['content'] = sanitize_rich_html($data['content']);
        }
        if (array_key_exists('image_url', $data)) {
            $data['image'] = $data['image_url'] ? $this->safeImagePath($data['image_url']) : null;
            unset($data['image_url']);
        }
        if (isset($data['tags'])) {
            $data['tags'] = implode(',', $data['tags']);
        }
        if ($user->role === 'author') {
            // Authors can edit their own drafts but cannot publish or feature.
            unset($data['status'], $data['is_featured']);
        }

        $article->update($data);

        return response()->json($article->fresh());
    }

    public function destroy(Request $request, News $article): JsonResponse
    {
        $this->authorizeAbility($request, 'delete');
        $user = $request->user();

        if ($user->role === 'author' && $article->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $article->delete();

        return response()->json(null, 204);
    }

    /**
     * Validation rules shared by store/update. `image_url`, when provided,
     * must be an absolute http(s) URL. We later normalize the path component
     * to strip directory traversal in safeImagePath().
     */
    private function writeRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes|required';
        $imageRules = ['nullable', 'url:http,https', 'max:500'];

        return [
            'title' => $required.'|string|max:255',
            'excerpt' => $required.'|string|max:1000',
            'content' => $required.'|string',
            'category_id' => ($creating ? 'required' : 'sometimes').'|integer|exists:categories,id',
            'image_url' => $imageRules,
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'status' => 'nullable|in:0,1',
            'is_featured' => 'nullable|boolean',
            'slug' => $creating
                ? 'nullable|string|max:255|unique:news,slug'
                : 'sometimes|string|max:255',
        ];
    }

    /**
     * Defense-in-depth even after the url:http,https rule passes — strip
     * traversal sequences and control bytes from the URL before we persist
     * it. The result is still returned in templates wrapped by Storage::url
     * and inside <img src>, so we keep it conservative.
     */
    private function safeImagePath(string $url): string
    {
        // Strip anything Blade would treat as an attribute breaker.
        $sanitized = preg_replace('/[\x00-\x1F\x7F<>"\']/', '', $url) ?? '';
        // Block obvious traversal sequences anywhere in the URL.
        $sanitized = str_replace(['../', '..\\', '..'], '', $sanitized);

        return mb_substr($sanitized, 0, 500);
    }

    private function authorizeAbility(Request $request, string $ability): void
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = $token ? (array) $token->abilities : [];

        if ($ability === 'read') {
            return;
        }

        // Fail closed: no token (e.g. session-based auth somehow reaches
        // this controller) means no write/delete capability.
        if (! $token) {
            abort(response()->json(['message' => 'Token authentication required.'], 401));
        }

        // Wildcard tokens are deliberately rejected — token creation also
        // refuses them, but enforce here so legacy or hand-issued "*" tokens
        // cannot drive write/delete via this API.
        if (in_array('*', $abilities, true)) {
            abort(response()->json(['message' => 'Wildcard tokens are not permitted.'], 403));
        }

        if (! in_array($ability, $abilities, true) &&
            ! in_array("articles:{$ability}", $abilities, true)) {
            abort(response()->json(['message' => "Token is missing the '{$ability}' ability."], 403));
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $n = 1;
        while (News::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }
        return $slug;
    }
}
