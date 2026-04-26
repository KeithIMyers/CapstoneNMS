<?php

namespace App\Services\Referrals;

use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Referral lifecycle:
 *
 *   codeFor(user)              lazy-generate + persist a unique
 *                              referral_code on the user. Idempotent.
 *
 *   capture(request, user)     called after signup completes; if
 *                              the cookie `usnt_ref` carries a
 *                              valid code and points to a different
 *                              user, link referred_by_user_id and
 *                              clear the cookie.
 *
 *   stashFromUrl(request)      reads ?ref=CODE off any landing
 *                              page and stashes it in a 30-day
 *                              cookie. Idempotent on re-visit.
 *
 *   onConversion(referee)      a referee converted to subscriber.
 *                              Mints a referral_rewards row in
 *                              `granted` status; admins fulfill via
 *                              the Filament resource.
 */
class ReferralService
{
    public const COOKIE = 'usnt_ref';
    public const COOKIE_TTL_DAYS = 30;

    public const DEFAULT_REWARD_TYPE   = ReferralReward::TYPE_FREE_MONTHS;
    public const DEFAULT_REWARD_AMOUNT = 1;

    public function codeFor(User $user): string
    {
        if ($user->referral_code) return $user->referral_code;

        // 8 chars, base32-ish. Avoids ambiguous 0/O/1/I.
        do {
            $code = strtoupper(substr(
                str_replace(['0','1','O','I'], ['2','3','P','J'], Str::random(8)),
                0, 8,
            ));
            $exists = User::where('referral_code', $code)->exists();
        } while ($exists);

        $user->forceFill(['referral_code' => $code])->save();
        return $code;
    }

    public function stashFromUrl(Request $request): void
    {
        $code = trim((string) $request->query('ref', ''));
        if ($code === '' || ! preg_match('/^[A-Za-z0-9]{4,16}$/', $code)) return;
        cookie()->queue(self::COOKIE, strtoupper($code), 60 * 24 * self::COOKIE_TTL_DAYS, '/', null, true, true, false, 'lax');
    }

    public function capture(Request $request, User $user): void
    {
        $code = strtoupper(trim((string) $request->cookie(self::COOKIE, '')));
        if ($code === '') $code = strtoupper(trim((string) $request->query('ref', '')));
        if ($code === '') return;
        if ($user->referral_code === $code) return; // can't refer yourself

        $referrer = User::where('referral_code', $code)->first();
        if (! $referrer || $referrer->id === $user->id) return;

        // First-write-wins: don't overwrite an existing referrer
        // (a user clicking a fresh link after signup shouldn't
        // re-attribute their account).
        if ($user->referred_by_user_id) return;

        $user->forceFill(['referred_by_user_id' => $referrer->id])->save();
        cookie()->queue(cookie()->forget(self::COOKIE));
    }

    /**
     * The referee just upgraded to a paid plan. Mint the reward
     * row for their referrer in PENDING status, idempotent on the
     * (referrer, referee) unique key.
     *
     * Pending lets the post-conversion-hold cron flip the row to
     * granted only after a 30-day chargeback window — otherwise a
     * referee who cancels mid-window leaves the referrer with a
     * fulfilled reward they didn't really earn. Self-referral is
     * rejected up front.
     */
    public function onConversion(User $referee): ?ReferralReward
    {
        if (! $referee->referred_by_user_id) return null;
        // Reject self-referral: same id (couldn't normally happen via
        // capture(), which already filters), same email, or same
        // normalized email host+localpart (a+1 / a+2 aliases on
        // providers that ignore +-suffixes).
        $referrer = User::find($referee->referred_by_user_id);
        if (! $referrer) return null;
        if ($referrer->id === $referee->id) return null;
        if (strtolower(trim((string) $referrer->email)) === strtolower(trim((string) $referee->email))) {
            return null;
        }
        if ($this->emailsLikelyShared($referrer->email, $referee->email)) {
            \Illuminate\Support\Facades\Log::info('ReferralService: self-refer suspected, skipping reward', [
                'referrer' => $referrer->id, 'referee' => $referee->id,
            ]);
            return null;
        }

        $existing = ReferralReward::where('referrer_user_id', $referee->referred_by_user_id)
            ->where('referee_user_id', $referee->id)
            ->first();
        if ($existing) return $existing;

        return ReferralReward::create([
            'referrer_user_id' => $referee->referred_by_user_id,
            'referee_user_id'  => $referee->id,
            'referee_email'    => $referee->email,
            'reward_type'      => self::DEFAULT_REWARD_TYPE,
            'reward_amount'    => self::DEFAULT_REWARD_AMOUNT,
            'status'           => ReferralReward::STATUS_PENDING,
            'triggered_at'     => Carbon::now(),
        ]);
    }

    /**
     * Heuristic: do these two addresses look like the same human?
     * Catches the obvious self-refer cases — Gmail dot-aliases and
     * `+`-suffixed aliases — without trying to be a deliverability
     * service. False positives here are fine; a benign collision just
     * means an admin has to grant the reward manually.
     */
    private function emailsLikelyShared(?string $a, ?string $b): bool
    {
        if (! $a || ! $b) return false;
        $norm = function (string $e): string {
            $e = strtolower(trim($e));
            [$local, $host] = array_pad(explode('@', $e, 2), 2, '');
            $local = explode('+', $local, 2)[0];
            // Treat googlemail.com as gmail.com; strip dots in gmail
            // local-parts, which Gmail ignores anyway.
            if ($host === 'googlemail.com') $host = 'gmail.com';
            if ($host === 'gmail.com') $local = str_replace('.', '', $local);
            return $local.'@'.$host;
        };
        return $norm($a) === $norm($b);
    }
}
