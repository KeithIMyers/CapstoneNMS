<?php

namespace App\Services\Auth;

use App\Models\MagicLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in flow. Two entry points:
 *
 *   request(email)         issue a token and email it to the address.
 *                          Idempotent + enumeration-safe — the response
 *                          shape never changes whether the address is
 *                          on file or not.
 *
 *   consume(token, request) validate and log the user in. Returns the
 *                          User on success, null on any failure (caller
 *                          shows a generic "invalid or expired" message
 *                          regardless).
 *
 * Tokens are 48 random chars; only the sha256 hash is stored. Single-
 * use via `used_at`; 15-minute hard expiry.
 */
class MagicLinkService
{
    public const TTL_MINUTES = 15;

    public function request(string $email, Request $request): void
    {
        $email = strtolower(trim($email));
        if ($email === '') return;

        $user = User::where('email', $email)->first();

        // Always issue + send for "real" users only. For unknown emails
        // and ghost agents we silently no-op — the controller's flash
        // message is identical regardless, so the response doesn't leak
        // who's on file.
        if (! $user || ! $user->canLogIn()) return;

        $plain = Str::random(48);
        $hash  = hash('sha256', $plain);

        // Invalidate any unused outstanding tokens for this email so
        // the most recent request always wins. Cuts down replay window
        // and keeps the table from growing unboundedly.
        MagicLink::where('email', $email)
            ->whereNull('used_at')
            ->delete();

        MagicLink::create([
            'email'      => $email,
            'token_hash' => $hash,
            'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
            'request_ip' => $request->ip(),
            'request_ua' => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at' => Carbon::now(),
        ]);

        try {
            Mail::send('emails.magic_link', [
                'token'      => $plain,
                'expiresIn'  => self::TTL_MINUTES,
                'requestIp'  => $request->ip(),
                'requestUa'  => mb_substr((string) $request->userAgent(), 0, 120),
                'userName'   => $user->name,
            ], function ($message) use ($email) {
                $message->to($email)->subject('Your sign-in link');
            });
        } catch (\Throwable $e) {
            Log::warning('Magic link mail send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate the token, burn it (single-use), and return the user.
     * Does NOT call Auth::login — the caller is responsible for that
     * so the controller can route 2FA-enrolled users through the
     * challenge page before completing the session.
     */
    public function consume(string $token, Request $request): ?User
    {
        $token = trim($token);
        if (strlen($token) < 16) return null;

        $hash = hash('sha256', $token);

        $row = MagicLink::where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
        if (! $row) return null;

        $user = User::where('email', $row->email)->first();
        // Re-check canLogIn() at consume time — a user could have been
        // banned / converted to ghost agent between request and click.
        if (! $user || ! $user->canLogIn()) return null;

        // Mark single-use BEFORE login so a double-click race can't
        // burn two sessions out of one token.
        $row->forceFill(['used_at' => Carbon::now()])->save();

        return $user;
    }

    /**
     * Complete a magic-link login: log the user in and rotate the
     * session to defeat fixation. Used by the controller after the
     * 2FA branch decision (no-2FA users get logged in here directly;
     * 2FA users go through TwoFactorChallengeController which calls
     * Auth::login itself).
     */
    public function completeLogin(User $user, Request $request): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
    }

    /**
     * Tidy expired or used rows. Called from a scheduled command so
     * the table stays small and a long-archived row can't be probed.
     * Returns the number deleted.
     */
    public function purgeStale(): int
    {
        return MagicLink::where('expires_at', '<', Carbon::now()->subDays(7))
            ->orWhere(function ($q) {
                $q->whereNotNull('used_at')->where('used_at', '<', Carbon::now()->subDays(7));
            })
            ->delete();
    }
}
