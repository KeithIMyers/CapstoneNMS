<?php

namespace App\Http\Controllers;

use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Referrals\ReferralService;
use Illuminate\Http\Request;

/**
 * Reader-facing referral surface.
 *
 *   GET /profile/referrals  shows the user's code, share URL,
 *                           list of people they referred, list of
 *                           rewards earned. Lazily generates the
 *                           user's referral code on first visit.
 */
class ReferralController extends Controller
{
    public function __construct(private readonly ReferralService $svc) {}

    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $code = $this->svc->codeFor($user);
        $shareUrl = url('/?ref='.$code);

        $referees = User::where('referred_by_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'name', 'email', 'created_at']);

        $rewards = ReferralReward::where('referrer_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('pages.user.referrals', compact('code', 'shareUrl', 'referees', 'rewards'));
    }
}
