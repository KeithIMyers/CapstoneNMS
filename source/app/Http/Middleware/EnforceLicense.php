<?php

namespace App\Http\Middleware;

use App\Services\Licensing\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the admin panel on a valid license.
 *
 * Three failure modes:
 *   - unlicensed       no envelope on disk
 *   - invalid          envelope is malformed / signature failed
 *   - expired          past expiry AND past the grace window
 *   - host-mismatch    license signature ok but the request host
 *                      isn't in the licensed domain list (and isn't
 *                      a permitted dev loopback)
 *
 * In every failure mode we redirect the admin to the License page
 * (which IS allowed through this middleware so they can upload a
 * fresh envelope without being locked out forever) and surface the
 * reason on that page.
 *
 * `grace` and `active` states pass through. The grace window keeps
 * the admin functional for 14 days post-expiry while the dev banner
 * + License page nudge them to renew.
 */
class EnforceLicense
{
    public function __construct(private readonly LicenseService $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Always let the License page itself through so the admin
        // can upload a fresh envelope when their current one is
        // bad. Same for the logout route.
        if ($this->isAlwaysAllowed($request)) {
            return $next($request);
        }

        $status = $this->license->status();
        $state = $status['state'] ?? 'unlicensed';

        if ($state === 'active' || $state === 'grace') {
            // Domain check fires only on a verified license. Failure
            // here means the install was moved to a host the license
            // doesn't cover (or a pirate is fronting it).
            if (! $this->license->hostMatches($request)) {
                session()->flash('license_block_reason', 'License domain mismatch');
                return redirect()->to('/admin/license');
            }
            return $next($request);
        }

        // unlicensed / invalid / expired — block + redirect.
        $reasons = [
            'unlicensed' => 'No license uploaded yet.',
            'invalid'    => 'License signature did not verify.',
            'expired'    => 'License expired beyond the grace window.',
        ];
        session()->flash('license_block_reason', $reasons[$state] ?? 'License not valid.');
        return redirect()->to('/admin/license');
    }

    private function isAlwaysAllowed(Request $request): bool
    {
        return $request->is('admin/license')
            || $request->is('admin/license/*')
            || $request->is('admin/logout')
            || $request->is('admin/login');
    }
}
