<?php

namespace App\Http\Middleware;

use App\Services\Referrals\ReferralService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * If a request carries `?ref=CODE` on any GET landing page,
 * persist it in a cookie so the eventual signup picks it up. The
 * service ignores invalid codes; this middleware is cheap (one
 * regex, one cookie write) so wiring it on the global stack is
 * fine.
 */
class CaptureReferralCode
{
    public function __construct(private readonly ReferralService $svc) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->filled('ref')) {
            $this->svc->stashFromUrl($request);
        }
        return $next($request);
    }
}
