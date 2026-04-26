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
        if ($state === null) {
            return response()->json(['status' => 'idle'])->header('Cache-Control', 'no-store');
        }
        return response()->json($state)->header('Cache-Control', 'no-store');
    }
}
