<?php

namespace App\Http\Controllers;

use App\Models\News;
use App\Models\ReadingHistory;
use App\Models\ReadingList;
use App\Models\ReadingListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Reader-facing reading lists + history surfaces.
 *
 *   GET  /profile/lists              all of my lists, with item counts
 *   GET  /profile/lists/{slug}       one list, articles ordered by added
 *   POST /profile/lists              create a new list
 *   POST /profile/lists/{slug}/add   add an article to this list (AJAX)
 *   POST /profile/lists/{slug}/remove remove (AJAX)
 *   GET  /profile/history            recent reads + "continue reading"
 *   POST /profile/history/clear      wipe my reading history
 *
 * The default "Saved" list is auto-created on first interaction so a
 * fresh user always has somewhere to drop a save.
 */
class ReadingListController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            Session::flash('error_flash_message', 'Sign in to manage reading lists.');
            return redirect()->route('user_login');
        }

        ReadingList::defaultFor($user); // ensure the default exists

        $lists = ReadingList::where('user_id', $user->id)
            ->withCount('items')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('pages.user.reading_lists', compact('lists'));
    }

    public function show(Request $request, string $slug)
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $list = ReadingList::where('user_id', $user->id)->where('slug', $slug)->firstOrFail();
        $items = $list->items()->with('article:id,title,slug,excerpt,image,published_at,user_id')->paginate(20);

        return view('pages.user.reading_list_show', compact('list', 'items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $request->validate(['name' => 'required|string|max:120']);

        $list = ReadingList::create([
            'user_id'    => $user->id,
            'name'       => trim((string) $request->input('name')),
            'is_default' => false,
        ]);

        Session::flash('flash_message', 'List created.');
        return redirect()->route('reading_lists.show', ['slug' => $list->slug]);
    }

    public function add(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (! $user) return response()->json(['ok' => false, 'reason' => 'auth_required'], 401);

        $request->validate(['news_id' => 'required|integer']);

        $list = $slug === 'saved'
            ? ReadingList::defaultFor($user)
            : ReadingList::where('user_id', $user->id)->where('slug', $slug)->first();
        if (! $list) return response()->json(['ok' => false, 'reason' => 'list_not_found'], 404);

        $newsId = (int) $request->input('news_id');
        if (! News::whereKey($newsId)->exists()) {
            return response()->json(['ok' => false, 'reason' => 'article_not_found'], 404);
        }

        ReadingListItem::firstOrCreate(
            ['reading_list_id' => $list->id, 'news_id' => $newsId],
            ['added_at' => now(), 'note' => $request->input('note')],
        );

        return response()->json(['ok' => true, 'list' => $list->slug]);
    }

    public function remove(Request $request, string $slug): JsonResponse
    {
        $user = $request->user();
        if (! $user) return response()->json(['ok' => false, 'reason' => 'auth_required'], 401);

        $request->validate(['news_id' => 'required|integer']);

        $list = ReadingList::where('user_id', $user->id)->where('slug', $slug)->first();
        if (! $list) return response()->json(['ok' => false, 'reason' => 'list_not_found'], 404);

        ReadingListItem::where('reading_list_id', $list->id)
            ->where('news_id', (int) $request->input('news_id'))
            ->delete();

        return response()->json(['ok' => true]);
    }

    public function history(Request $request)
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $recent = ReadingHistory::where('user_id', $user->id)
            ->with('article:id,title,slug,excerpt,image,published_at')
            ->orderByDesc('last_read_at')
            ->limit(50)
            ->get();

        // "Continue reading" surface: rows where scroll_depth_pct < 80
        // — recent partial reads that the visitor likely wants to
        // resume. Same dataset, filtered.
        $continueReading = $recent->filter(fn ($h) => $h->scroll_depth_pct !== null && $h->scroll_depth_pct < 80)
            ->take(6)
            ->values();

        return view('pages.user.reading_history', compact('recent', 'continueReading'));
    }

    public function clearHistory(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        ReadingHistory::where('user_id', $user->id)->delete();
        Session::flash('flash_message', 'Reading history cleared.');
        return redirect()->route('reading_history');
    }
}
