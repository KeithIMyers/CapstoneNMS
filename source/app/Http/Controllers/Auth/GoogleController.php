<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Http\Request;
use Socialite;
use Auth;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\User;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $google_id = $googleUser->id;
            $user_name = $googleUser->name ?: 'No Name';
            $user_email = $googleUser->email;

            if (empty($google_id) || empty($user_email)) {
                return redirect('/login')->withErrors([
                    'email' => 'Google did not return a usable account. Please try again.',
                ]);
            }

            // Refuse OAuth sign-in/sign-up for Google identities whose email
            // claim isn't verified by Google. Otherwise an attacker can claim
            // a victim's address at the IdP and squat the corresponding local
            // account before the real owner ever signs up. Socialite exposes
            // the raw OIDC payload via `->user`.
            $emailVerified = $googleUser->user['email_verified'] ?? null;
            if ($emailVerified !== true && $emailVerified !== 'true') {
                Log::warning('Google OAuth rejected: email_verified not true', [
                    'email' => $user_email,
                    'google_id_present' => (bool) $google_id,
                ]);
                return redirect('/login')->withErrors([
                    'email' => 'Your Google email isn\'t verified. Verify it with Google and try again.',
                ]);
            }

            // Only match by verified google_id. Matching by email allows an
            // attacker who controls a Google account with the same address as
            // an existing local account to take it over. If the email matches
            // a local account but google_id isn't linked yet, force the user
            // to log in with their password first before linking.
            $finduser = User::where('google_id', $google_id)->first();

            if ($finduser) {
                if (! $finduser->canLogIn()) {
                    return redirect('/login')->withErrors([
                        'email' => 'This account isn\'t available for sign-in.',
                    ]);
                }
                // Honor 2FA: if the user has enrolled, hand off to the
                // public challenge page rather than completing the login.
                if ($finduser->hasTwoFactorEnabled()) {
                    return TwoFactorChallengeController::challenge($request, $finduser, $finduser->isAuthor() ? '/admin' : '/');
                }
                Auth::login($finduser);
                $request->session()->regenerate();
                $request->session()->regenerateToken();
                return redirect('/');
            }

            // Block Google OAuth account creation when the email matches
            // a real user OR a news-agent ghost. Real users are protected
            // from takeover (the original behavior); agent emails should
            // never be claimed via OAuth either.
            $existingUser = User::where('email', $user_email)->first();
            if ($existingUser) {
                if ($existingUser->is_agent) {
                    return redirect('/login')->withErrors([
                        'email' => 'This email isn\'t available for sign-up.',
                    ]);
                }
                return redirect('/login')->withErrors([
                    'email' => 'An account with this email already exists. Please sign in with your password and link Google from your profile.',
                ]);
            }

            // forceCreate: `role` and `google_id` are not in the model's
            // fillable list. The OAuth callback is a trusted path (we've
            // already verified email_verified === true above) so we
            // bypass the mass-assignment guard explicitly here.
            $newUser = User::forceCreate([
                'role' => 'user',
                'name' => $user_name,
                'email' => $user_email,
                'google_id' => $google_id,
                // Random unguessable password — users must use Google or reset
                // via email to sign in with a password.
                'password' => bcrypt(Str::random(40)),
            ]);

            // A brand-new account hasn't had a chance to enroll in 2FA
            // yet, so straight login is fine. Still rotate the session
            // to defeat fixation.
            Auth::login($newUser);
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect('/');
        } catch (Exception $e) {
            Log::error('Google OAuth callback failed', ['error' => $e->getMessage()]);
            return redirect('/login')->withErrors([
                'email' => 'Sign-in with Google failed. Please try again.',
            ]);
        }
    }
}
