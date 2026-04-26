<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSend;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Postmark Inbound Webhooks. Configure under Servers → {your server}
 * → Webhooks. Point each event you care about (Delivery, Bounce,
 * SpamComplaint, Open, Click, SubscriptionChange) at:
 *
 *   POST /webhooks/mail/postmark
 *
 * Postmark verifies via Basic Auth on the URL — set
 *   POSTMARK_WEBHOOK_USER, POSTMARK_WEBHOOK_PASSWORD
 * and Postmark prepends them as `https://user:pass@host/...`. We
 * compare the supplied credentials to the configured pair before
 * trusting the body.
 *
 * Each event carries `MessageID` (the X-PM-Message-Id we already
 * stamped on the send) and a `Metadata` object including the
 * `newsletter_send_id` we passed via X-PM-Metadata-* headers. Either
 * key is enough to find the matching NewsletterSend row.
 */
class PostmarkWebhookController extends Controller
{
    /**
     * Replay protection: refuse webhooks whose carrier event timestamp
     * is older than this. Postmark doesn't sign a header timestamp, so
     * we look at the per-event timestamps inside the payload (Postmark
     * events stamp DeliveredAt / BouncedAt / ReceivedAt). Idempotency
     * by MessageID still handles the no-op case; this catches a captured
     * payload being replayed days later to flip statuses around.
     */
    private const REPLAY_WINDOW_HOURS = 48;

    public function __invoke(Request $request): Response
    {
        if (! $this->verify($request)) {
            return response('forbidden', 403);
        }

        $payload = $request->json()->all();
        if (empty($payload)) return response('ok', 200);

        if (! $this->withinReplayWindow($payload)) {
            Log::warning('Postmark webhook rejected: outside replay window', [
                'record_type' => $payload['RecordType'] ?? null,
                'message_id'  => $payload['MessageID'] ?? null,
            ]);
            return response('stale', 200); // 200 so Postmark stops retrying
        }

        $send = $this->locateSend($payload);
        if (! $send) {
            // Webhook for an email we didn't track (transactional,
            // legacy, etc.) — ack so Postmark stops retrying.
            return response('ignored', 200);
        }

        $type = (string) ($payload['RecordType'] ?? '');
        $when = Carbon::parse($payload['ReceivedAt'] ?? $payload['DeliveredAt'] ?? $payload['BouncedAt'] ?? null) ?: Carbon::now();

        match ($type) {
            'Delivery'         => $this->markDelivered($send, $when),
            'Bounce'           => $this->markBounced($send, $payload, $when),
            'SpamComplaint'    => $this->markComplained($send, $when),
            'Open'             => $this->bumpOpen($send, $when),
            'Click'            => $this->bumpClick($send, $when),
            'SubscriptionChange' => $this->markUnsubscribed($send, $when),
            default            => null,
        };

        return response('ok', 200);
    }

    private function verify(Request $request): bool
    {
        $user = (string) config('services.postmark.webhook_user', env('POSTMARK_WEBHOOK_USER'));
        $pass = (string) config('services.postmark.webhook_password', env('POSTMARK_WEBHOOK_PASSWORD'));
        if ($user === '' || $pass === '') {
            // No credentials configured — log + reject so a misconfig
            // doesn't silently accept untrusted payloads.
            Log::warning('Postmark webhook called but POSTMARK_WEBHOOK_USER/PASSWORD not set');
            return false;
        }

        $supplied = $request->getUser().':'.$request->getPassword();
        $expected = $user.':'.$pass;
        return hash_equals($expected, $supplied);
    }

    private function withinReplayWindow(array $payload): bool
    {
        $candidates = [
            $payload['ReceivedAt'] ?? null,
            $payload['DeliveredAt'] ?? null,
            $payload['BouncedAt'] ?? null,
            $payload['ChangedAt'] ?? null,  // SubscriptionChange
            $payload['ReceivedAt'] ?? null,
        ];
        foreach ($candidates as $ts) {
            if (! $ts) continue;
            try {
                $when = Carbon::parse($ts);
            } catch (\Throwable $e) {
                continue;
            }
            // Reject if older than the window OR more than 30min in
            // the future (clock skew tolerance).
            if ($when->diffInHours(Carbon::now(), false) > self::REPLAY_WINDOW_HOURS) {
                return false;
            }
            if ($when->isAfter(Carbon::now()->addMinutes(30))) {
                return false;
            }
            return true;
        }
        // No timestamp in the payload at all — accept (older Postmark
        // record types). Idempotency-by-MessageID still protects us.
        return true;
    }

    private function locateSend(array $payload): ?NewsletterSend
    {
        $sendId = $payload['Metadata']['newsletter_send_id'] ?? null;
        if ($sendId) {
            $row = NewsletterSend::find((int) $sendId);
            if ($row) return $row;
        }

        $msgId = $payload['MessageID'] ?? null;
        if ($msgId) {
            return NewsletterSend::where('provider', 'postmark')
                ->where('provider_message_id', $msgId)
                ->first();
        }
        return null;
    }

    private function markDelivered(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill([
            'status'         => 'delivered',
            'delivered_at'   => $when,
            'last_event_at'  => $when,
        ])->save();
    }

    private function markBounced(NewsletterSend $s, array $payload, Carbon $when): void
    {
        $s->forceFill([
            'status'         => 'bounced',
            'bounced_at'     => $when,
            'last_event_at'  => $when,
            'error_message'  => mb_substr((string) ($payload['Description'] ?? ''), 0, 1000),
        ])->save();

        // Hard bounces should disable the subscription so the next
        // blast doesn't re-attempt. Postmark types: HardBounce,
        // SpamNotification, ManuallyDeactivated, Unknown.
        $bounceType = (string) ($payload['Type'] ?? '');
        if (in_array($bounceType, ['HardBounce', 'ManuallyDeactivated'], true) && $s->subscription_id) {
            Subscription::where('id', $s->subscription_id)->update([
                'unsubscribed_at' => now(),
            ]);
        }
    }

    private function markComplained(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill([
            'status'         => 'complained',
            'complained_at'  => $when,
            'last_event_at'  => $when,
        ])->save();

        if ($s->subscription_id) {
            Subscription::where('id', $s->subscription_id)->update([
                'unsubscribed_at' => now(),
            ]);
        }
    }

    private function bumpOpen(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill([
            'opens_count'     => $s->opens_count + 1,
            'first_opened_at' => $s->first_opened_at ?: $when,
            'last_event_at'   => $when,
        ])->save();
    }

    private function bumpClick(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill([
            'clicks_count'   => $s->clicks_count + 1,
            'last_event_at'  => $when,
        ])->save();
    }

    private function markUnsubscribed(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill([
            'unsubscribed_at' => $when,
            'last_event_at'   => $when,
        ])->save();

        if ($s->subscription_id) {
            Subscription::where('id', $s->subscription_id)->update([
                'unsubscribed_at' => now(),
            ]);
        }
    }
}
