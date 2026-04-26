<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\MagicLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

/**
 * Passwordless sign-in entry points. The actual issuing + consuming
 * lives in MagicLinkService — this controller is just routing,
 * captcha, and flash messaging.
 *
 * Routes are throttled via the shared `auth` rate limiter (same
 * 5/min per email + 20/min per IP that protects login & forgot-pass).
 */
class MagicLinkController extends Controller
{
    public function __construct(private readonly MagicLinkService $service) {}

    public function show(Request $request)
    {
        return view('pages.user.magic_link_request');
    }

    public function send(Request $request): RedirectResponse
    {
        $rules = ['email' => 'required|email|max:200'];
        if (getcong('recaptcha_on_login')) {
            $rules['g-recaptcha-response'] = 'required';
        }
        $request->validate($rules);

        if (getcong('recaptcha_on_login') && ! verify_recaptcha($request)) {
            Session::flash('error_flash_message', 'Captcha timeout or duplicate');
            return redirect()->back();
        }

        $this->service->request((string) $request->input('email'), $request);

        // Same flash regardless of whether the email is on file or not
        // — enumeration-safe, mirrors forgot-password.
        Session::flash('flash_message', 'If an account exists for that address, we just sent a sign-in link. Check your inbox.');
        return redirect()->route('magic_link.show');
    }

    public function consume(Request $request, string $token): RedirectResponse
    {
        $user = $this->service->consume($token, $request);

        if (! $user) {
            Session::flash('error_flash_message', 'That sign-in link is invalid or expired. Request a new one.');
            return redirect()->route('magic_link.show');
        }

        $intended = $user->isAuthor() ? '/admin' : '/';

        // If the user has 2FA enrolled, redirect to the challenge page
        // rather than completing the login. The token has already been
        // burned, so a failed challenge means they need a fresh link
        // — exactly the behavior we want.
        if ($user->hasTwoFactorEnabled()) {
            return TwoFactorChallengeController::challenge($request, $user, $intended);
        }

        $this->service->completeLogin($user, $request);

        Session::flash('flash_message', 'Signed in. Welcome back.');
        return redirect($intended);
    }
}
