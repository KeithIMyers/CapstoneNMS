<?php

namespace App\Http\Controllers;

use App\Models\LiveBlog;
use App\Models\LiveBlogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class LiveBlogController extends Controller
{
    public function show(string $slug)
    {
        $live = LiveBlog::publiclyVisible()
            ->where('slug', $slug)
            ->with(['creator', 'polls'])
            ->firstOrFail();

        $pinned = $live->entries()->where('is_pinned', true)->get();
        $entries = $live->entries()->where('is_pinned', false)->paginate(20);
        $polls   = $live->polls()->open()->get();

        return view('pages.live', compact('live', 'pinned', 'entries', 'polls'));
    }

    /**
     * JSON feed of entries newer than `?since=<unix-seconds>`. Used
     * by the live-blog page's foreground poll so readers see new
     * entries land without a full page reload. Always returns the
     * 30 most-recent matching entries to bound payload size.
     */
    public function entriesJson(Request $request, string $slug): JsonResponse
    {
        $live = LiveBlog::publiclyVisible()
            ->where('slug', $slug)
            ->firstOrFail(['id', 'slug', 'status']);

        $since = (int) $request->query('since', 0);

        // Cache the latest 30 entries for this live blog at a short
        // TTL — readers polling every few seconds during a breaking
        // event would otherwise pin a PHP-FPM worker per request even
        // though the underlying data only changes when an editor posts
        // a new entry. The `since` filter is applied AFTER the cache
        // hit so the cache key isn't fragmented by every visitor's
        // poll cursor. 4 s buys ~95 % cache hit rate on a heavy event
        // while still letting a new entry land within the next poll.
        $payload = Cache::remember(
            "live_blog:entries:{$live->id}",
            4,
            function () use ($live) {
                $rows = LiveBlogEntry::query()
                    ->where('live_blog_id', $live->id)
                    ->where('is_pinned', false)
                    ->orderByDesc('posted_at')
                    ->limit(30)
                    ->get(['id', 'headline', 'body', 'kicker', 'posted_at']);

                return [
                    'live_status' => $live->status,
                    'entries' => $rows->map(fn ($e) => [
                        'id'        => $e->id,
                        'headline'  => (string) $e->headline,
                        'kicker'    => (string) ($e->kicker ?? ''),
                        'body'      => (string) $e->body,
                        'posted_at' => optional($e->posted_at)->toIso8601ZuluString(),
                        'posted_ts' => optional($e->posted_at)->timestamp,
                        'pretty_at' => optional($e->posted_at)->format('M j · g:i A'),
                    ])->values()->all(),
                ];
            },
        );

        $entries = array_values(array_filter(
            $payload['entries'],
            fn ($e) => ($e['posted_ts'] ?? 0) > $since,
        ));

        return response()->json([
            'live_status' => $payload['live_status'],
            'now'         => Carbon::now()->timestamp,
            'entries'     => $entries,
        ], 200, [
            // Browser/CDN can also hold the response for a beat. The
            // `since` query param keeps poll responses bucketed per
            // visitor, so a CDN can cache per-(slug, since) safely.
            'Cache-Control' => 'public, max-age=3, s-maxage=3',
        ]);
    }
}
