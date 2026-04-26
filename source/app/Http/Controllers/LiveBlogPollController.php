<?php

namespace App\Http\Controllers;

use App\Models\LiveBlogPoll;
use App\Models\LiveBlogPollVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reader-side live-poll voting.
 *
 *   POST /live/polls/{poll}/vote   submit a choice (idempotent: if
 *                                  the voter already voted on this
 *                                  poll, returns the current state
 *                                  without changing the tally).
 *
 *   GET  /live/polls/{poll}/state  poll counters for the current
 *                                  voter (used by the live-blog page
 *                                  to refresh tallies on poll).
 *
 * Voter identity = sha256(usnt_voter cookie || ip || poll_id). The
 * cookie is a random uuid stamped on first vote and persists for a
 * year. Combined with the per-poll-+-voter unique constraint, this
 * dedups casual repeat-voting without forcing reader registration.
 */
class LiveBlogPollController extends Controller
{
    public function vote(Request $request, LiveBlogPoll $poll): JsonResponse
    {
        $request->validate(['choice_id' => 'required|integer|min:0|max:255']);

        if (! $poll->isAcceptingVotes()) {
            return response()->json(['ok' => false, 'reason' => 'closed'], 409);
        }

        $choiceId = (int) $request->input('choice_id');
        $valid = collect($poll->options)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! in_array($choiceId, $valid, true)) {
            return response()->json(['ok' => false, 'reason' => 'invalid_choice'], 422);
        }

        // Issue/refresh the persistent voter cookie. The hash is
        // pinned per-poll so the same browser can vote on different
        // polls but not the same one twice.
        $voter = (string) $request->cookie('usnt_voter');
        $newCookie = false;
        if ($voter === '' || strlen($voter) < 16) {
            $voter = (string) Str::uuid();
            $newCookie = true;
        }
        $voterHash = hash('sha256', $voter.':'.$poll->id);
        $ipHash    = hash('sha256', (string) $request->ip());

        // Dedup on EITHER the cookie-derived voter hash or the IP+poll
        // hash. The cookie alone is trivially rotated by an attacker
        // who reads the Set-Cookie header off the first response, so we
        // also fold in the source IP. Combined: a single browser can
        // vote once, and a single IP can cast at most as many votes as
        // it has unique cookies (the route throttle caps that further).
        $alreadyVoted = LiveBlogPollVote::where('poll_id', $poll->id)
            ->where(function ($q) use ($voterHash, $ipHash) {
                $q->where('voter_hash', $voterHash)
                  ->orWhere('ip_hash', $ipHash);
            })
            ->exists();

        if (! $alreadyVoted) {
            DB::transaction(function () use ($poll, $choiceId, $voterHash, $ipHash) {
                LiveBlogPollVote::create([
                    'poll_id'    => $poll->id,
                    'choice_id'  => $choiceId,
                    'voter_hash' => $voterHash,
                    'ip_hash'    => $ipHash,
                    'created_at' => now(),
                ]);

                $tallies = (array) ($poll->tallies ?? []);
                $tallies[$choiceId] = (int) ($tallies[$choiceId] ?? 0) + 1;
                $poll->forceFill([
                    'tallies'     => $tallies,
                    'total_votes' => (int) $poll->total_votes + 1,
                ])->save();
            });
            $poll->refresh();
        }

        $response = response()->json([
            'ok'           => true,
            'already_voted'=> $alreadyVoted,
            'choice_id'    => $choiceId,
            'tallies'      => $poll->tallies,
            'total_votes'  => $poll->total_votes,
            'percentages'  => $poll->percentages(),
        ]);

        if ($newCookie) {
            $response->cookie('usnt_voter', $voter, 60 * 24 * 365, '/', null, true, true, false, 'lax');
        }
        return $response;
    }

    public function state(Request $request, LiveBlogPoll $poll): JsonResponse
    {
        return response()->json([
            'ok'          => true,
            'tallies'     => $poll->tallies,
            'total_votes' => $poll->total_votes,
            'percentages' => $poll->percentages(),
            'status'      => $poll->status,
        ]);
    }
}
