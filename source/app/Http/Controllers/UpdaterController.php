<?php

namespace App\Http\Controllers;

use App\Services\Update\ProgressTracker;
use Illuminate\Http\JsonResponse;

/**
 * Lightweight JSON endpoint the Filament Updates page polls while
 * an apply is in flight. Lives outside the Filament Livewire surface
 * so the polling doesn't have to share a worker with the long-
 * running apply request — different PHP-FPM workers handle each.
 *
 * Auth: routed inside the admin auth chain (web group + Authenticate
 * + EnforceLicense) so only an admin who can see the Updates page
 * can poll it.
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
