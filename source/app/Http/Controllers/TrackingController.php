<?php

namespace App\Http\Controllers;

use App\Models\AdSlot;
use App\Models\News;
use App\Models\NewsHeadline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tiny analytics endpoints for the public site. Currently just scroll
 * depth. Per-session de-duplication prevents one user reloading from
 * skewing the average.
 */
class TrackingController extends Controller
{
    public function scroll(Request $request, News $article): JsonResponse
    {
        $depth = (int) $request->input('depth_pct', 0);
        if ($depth < 1 || $depth > 100) {
            return response()->json(['ok' => false], 422);
        }

        // Bot filter — same UA pattern as the view counter.
        $ua = strtolower((string) $request->header('User-Agent'));
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|fetcher|scrape|wget|curl|python-requests|httpclient/i', $ua)) {
            return response()->json(['ok' => true, 'note' => 'skipped']);
        }

        // One sample per session per article.
        $sessionKey = "scroll_logged_{$article->id}";
        if (session()->has($sessionKey)) {
            return response()->json(['ok' => true, 'note' => 'duplicate']);
        }
        session()->put($sessionKey, true);

        DB::table('news')->where('id', $article->id)->increment('scroll_samples', 1, [
            'scroll_depth_total' => DB::raw('scroll_depth_total + '.$depth),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Record a click on an A/B headline variant. Bot-filtered; one click per
     * session per variant so a frantic reload doesn't distort CTR. The
     * impression counter increments server-side when the variant is served
     * (see News::bucketedHeadline callers).
     */
    public function headlineClick(Request $request, NewsHeadline $headline): JsonResponse
    {
        $ua = strtolower((string) $request->header('User-Agent'));
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|fetcher|scrape|wget|curl|python-requests|httpclient/i', $ua)) {
            return response()->json(['ok' => true, 'note' => 'skipped']);
        }

        $sessionKey = "headline_click_{$headline->id}";
        if (session()->has($sessionKey)) {
            return response()->json(['ok' => true, 'note' => 'duplicate']);
        }
        session()->put($sessionKey, true);

        DB::table('news_headlines')->where('id', $headline->id)->increment('clicks');

        return response()->json(['ok' => true]);
    }

    /**
     * Serve a creative for the given placement. Called by the site JS
     * instead of rendering the creative server-side so the response-cache
     * middleware can still cache the surrounding HTML without freezing one
     * ad for every visitor. The slot's impression counter is incremented
     * once per session per slot server-side here.
     */
    public function adServe(Request $request, string $placement): JsonResponse
    {
        $slot = AdSlot::pick($placement);
        if (! $slot) {
            return response()->json(['ok' => true, 'empty' => true]);
        }
        $html = $slot->renderHtml();
        if (trim($html) === '') {
            return response()->json(['ok' => true, 'empty' => true]);
        }

        return response()->json([
            'ok'   => true,
            'id'   => $slot->id,
            'kind' => $slot->kind,
            'html' => $html,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Record a click on an ad slot. Bot-filtered; one click per session per
     * slot to keep CTR honest. Called fire-and-forget from the site JS.
     */
    public function adClick(Request $request, AdSlot $slot): JsonResponse
    {
        $ua = strtolower((string) $request->header('User-Agent'));
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|fetcher|scrape|wget|curl|python-requests|httpclient/i', $ua)) {
            return response()->json(['ok' => true, 'note' => 'skipped']);
        }

        $sessionKey = "ad_click_{$slot->id}";
        if (session()->has($sessionKey)) {
            return response()->json(['ok' => true, 'note' => 'duplicate']);
        }
        session()->put($sessionKey, true);

        DB::table('ad_slots')->where('id', $slot->id)->increment('clicks');

        return response()->json(['ok' => true]);
    }
}
