<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionTier;
use Illuminate\Http\Request;

/**
 * Tier-driven subscription pages. Each row in `subscription_tiers`
 * is one offering on /subscribe; the tier slug doubles as the
 * Cashier subscription name, so Cashier's `newSubscription($slug,
 * $priceId)` and `subscribed($slug)` slot in cleanly.
 *
 * Gift purchases are still a one-time charge against
 * STRIPE_GIFT_PRICE — separate from the recurring tier flow.
 *
 * Cancellation finds the user's active tier (whichever it is) and
 * cancels-at-period-end so they keep access through the current
 * billing window.
 */
class SubscribeController extends Controller
{
    public function show(Request $request)
    {
        $tiers = SubscriptionTier::purchasable()->orderBy('sort_order')->get();
        $stripeReady = (bool) config('cashier.secret');
        $giftPriceId = config('paywall.gift_price_id');

        return view('pages.subscribe', [
            'tiers'       => $tiers,
            'configured'  => $stripeReady && $tiers->isNotEmpty(),
            'giftPrice'   => $giftPriceId,
        ]);
    }

    public function checkout(Request $request)
    {
        $request->validate([
            'tier'    => 'nullable|string|max:60',
            'billing' => 'nullable|in:monthly,annual',
            'plan'    => 'nullable|in:gift', // legacy gift checkout flag
            'seats'   => 'nullable|integer|min:1|max:500',
            'team_name' => 'nullable|string|max:120',
        ]);

        $user = $request->user();
        if (! $user) {
            return redirect()->route('user_login')->with('error_flash_message', 'Please sign in before subscribing.');
        }

        // Gift purchase: one-time charge, no tier subscription.
        if ($request->input('plan') === 'gift') {
            $giftPrice = config('paywall.gift_price_id');
            if (! $giftPrice) {
                return redirect()->route('subscribe.show')
                    ->with('error_flash_message', 'Gift purchases aren\'t configured yet.');
            }
            return $user->checkout([$giftPrice => 1], [
                'success_url'           => route('subscribe.success'),
                'cancel_url'            => route('subscribe.show'),
                'allow_promotion_codes' => true,
            ]);
        }

        $tier = SubscriptionTier::active()->where('slug', $request->input('tier'))->first();
        if (! $tier) {
            return redirect()->route('subscribe.show')
                ->with('error_flash_message', 'That tier isn\'t available right now.');
        }

        $billing = $request->input('billing', 'monthly');
        $priceId = $billing === 'annual'
            ? $tier->stripe_price_annual
            : $tier->stripe_price_monthly;

        if (! $priceId) {
            return redirect()->route('subscribe.show')
                ->with('error_flash_message', 'That billing interval isn\'t available for this tier.');
        }

        // If the user is already subscribed to ANY tier, push them
        // through Cashier's `swap` flow rather than a brand-new
        // subscription so Stripe handles the proration cleanly.
        if (method_exists($user, 'isPaidSubscriber') && $user->isPaidSubscriber()) {
            $existing = $user->currentTier();
            if ($existing && method_exists($user, 'subscription')) {
                $sub = $user->subscription($existing->slug);
                if ($sub) {
                    try {
                        if ($existing->slug === $tier->slug) {
                            // Same tier, different billing interval → swap.
                            return redirect()->route('subscribe.success')->with(
                                'flash_message',
                                $sub->swap($priceId)
                                    ? 'Plan updated. The price change applies on your next renewal.'
                                    : 'Plan updated.'
                            );
                        }
                        // Different tier → swap+invoice for proration.
                        $sub->swapAndInvoice($priceId);
                        // Rename the Cashier row to the new tier slug
                        // so subscribed($tier->slug) resolves correctly.
                        $sub->forceFill(['type' => $tier->slug])->save();
                        return redirect()->route('subscribe.success')->with(
                            'flash_message', 'Tier changed to '.$tier->name.'.'
                        );
                    } catch (\Throwable $e) {
                        return redirect()->route('subscribe.show')->with(
                            'error_flash_message',
                            'Could not change your plan. Try again or contact support.'
                        );
                    }
                }
            }
        }

        // Team-tier first-time purchase: validate seat count, mint
        // the team_subscriptions row when checkout completes (the
        // listener path is in HandleStripeOneTimeCharges' big
        // sibling — for v1 we provision optimistically right after
        // the Cashier subscription saves, since Cashier itself
        // refuses to create a row before Stripe accepts the
        // payment-method).
        if ($tier->is_team) {
            $seats = (int) $request->input('seats', max(2, $tier->min_seats ?? 2));
            $seats = max((int) ($tier->min_seats ?: 2), min((int) ($tier->max_seats ?: 100), $seats));
            $teamName = (string) $request->input('team_name', '');

            $sub = $user->newSubscription($tier->slug, $priceId)->quantity($seats);
            $checkoutSession = $sub->checkout([
                'success_url'           => route('subscribe.success').'?team=1&seats='.$seats.'&tier='.$tier->slug.'&team_name='.urlencode($teamName),
                'cancel_url'            => route('subscribe.show'),
                'allow_promotion_codes' => true,
            ]);
            return $checkoutSession;
        }

        // First-time subscriber: build the Cashier session.
        return $user
            ->newSubscription($tier->slug, $priceId)
            ->checkout([
                'success_url'           => route('subscribe.success'),
                'cancel_url'            => route('subscribe.show'),
                'allow_promotion_codes' => true,
            ]);
    }

    public function success(Request $request)
    {
        // Post-checkout team provisioning. The success_url for a
        // team plan carries `?team=1&seats=N&tier=slug&team_name=…`.
        // We look up the user's now-active Cashier subscription and
        // mint a team_subscriptions row (idempotent: if one already
        // exists for this owner+tier we reuse it).
        if ($request->boolean('team') && ($user = $request->user())) {
            $tierSlug = (string) $request->query('tier', '');
            $seats    = max(1, (int) $request->query('seats', 1));
            $teamName = (string) $request->query('team_name', '');

            if ($tierSlug !== '' && method_exists($user, 'subscribed') && $user->subscribed($tierSlug)) {
                $existing = \App\Models\TeamSubscription::where('owner_user_id', $user->id)
                    ->where('tier_slug', $tierSlug)
                    ->first();
                if (! $existing) {
                    app(\App\Services\Paywall\TeamSubscriptionService::class)
                        ->provision($user, $tierSlug, $seats, $teamName ?: null);
                }
                return redirect()->route('team.show')->with(
                    'flash_message',
                    'Your team subscription is live. Invite your colleagues below.'
                );
            }
        }

        return view('pages.subscribe-success');
    }

    public function cancelShow(Request $request)
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $isSubscribed = method_exists($user, 'isPaidSubscriber') && $user->isPaidSubscriber();
        $currentTier  = $isSubscribed && method_exists($user, 'currentTier') ? $user->currentTier() : null;

        return view('pages.subscribe-cancel', [
            'isSubscribed' => $isSubscribed,
            'currentTier'  => $currentTier,
        ]);
    }

    public function cancelSubmit(Request $request)
    {
        $user = $request->user();
        if (! $user) return redirect()->route('user_login');

        $request->validate([
            'reason'   => 'nullable|string|max:50',
            'feedback' => 'nullable|string|max:2000',
        ]);

        activity('subscription')
            ->causedBy($user)
            ->withProperties([
                'reason'   => $request->input('reason'),
                'feedback' => $request->input('feedback'),
            ])
            ->log('cancellation_survey');

        // Find whichever tier the user is currently on and cancel that
        // specific Cashier subscription. We avoid blanket cancel so a
        // user with multiple subs (rare; future) loses only one.
        $tier = method_exists($user, 'currentTier') ? $user->currentTier() : null;
        $sub = $tier && method_exists($user, 'subscription')
            ? $user->subscription($tier->slug)
            : null;

        if ($sub) {
            try {
                $sub->cancel();
            } catch (\Throwable $e) {
                return redirect()->route('subscribe.cancel.show')
                    ->with('error_flash_message', 'Could not reach Stripe right now. Try again, or contact support.');
            }
        }

        return redirect()->route('subscribe.cancel.show')
            ->with('flash_message', 'Subscription canceled. You\'ll keep access until the end of the current billing period.');
    }
}
