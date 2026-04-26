<?php

namespace App\Http\Controllers;

use App\Services\Privacy\AccountDeletionService;
use App\Services\Privacy\DataExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * GDPR / CCPA self-service surface. Lives under /profile/privacy
 * for authenticated users.
 *
 *   GET  /profile/privacy          settings page (data export +
 *                                  preference toggles + delete CTA).
 *   POST /profile/privacy          save preferences (do_not_sell,
 *                                  marketing_email_opt_out,
 *                                  behavioral_tracking_opt_out).
 *   POST /profile/privacy/export   build the data dump and stream
 *                                  it as a JSON download.
 *   POST /profile/privacy/delete   schedule the account for deletion
 *                                  after the grace window.
 *   POST /profile/privacy/cancel-deletion
 *                                  clear deletion_requested_at,
 *                                  restoring login access.
 */
class PrivacyController extends Controller
{
    public function __construct(
        private readonly DataExportService $exporter,
        private readonly AccountDeletionService $deleter,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            Session::flash('error_flash_message', 'Sign in to manage your privacy preferences.');
            return redirect()->route('user_login');
        }
        return view('pages.user.privacy', compact('user'));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $request->validate([
            'do_not_sell'                 => 'nullable|boolean',
            'marketing_email_opt_out'     => 'nullable|boolean',
            'behavioral_tracking_opt_out' => 'nullable|boolean',
        ]);

        $user->forceFill([
            'privacy_prefs' => [
                'do_not_sell'                 => (bool) $request->boolean('do_not_sell'),
                'marketing_email_opt_out'     => (bool) $request->boolean('marketing_email_opt_out'),
                'behavioral_tracking_opt_out' => (bool) $request->boolean('behavioral_tracking_opt_out'),
            ],
        ])->save();

        Session::flash('flash_message', 'Privacy preferences saved.');
        return redirect()->route('privacy.show');
    }

    public function export(Request $request): Response
    {
        $user = $request->user();
        if (! $user) abort(403);

        $payload = $this->exporter->exportFor($user);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Filename intentionally does NOT include the user's email — a
        // saved download in a shared folder shouldn't reveal the
        // account address. The numeric id is enough for the user to
        // tell their own exports apart.
        $filename = sprintf(
            'data-export-%d-%s.json',
            $user->id,
            now()->format('Ymd-His'),
        );

        return response($json, 200, [
            'Content-Type'              => 'application/json; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options'    => 'nosniff',
            'Cache-Control'             => 'no-store, private',
        ]);
    }

    public function requestDeletion(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) abort(403);

        // Confirm by typing email + the literal word "delete" — same
        // pattern GitHub / Stripe use to make a slip-of-the-finger
        // hard.
        $request->validate([
            'confirm_email' => 'required|email',
            'confirm_word'  => 'required|in:delete,DELETE',
        ]);

        if (strtolower(trim($request->input('confirm_email'))) !== strtolower($user->email)) {
            return redirect()->route('privacy.show')
                ->with('error_flash_message', 'Email confirmation didn\'t match the account email.');
        }

        $this->deleter->request($user);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with(
            'flash_message',
            'Your account is scheduled for deletion. You\'ll receive a confirmation email; check it for a link to cancel within the grace window.'
        );
    }

    public function cancelDeletion(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            // The user is logged out (canLogIn() blocks them) — but
            // we still want to support the cancel link in the
            // confirmation email. Look up by email + a one-time
            // token would be cleanest; v1 punts to "log in via magic
            // link, which clears it before this flow runs".
            return redirect()->route('magic_link.show')
                ->with('flash_message', 'Sign in via a magic link to cancel a pending deletion.');
        }

        $this->deleter->cancel($user);
        Session::flash('flash_message', 'Account deletion canceled.');
        return redirect()->route('privacy.show');
    }
}
