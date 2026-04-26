<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

/**
 * Public donations / one-time tipping flow. Uses Stripe Checkout in
 * `payment` mode (one-time, not subscription). The session metadata
 * carries our internal donation row id so the webhook listener can
 * mark it `succeeded` when the charge clears.
 *
 * Three preset amounts + a free-form custom amount. Min/max bounded
 * to keep typo-tier mistakes (e.g. $0.05) from cluttering the
 * Stripe dashboard.
 */
class DonateController extends Controller
{
    private const MIN_CENTS = 100;     // $1.00
    private const MAX_CENTS = 100_000; // $1,000.00
    private const PRESETS   = [500, 1500, 5000]; // $5, $15, $50

    public function show(Request $request)
    {
        return view('pages.donate', [
            'presets'    => self::PRESETS,
            'configured' => (bool) config('cashier.secret'),
        ]);
    }

    public function checkout(Request $request)
    {
        // Allow either `amount_cents` (preset radios) or `amount_dollars`
        // (free-form custom amount). Normalize to cents before validation.
        if (! $request->has('amount_cents') && $request->filled('amount_dollars')) {
            $dollars = (float) $request->input('amount_dollars');
            $request->merge(['amount_cents' => max(0, (int) round($dollars * 100))]);
        }

        $request->validate([
            'amount_cents' => 'required|integer|min:'.self::MIN_CENTS.'|max:'.self::MAX_CENTS,
            'name'         => 'nullable|string|max:120',
            'email'        => 'nullable|email|max:255',
            'message'      => 'nullable|string|max:2000',
            'anonymous'    => 'nullable|boolean',
        ]);

        if (! config('cashier.secret')) {
            Session::flash('error_flash_message', 'Donations aren\'t configured on this site yet.');
            return redirect()->route('donate.show');
        }

        $user = $request->user();

        $donation = Donation::create([
            'user_id'      => $user?->id,
            'email'        => $user?->email ?: $request->input('email'),
            'name'         => (bool) $request->boolean('anonymous')
                ? null
                : ($request->input('name') ?: $user?->name),
            'amount_cents' => (int) $request->input('amount_cents'),
            'currency'     => 'USD',
            'message'      => $request->input('message'),
            'anonymous'    => (bool) $request->boolean('anonymous'),
            'status'       => 'pending',
        ]);

        Stripe::setApiKey(config('cashier.secret'));

        $session = StripeSession::create([
            'mode'                  => 'payment',
            'success_url'           => route('donate.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'            => route('donate.show'),
            'allow_promotion_codes' => false,
            'customer_email'        => $donation->email,
            'metadata'              => [
                'donation_id' => (string) $donation->id,
                'kind'        => 'donation',
            ],
            'line_items' => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower($donation->currency),
                    'product_data' => [
                        'name'        => 'Support '.(getcong('site_name') ?: config('app.name')),
                        'description' => 'One-time donation',
                    ],
                    'unit_amount'  => $donation->amount_cents,
                ],
            ]],
        ]);

        $donation->forceFill(['stripe_session_id' => $session->id])->save();

        return redirect($session->url, 303);
    }

    public function success(Request $request)
    {
        // We don't trust the success-url query param for marking the
        // donation succeeded — the Stripe webhook handles that. This
        // page is just a friendly thank-you that may render a few
        // seconds before the webhook fires.
        return view('pages.donate-success');
    }
}
