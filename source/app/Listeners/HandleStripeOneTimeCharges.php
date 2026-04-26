<?php

namespace App\Listeners;

use App\Models\ArticlePurchase;
use App\Models\Donation;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Listens to Cashier's WebhookReceived event so we can claim the
 * one-time-charge events Cashier itself doesn't care about
 * (donations + pay-per-article both flow through Stripe Checkout in
 * `mode=payment`, which Cashier sees as a no-op).
 *
 * The `metadata.kind` field on each Stripe Checkout Session tells
 * us which internal table to update; matching ids come back via
 * `metadata.donation_id` / `metadata.article_purchase_id`.
 *
 * Cashier already verifies the webhook signature for us — by the
 * time this listener runs, the payload is trusted.
 */
class HandleStripeOneTimeCharges
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload ?? [];
        $type = (string) ($payload['type'] ?? '');

        if ($type !== 'checkout.session.completed') return;

        $session = $payload['data']['object'] ?? [];
        if (($session['mode'] ?? null) !== 'payment') return;

        $kind = $session['metadata']['kind'] ?? null;
        switch ($kind) {
            case 'donation':         $this->markDonation($session);  break;
            case 'article_purchase': $this->markPurchase($session); break;
            default:                 return; // not ours; gift flow uses no metadata.kind
        }
    }

    private function markDonation(array $session): void
    {
        $id = (int) ($session['metadata']['donation_id'] ?? 0);
        if ($id <= 0) return;

        $row = Donation::find($id);
        if (! $row) {
            Log::warning('Donation webhook for unknown id', ['donation_id' => $id]);
            return;
        }

        // Idempotency: Stripe retries failed webhook deliveries. Once
        // a row is already succeeded, replays would otherwise rewrite
        // the stripe_charge_id and clobber the audit timestamp on a
        // race. Short-circuit cleanly.
        if ($row->status === 'succeeded') return;

        $row->forceFill([
            'status'           => $session['payment_status'] === 'paid' ? 'succeeded' : 'failed',
            'stripe_charge_id' => (string) ($session['payment_intent'] ?? $row->stripe_charge_id),
        ])->save();
    }

    private function markPurchase(array $session): void
    {
        $id = (int) ($session['metadata']['article_purchase_id'] ?? 0);
        if ($id <= 0) return;

        $row = ArticlePurchase::find($id);
        if (! $row) {
            Log::warning('Article purchase webhook for unknown id', ['purchase_id' => $id]);
            return;
        }

        // Same idempotency guard as donations.
        if ($row->status === 'succeeded') return;

        $row->forceFill([
            'status'           => $session['payment_status'] === 'paid' ? 'succeeded' : 'failed',
            'stripe_charge_id' => (string) ($session['payment_intent'] ?? $row->stripe_charge_id),
        ])->save();
    }
}
