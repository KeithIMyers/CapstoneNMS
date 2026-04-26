<?php

namespace App\Services\Paywall;

use App\Models\TeamSeat;
use App\Models\TeamSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Multi-seat / team subscription orchestration.
 *
 *   provision(owner, tier, seats)  one-shot from the post-Stripe
 *                                  success path: create the
 *                                  team_subscriptions row + N empty
 *                                  team_seats. Returns the team row
 *                                  so the controller can redirect
 *                                  straight to the manage screen.
 *
 *   invite(team, email)            mint a single-use invitation
 *                                  link, email it. The token is
 *                                  sha256-hashed before storage so
 *                                  a row dump can't be replayed.
 *
 *   acceptInvitation(token, user)  consume a token, mark the seat
 *                                  active for the signed-in user.
 *
 *   removeSeat(seat)               return the seat to the open
 *                                  pool. Doesn't reduce Stripe
 *                                  quantity — the owner manages that
 *                                  separately if they downsize.
 *
 *   isTeamMember(user)             cheap check used by Paywall::canRead
 *                                  to honor team entitlement
 *                                  alongside the owner's direct
 *                                  subscription.
 */
class TeamSubscriptionService
{
    public const TOKEN_TTL_DAYS = 14;

    public function provision(User $owner, string $tierSlug, int $seatCount, ?string $teamName = null): TeamSubscription
    {
        $team = TeamSubscription::create([
            'owner_user_id' => $owner->id,
            'tier_slug'     => $tierSlug,
            'seat_count'    => max(1, $seatCount),
            'team_name'     => $teamName ? mb_substr($teamName, 0, 120) : null,
        ]);

        // The owner gets seat 1 automatically — they paid, they're
        // already on the active list. Subsequent seats start empty.
        TeamSeat::create([
            'team_subscription_id' => $team->id,
            'member_user_id'       => $owner->id,
            'status'               => TeamSeat::STATUS_ACTIVE,
            'accepted_at'          => Carbon::now(),
        ]);

        for ($i = 1; $i < $team->seat_count; $i++) {
            TeamSeat::create([
                'team_subscription_id' => $team->id,
                'status'               => TeamSeat::STATUS_OPEN,
            ]);
        }

        return $team;
    }

    /**
     * @return array{ok: bool, reason?: string, message?: string}
     */
    public function invite(TeamSubscription $team, string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') return ['ok' => false, 'reason' => 'invalid_email'];

        if ($team->openSeatsCount() <= 0 && ! $team->seats()->where('status', TeamSeat::STATUS_OPEN)->exists()) {
            return ['ok' => false, 'reason' => 'no_open_seats'];
        }

        // Reuse the open seat with the lowest id rather than
        // creating new rows — keeps the seats collection bounded
        // by the purchased quantity.
        $seat = $team->seats()->where('status', TeamSeat::STATUS_OPEN)->orderBy('id')->first();
        if (! $seat) return ['ok' => false, 'reason' => 'no_open_seats'];

        // Refuse to invite an email already actively on the team.
        $alreadyOn = $team->seats()
            ->whereIn('status', [TeamSeat::STATUS_ACTIVE, TeamSeat::STATUS_INVITED])
            ->where(function ($q) use ($email) {
                $q->where('invited_email', $email)
                  ->orWhereHas('member', fn ($qq) => $qq->where('email', $email));
            })
            ->exists();
        if ($alreadyOn) return ['ok' => false, 'reason' => 'already_on_team'];

        $plain = Str::random(48);
        $hash  = hash('sha256', $plain);

        $seat->forceFill([
            'invited_email'         => $email,
            'invitation_token_hash' => $hash,
            'status'                => TeamSeat::STATUS_INVITED,
            'invited_at'            => Carbon::now(),
        ])->save();

        $url = route('team.accept', ['token' => $plain]);
        try {
            Mail::send('emails.team_invite', [
                'inviterName' => $team->owner?->name,
                'teamName'    => $team->team_name,
                'tierName'    => optional($team->tier())->name,
                'acceptUrl'   => $url,
                'expiresIn'   => self::TOKEN_TTL_DAYS,
            ], function ($message) use ($email) {
                $message->to($email)->subject(
                    'You\'re invited to join a '.(getcong('site_name') ?: config('app.name')).' team subscription'
                );
            });
        } catch (\Throwable $e) {
            Log::warning('Team invite email failed', [
                'team' => $team->id, 'email' => $email, 'error' => $e->getMessage(),
            ]);
        }

        return ['ok' => true];
    }

    /**
     * Consume a redemption token. Returns the seat on success, null
     * on any miss (the controller surfaces a generic "invalid or
     * already used" error in either case). Wrapped in a transaction
     * with a row-level lock so two concurrent accept clicks can't
     * both succeed against the same seat.
     */
    public function acceptInvitation(string $token, User $user): ?TeamSeat
    {
        $token = trim($token);
        if (strlen($token) < 16) return null;
        $hash = hash('sha256', $token);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($hash, $user) {
            $seat = TeamSeat::query()
                ->where('invitation_token_hash', $hash)
                ->where('status', TeamSeat::STATUS_INVITED)
                ->lockForUpdate()
                ->first();
            if (! $seat) return null;

            // TTL guard.
            if ($seat->invited_at && $seat->invited_at->copy()->addDays(self::TOKEN_TTL_DAYS)->isPast()) {
                return null;
            }

            // Defense-in-depth: only honor the token if the accepting
            // user's email matches the address the invite was sent to.
            // The token alone is unforgeable (48 random chars + sha256
            // storage), but if a future code path leaks it (logs,
            // referrer headers), the email match still blocks misuse.
            if ($seat->invited_email
                && strtolower(trim($seat->invited_email)) !== strtolower(trim((string) $user->email))) {
                return null;
            }

            // Refuse to assign two seats on the same team to one user
            // (e.g. accepting a second invite to the same team).
            $alreadyOn = TeamSeat::where('team_subscription_id', $seat->team_subscription_id)
                ->where('member_user_id', $user->id)
                ->exists();
            if ($alreadyOn) return null;

            $seat->forceFill([
                'member_user_id'        => $user->id,
                'status'                => TeamSeat::STATUS_ACTIVE,
                'accepted_at'           => Carbon::now(),
                'invitation_token_hash' => null,
            ])->save();

            return $seat;
        });
    }

    public function removeSeat(TeamSeat $seat): void
    {
        $seat->forceFill([
            'status'                => TeamSeat::STATUS_OPEN,
            'member_user_id'        => null,
            'invited_email'         => null,
            'invitation_token_hash' => null,
            'removed_at'            => Carbon::now(),
            'invited_at'            => null,
            'accepted_at'           => null,
        ])->save();
    }

    /**
     * Whether the user has an active seat on a team whose owner's
     * subscription is current. Used by Paywall::canRead so team
     * members enjoy the same gates as a direct subscriber.
     */
    public function isTeamMember(User $user): bool
    {
        $teamIds = TeamSeat::query()
            ->where('member_user_id', $user->id)
            ->where('status', TeamSeat::STATUS_ACTIVE)
            ->pluck('team_subscription_id')
            ->all();
        if (empty($teamIds)) return false;

        $teams = TeamSubscription::with('owner')->whereIn('id', $teamIds)->get();
        foreach ($teams as $team) {
            if ($team->isActive()) return true;
        }
        return false;
    }
}
