<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class TaxonomyController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = Category::active()
            ->orderBy('cat_order')
            ->get(['id', 'name', 'slug', 'parent_id', 'description']);

        return response()->json($categories);
    }

    public function tags(): JsonResponse
    {
        // Cached for 5 minutes — this endpoint used to flat-map every
        // published article's CSV `tags` column on every request, which
        // grows linearly with content. With the real Tag model we just
        // pluck from a small table, but caching keeps it cheap.
        $tags = Cache::remember('api.tags.list', 300, function () {
            return Tag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description']);
        });

        return response()->json($tags);
    }
}
