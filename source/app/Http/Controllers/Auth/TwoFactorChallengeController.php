<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use PragmaRX\Google2FA\Google2FA;

/**
 * Public-side 2FA challenge for sign-in flows that don't go through
 * the password form (Google OAuth, magic links). The Filament panel
 * has its own challenge page; this one handles authentication that
 * happens BEFORE the Filament middleware chain runs (e.g. an OAuth
 * callback that redirects to the public site).
 *
 * Flow:
 *   1. The upstream flow (OAuth callback / magic link consume)
 *      verifies the user's identity but does NOT call Auth::login().
 *      Instead it stashes the user id + intended URL in the session
 *      and redirects here.
 *   2. show()  renders the code-entry form.
 *   3. verify() checks the TOTP / recovery code, calls Auth::login(),
 *      regenerates the session, and redirects to the intended URL.
 *
 * Mirrors TwoFactorChallenge (Filament page): supports recovery
 * codes, prevents replay within the 30s window, encrypts secrets at
 * rest via the User model casts.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_USER_ID = '2fa.pending.user_id';
    public const SESSION_INTENDED = '2fa.pending.intended';
    public const SESSION_REMEMBER = '2fa.pending.remember';
    /** Hard expiry on the pending challenge so an abandoned tab can't be picked up later. */
    public const TTL_MINUTES = 10;
    public const SESSION_EXPIRES = '2fa.pending.expires_at';

    public function show(Request $request)
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            Session::flash('error_flash_message', 'No pending sign-in. Start over.');
            return redirect()->route('user_login');
        }
        return view('pages.user.two_factor_challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            Session::flash('error_flash_message', 'No pending sign-in. Start over.');
            return redirect()->route('user_login');
        }

        $request->validate(['code' => 'required|string|max:20']);
        $code = trim((string) $request->input('code'));

        if (! $this->codeMatches($user, $code)) {
            return redirect()->route('two_factor.challenge')
                ->withErrors(['code' => 'Invalid code. Try again.']);
        }

        $intended = (string) $request->session()->pull(self::SESSION_INTENDED, '/');
        $remember = (bool) $request->session()->pull(self::SESSION_REMEMBER, false);
        $request->session()->forget([self::SESSION_USER_ID, self::SESSION_EXPIRES]);

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put('two_factor.passed_at', now());

        return redirect($intended ?: ($user->isAuthor() ? '/admin' : '/'));
    }

    /**
     * Stash a pending challenge for `$user` and return a redirect to the
     * challenge page. Callers (OAuth callback, magic-link consume,
     * password login) use this in place of Auth::login() when the user
     * has 2FA enabled.
     */
    public static function challenge(Request $request, User $user, ?string $intended = null, bool $remember = false): RedirectResponse
    {
        $request->session()->put([
            self::SESSION_USER_ID  => $user->id,
            self::SESSION_INTENDED => $intended ?: ($user->isAuthor() ? '/admin' : '/'),
            self::SESSION_REMEMBER => $remember,
            self::SESSION_EXPIRES  => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ]);
        return redirect()->route('two_factor.challenge');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::SESSION_USER_ID);
        if (! $id) return null;

        $expiresAt = (int) $request->session()->get(self::SESSION_EXPIRES, 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            $request->session()->forget([
                self::SESSION_USER_ID, self::SESSION_INTENDED,
                self::SESSION_REMEMBER, self::SESSION_EXPIRES,
            ]);
            return null;
        }

        $user = User::find($id);
        if (! $user || ! $user->canLogIn() || ! $user->hasTwoFactorEnabled()) return null;
        return $user;
    }

    private function codeMatches(User $user, string $code): bool
    {
        if ($code === '') return false;

        $google2fa = new Google2FA();
        if ($user->two_factor_secret) {
            $lastTs = (int) ($user->two_factor_last_used_ts ?? 0);
            $newTs = $google2fa->verifyKeyNewer($user->two_factor_secret, $code, $lastTs, 1);
            if ($newTs !== false) {
                $user->two_factor_last_used_ts = is_int($newTs) ? $newTs : (int) floor(time() / 30);
                $user->save();
                return true;
            }
        }

        // Recovery codes: bcrypt-hashed, single-use; on a match we
        // remove the entry so the same code can't be reused.
        if ($user->two_factor_recovery_codes) {
            $hashes = json_decode($user->two_factor_recovery_codes, true) ?: [];
            foreach ($hashes as $i => $hash) {
                if (is_string($hash) && Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $user->two_factor_recovery_codes = json_encode(array_values($hashes));
                    $user->save();
                    return true;
                }
            }
        }

        return false;
    }
}
