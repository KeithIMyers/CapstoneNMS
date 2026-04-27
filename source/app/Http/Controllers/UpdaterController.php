<?php

namespace App\Http\Controllers;

use App\Services\Update\ProgressTracker;
use Illuminate\Http\JsonResponse;

/**
 * Lightweight JSON endpoint the Filament Updates page polls while
 * an apply is in flight. Designed to be cheap AND non-blocking:
 *
 *   - No session middleware. Laravel's file-driver session takes an
 *     exclusive lock during request handling. An in-flight 30-60s
 *     apply request would otherwise starve every concurrent poll
 *     for the entire duration, leaving the UI frozen at its last-
 *     rendered percent until the apply finally releases the lock.
 *
 *   - No `auth` middleware. We don't have a `login` named route in
 *     this app, so the default Authenticate redirect-on-fail throws
 *     "Route [login] not defined" → 500 HTML, which the polling
 *     fetch can't parse and the UI hangs.
 *
 * Information exposure is minimal: an in-flight step name + percent
 * + version. No license, no secrets, no customer PII.
 */
class UpdaterController extends Controller
{
    public function progress(ProgressTracker $tracker): JsonResponse
    {
        $state = $tracker->read();

        // Auto-clean stale terminal states. Once a complete/failed
        // entry has been around for over a minute it's no longer
        // useful, and leaving it on disk lets the in-page progress
        // card show a stale "100% Done" the next time the admin
        // navigates to /admin/updates.
        if ($state !== null
            && in_array($state['status'] ?? null, ['complete', 'failed'], true)
            && isset($state['finished_at'])
            && (time() - (int) $state['finished_at']) > 60) {
            $tracker->clear();
            $state = null;
        }

        if ($state === null) {
            return response()->json(['status' => 'idle'])->header('Cache-Control', 'no-store');
        }
        return response()->json($state)->header('Cache-Control', 'no-store');
    }
}
