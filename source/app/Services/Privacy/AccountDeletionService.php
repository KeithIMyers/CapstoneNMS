<?php

namespace App\Services\Privacy;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * Handles the two-stage account deletion flow:
 *
 *   request(user)      mark deletion_requested_at = now(); user can
 *                      no longer log in. Email them a "click to
 *                      cancel" link active for the grace window.
 *
 *   cancel(user)       clear the timestamp, restoring login.
 *
 *   purge(user)        hard-delete the user + cascade every row that
 *                      references their id, anonymize comments. Run
 *                      by `accounts:purge` once the grace window
 *                      lapses.
 *
 * GRACE_DAYS is the window during which the user can still cancel
 * (and during which the purge command leaves the row alone).
 */
class AccountDeletionService
{
    public const GRACE_DAYS = 7;

    public function request(User $user): void
    {
        if ($user->is_agent || $user->isAdmin()) {
            // Admins and ghost agents cannot self-delete from the
            // public flow — guards an admin from locking the panel
            // with no remaining admin.
            return;
        }

        $user->forceFill(['deletion_requested_at' => Carbon::now()])->save();

        try {
            Mail::send('emails.account_deletion_requested', [
                'name'      => $user->name,
                'graceDays' => self::GRACE_DAYS,
                'cancelUrl' => url('/profile/privacy'),
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Your account is scheduled for deletion');
            });
        } catch (\Throwable $e) {
            Log::warning('Account deletion confirmation email failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    public function cancel(User $user): void
    {
        $user->forceFill(['deletion_requested_at' => null])->save();
    }

    /**
     * Hard-delete the user + cascade. Idempotent on missing tables
     * (so a stripped-down install still purges cleanly).
     */
    public function purge(User $user): void
    {
        $userId = $user->id;
        $email  = $user->email;

        DB::transaction(function () use ($userId, $email) {
            $tables = [
                ['comment_likes',           'user_id'],
                ['comments',                'user_id', 'anonymize'],
                ['reactions',               'user_id'],
                ['favorites',               'user_id'],
                ['reading_history',         'user_id'],
                ['reading_list_items',      'reading_list_id', 'subselect:reading_lists.id WHERE user_id'],
                ['reading_lists',           'user_id'],
                ['push_subscriptions',      'user_id'],
                ['ai_requests',             'user_id', 'anonymize'],
                ['donations',               'user_id', 'anonymize'],
                ['article_purchases',       'user_id'],
                ['article_gifts',           'gifter_user_id', 'anonymize'],
                ['magic_links',             'email'],
                ['newsletter_subscriptions','email'],
                ['assignments',             'assigned_to_user_id', 'detach'],
                // Hard-revoke all auth artifacts so a leaked token /
                // remember-me cookie / live session can't outlive the
                // account it was tied to.
                ['personal_access_tokens',  'tokenable_id'],
                ['sessions',                'user_id'],
            ];

            foreach ($tables as $entry) {
                [$table, $col] = [$entry[0], $entry[1]];
                $mode = $entry[2] ?? 'delete';
                if (! Schema::hasTable($table)) continue;

                $value = $col === 'email' ? $email : $userId;

                if (str_starts_with($mode, 'subselect:')) {
                    DB::table($table)
                        ->whereIn($col, DB::table('reading_lists')->where('user_id', $userId)->pluck('id'))
                        ->delete();
                } elseif ($mode === 'anonymize' || $mode === 'detach') {
                    // Keep the row for editorial / audit value, but
                    // sever the user_id link so the data can no
                    // longer be tied back to the deleted user.
                    DB::table($table)->where($col, $value)->update([$col => null]);
                } else {
                    DB::table($table)->where($col, $value)->delete();
                }
            }

            DB::table('users')->where('id', $userId)->delete();
        });
    }
}
