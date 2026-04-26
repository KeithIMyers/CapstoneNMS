<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionTier;
use App\Models\TeamSeat;
use App\Models\TeamSubscription;
use App\Services\Paywall\TeamSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * Team-subscription owner surface. The paid Stripe checkout itself
 * still flows through SubscribeController; this controller covers
 * the post-checkout invite/manage screen + the recipient-side
 * acceptance flow.
 *
 *   GET  /profile/team               owner's team manage page
 *   POST /profile/team/invite        owner invites a new email
 *   POST /profile/team/seats/{seat}/remove  owner pulls a seat back
 *   GET  /team/accept/{token}        recipient redemption (auto-
 *                                    redirects to login + comes
 *                                    back here when not signed in)
 */
class TeamSubscriptionController extends Controller
{
    public function __construct(private readonly TeamSubscriptionService $svc) {}

    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $teams = TeamSubscription::with(['seats.member:id,name,email', 'owner:id,name,email'])
            ->where('owner_user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        // Plus any team this user is a *member* of (so they can see
        // who the owner is and leave if they want).
        $memberTeams = TeamSeat::query()
            ->where('member_user_id', $user->id)
            ->where('status', TeamSeat::STATUS_ACTIVE)
            ->with(['teamSubscription.owner:id,name,email'])
            ->get()
            ->pluck('teamSubscription')
            ->filter()
            ->unique('id')
            ->reject(fn ($t) => $t->owner_user_id === $user->id) // don't double-list owned teams
            ->values();

        return view('pages.user.team', compact('teams', 'memberTeams'));
    }

    public function invite(Request $request, TeamSubscription $team): RedirectResponse
    {
        $user = $request->user();
        if (! $user || $user->id !== (int) $team->owner_user_id) abort(403);

        $request->validate(['email' => 'required|email|max:255']);

        $r = $this->svc->invite($team, (string) $request->input('email'));

        if (! $r['ok']) {
            $msg = match ($r['reason'] ?? '') {
                'no_open_seats'   => 'No open seats. Remove someone first or upgrade your plan.',
                'already_on_team' => 'That email is already on this team.',
                default           => 'Could not send the invitation. Try again.',
            };
            Session::flash('error_flash_message', $msg);
        } else {
            Session::flash('flash_message', 'Invitation sent.');
        }
        return redirect()->route('team.show');
    }

    public function removeSeat(Request $request, TeamSeat $seat): RedirectResponse
    {
        $user = $request->user();
        $team = $seat->teamSubscription;
        if (! $user || ! $team || $user->id !== (int) $team->owner_user_id) abort(403);

        // Don't let the owner remove their own seat — they manage
        // billing through Stripe; deleting their own seat would
        // strand them.
        if ((int) $seat->member_user_id === $user->id) {
            Session::flash('error_flash_message', 'You can\'t remove your own seat. Cancel the subscription if you\'re winding down.');
            return redirect()->route('team.show');
        }

        $this->svc->removeSeat($seat);
        Session::flash('flash_message', 'Seat freed up.');
        return redirect()->route('team.show');
    }

    /**
     * Recipient-side redemption. Two paths:
     *  - Signed in: accept and redirect to /profile/team.
     *  - Anonymous: stash the token in session and bounce to
     *    sign-in / sign-up; return here after auth.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            $request->session()->put('pending_team_invite', $token);
            Session::flash('flash_message', 'Sign in or create an account to accept the team invitation.');
            return redirect()->route('user_login');
        }

        // After sign-in we may also land here from the session
        // stash; clear it either way.
        $request->session()->forget('pending_team_invite');

        $seat = $this->svc->acceptInvitation($token, $user);
        if (! $seat) {
            Session::flash('error_flash_message', 'That team invitation is invalid, expired, or already used.');
            return redirect()->route('team.show');
        }

        Session::flash('flash_message', 'Welcome aboard. You now have full access via the team subscription.');
        return redirect()->route('team.show');
    }
}
