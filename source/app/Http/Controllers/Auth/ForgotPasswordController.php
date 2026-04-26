<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    private const TOKEN_TTL_MINUTES = 60;

    public function forget_password()
    {
        return view('pages.user.forget_password');
    }

    public function forget_password_submit(Request $request): RedirectResponse
    {
        // No `exists:users` rule — that would reveal which addresses
        // have accounts (account enumeration). We accept any well-formed
        // email, only send the actual reset email to addresses that
        // resolve to a login-capable user, and always return the same
        // success flash regardless.
        $rules = ['email' => 'required|email'];
        if (getcong('recaptcha_on_forgot_pass')) {
            $rules['g-recaptcha-response'] = 'required';
        }
        $request->validate($rules);

        if (getcong('recaptcha_on_forgot_pass') && ! verify_recaptcha($request)) {
            Session::flash('error_flash_message', 'Captcha timeout or duplicate');
            return redirect()->back();
        }

        $user = \App\Models\User::where('email', $request->email)->first();

        // News-agent personas don't authenticate, and unknown emails
        // shouldn't reveal anything either. In both cases, fall through
        // to the same success flash without sending mail.
        if ($user && $user->canLogIn()) {
            $plainToken = Str::random(64);
            $hashedToken = hash('sha256', $plainToken);

            DB::table('password_resets')->where('email', $request->email)->delete();
            DB::table('password_resets')->insert([
                'email' => $request->email,
                'token' => $hashedToken,
                'created_at' => Carbon::now(),
            ]);

            try {
                Mail::send('emails.forget_password', ['token' => $plainToken], function ($message) use ($request) {
                    $message->to($request->email);
                    $message->subject('Reset your password');
                });
            } catch (\Throwable $e) {
                \Log::warning('Password reset mail failed: '.$e->getMessage());
            }
        }

        Session::flash('flash_message', trans('words.we_have_email_password_sent_link'));
        return redirect()->back();
    }

    public function reset_password(string $token)
    {
        return view('pages.user.reset_password', ['token' => $token]);
    }

    public function reset_password_submit(Request $request): RedirectResponse
    {
        $rules = [
            // No `exists:users` — let the token check below be the only
            // gate. A non-existent email here will fall through to the
            // generic "Invalid token" response, which is what we want.
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required',
        ];
        if (getcong('recaptcha_on_forgot_pass')) {
            $rules['g-recaptcha-response'] = 'required';
        }
        $request->validate($rules);

        if (getcong('recaptcha_on_forgot_pass') && ! verify_recaptcha($request)) {
            Session::flash('error_flash_message', 'Captcha timeout or duplicate');
            return redirect()->back();
        }

        $hashedToken = hash('sha256', (string) $request->token);

        $row = DB::table('password_resets')
            ->where('email', $request->email)
            ->where('token', $hashedToken)
            ->first();

        if (! $row) {
            Session::flash('error_flash_message', 'Invalid token!');
            return redirect()->back();
        }

        if (Carbon::parse($row->created_at)->diffInMinutes(Carbon::now()) > self::TOKEN_TTL_MINUTES) {
            DB::table('password_resets')->where('email', $request->email)->delete();
            Session::flash('error_flash_message', 'This password reset link has expired. Please request a new one.');
            return redirect('/password/email');
        }

        // Belt and braces: even if a token row was somehow created for
        // a ghost agent, refuse to mutate their password here.
        $resetTarget = User::where('email', $request->email)->first();
        if ($resetTarget && ! $resetTarget->canLogIn()) {
            DB::table('password_resets')->where('email', $request->email)->delete();
            Session::flash('error_flash_message', 'This account isn\'t available for password reset.');
            return redirect('/login');
        }

        User::where('email', $request->email)
            ->update(['password' => Hash::make($request->password)]);

        DB::table('password_resets')->where('email', $request->email)->delete();

        Session::flash('flash_message', trans('words.your_password_changed'));
        return redirect('/login');
    }

}
