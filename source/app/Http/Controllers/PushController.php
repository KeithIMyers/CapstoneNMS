<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints for browser-side Web Push registration. The client-side
 * flow:
 *
 *   GET  /push/key            ← VAPID public key (so the browser can subscribe)
 *   POST /push/subscribe      ← endpoint + p256dh + auth from PushManager.subscribe()
 *   POST /push/unsubscribe    ← endpoint to drop
 *
 * No CSRF on subscribe / unsubscribe (they're invoked from JS that
 * doesn't have a CSRF token in the same request flow); auth is the
 * VAPID signing instead.
 */
class PushController extends Controller
{
    public function key(): JsonResponse
    {
        $public = config('webpush.vapid_public');
        if (! $public) {
            return response()->json(['enabled' => false]);
        }
        return response()->json([
            'enabled' => true,
            'key'     => $public,
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint'      => 'required|url|max:500',
            'keys.p256dh'   => 'required|string|max:200',
            'keys.auth'     => 'required|string|max:100',
        ]);

        $sub = PushSubscription::firstOrNew(['endpoint' => $request->input('endpoint')]);
        $sub->p256dh       = $request->input('keys.p256dh');
        $sub->auth         = $request->input('keys.auth');
        $sub->user_id      = optional($request->user())->id;
        $sub->user_agent   = mb_substr((string) $request->header('User-Agent'), 0, 255);
        $sub->last_used_at = now();
        $sub->save();

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => 'required|url|max:500']);
        PushSubscription::where('endpoint', $request->input('endpoint'))->delete();
        return response()->json(['ok' => true]);
    }
}
