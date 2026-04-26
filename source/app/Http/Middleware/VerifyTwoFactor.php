<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate Filament access on a verified 2FA challenge.
 *
 * Flow:
 *   1. If the user has not enrolled, no challenge is required.
 *   2. If they have enrolled but the current session has not passed a
 *      challenge, redirect to the challenge page.
 *   3. The challenge page itself, the logout route, and Livewire updates
 *      that target the challenge component must remain accessible.
 *
 * The Livewire-update case is the dangerous one: blanket-exempting
 * /livewire/update lets an attacker drive any Filament resource
 * component (EditUser, etc.) without ever passing the challenge.
 * We instead inspect the request's component snapshots and only allow
 * components whose `memo.path` is the challenge page itself.
 */
class VerifyTwoFactor
{
    private const CHALLENGE_PATH = 'admin/two-factor-challenge';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if (session('two_factor.passed_at')) {
            return $next($request);
        }

        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        if ($this->isLivewireRequest($request) && $this->livewireTargetsChallenge($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'Two-factor authentication required.',
                'redirect' => '/'.self::CHALLENGE_PATH,
            ], 423); // 423 Locked
        }

        return redirect()->to('/'.self::CHALLENGE_PATH);
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        $allowed = [
            self::CHALLENGE_PATH,
            'admin/logout',
        ];

        foreach ($allowed as $path) {
            if ($request->is($path) || $request->is($path.'/*')) {
                return true;
            }
        }

        return false;
    }

    private function isLivewireRequest(Request $request): bool
    {
        return $request->is('livewire/update')
            || $request->is('livewire/upload-file')
            || $request->is('livewire/preview-file/*');
    }

    /**
     * Inspect the Livewire payload and return true only when every component
     * being updated is anchored to the challenge page (`memo.path` matches).
     * Anything else is rejected so that this endpoint cannot be used as a
     * generic Filament-resource-driving back door.
     */
    private function livewireTargetsChallenge(Request $request): bool
    {
        $components = $request->input('components');
        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = $component['snapshot'] ?? null;
            if (! is_string($snapshot)) {
                return false;
            }

            $decoded = json_decode($snapshot, true);
            $path = $decoded['memo']['path'] ?? null;

            if ($path === null) {
                return false;
            }

            // Trim leading slash and any querystring before matching.
            $path = ltrim((string) $path, '/');
            $path = strtok($path, '?');

            if ($path !== self::CHALLENGE_PATH) {
                return false;
            }
        }

        return true;
    }
}
