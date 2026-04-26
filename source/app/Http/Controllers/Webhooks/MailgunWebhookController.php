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
 * Mailgun Webhooks v2 receiver.
 *
 *   POST /webhooks/mail/mailgun
 *
 * Configure under Sending → Webhooks for the events you want
 * (delivered, opened, clicked, permanent_fail, complained,
 * unsubscribed). Mailgun signs every payload with HMAC-SHA256(
 * timestamp + token, signing_key) — verify before trusting body.
 *
 * Set MAILGUN_WEBHOOK_SIGNING_KEY in env (different from your
 * sending API key — the signing key is in Sending → Webhooks).
 */
class MailgunWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (! $this->verify($request)) {
            return response('forbidden', 403);
        }

        $payload = $request->json()->all();
        $event = $payload['event-data'] ?? [];

        if (empty($event)) return response('ok', 200);

        $send = $this->locateSend($event);
        if (! $send) return response('ignored', 200);

        $when = Carbon::createFromTimestamp((float) ($event['timestamp'] ?? time()));
        $type = (string) ($event['event'] ?? '');

        match ($type) {
            'delivered'      => $this->markDelivered($send, $when),
            'opened'         => $this->bumpOpen($send, $when),
            'clicked'        => $this->bumpClick($send, $when),
            'permanent_fail',
            'failed'         => $this->markBounced($send, $event, $when),
            'complained'     => $this->markComplained($send, $when),
            'unsubscribed'   => $this->markUnsubscribed($send, $when),
            default          => null,
        };

        return response('ok', 200);
    }

    private function verify(Request $request): bool
    {
        $key = (string) config('services.mailgun.webhook_signing_key', env('MAILGUN_WEBHOOK_SIGNING_KEY'));
        if ($key === '') {
            Log::warning('Mailgun webhook called but MAILGUN_WEBHOOK_SIGNING_KEY not set');
            return false;
        }

        $sig = $request->json('signature') ?? [];
        $timestamp = (string) ($sig['timestamp'] ?? '');
        $token     = (string) ($sig['token'] ?? '');
        $supplied  = (string) ($sig['signature'] ?? '');

        if ($timestamp === '' || $token === '' || $supplied === '') return false;

        // Reject anything older than 15 minutes to defeat replay.
        if (abs(time() - (int) $timestamp) > 900) return false;

        $expected = hash_hmac('sha256', $timestamp.$token, $key);
        return hash_equals($expected, $supplied);
    }

    private function locateSend(array $event): ?NewsletterSend
    {
        $vars = $event['user-variables'] ?? [];
        $sendId = $vars['newsletter_send_id'] ?? null;
        if ($sendId) {
            $row = NewsletterSend::find((int) $sendId);
            if ($row) return $row;
        }
        $msgId = $event['message']['headers']['message-id'] ?? null;
        if ($msgId) {
            return NewsletterSend::where('provider', 'mailgun')
                ->where('provider_message_id', $msgId)
                ->first();
        }
        return null;
    }

    private function markDelivered(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill(['status' => 'delivered', 'delivered_at' => $when, 'last_event_at' => $when])->save();
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
            'clicks_count' => $s->clicks_count + 1,
            'last_event_at' => $when,
        ])->save();
    }

    private function markBounced(NewsletterSend $s, array $event, Carbon $when): void
    {
        $s->forceFill([
            'status'        => 'bounced',
            'bounced_at'    => $when,
            'last_event_at' => $when,
            'error_message' => mb_substr((string) ($event['delivery-status']['description']
                ?? $event['reason']
                ?? ''), 0, 1000),
        ])->save();

        if ($s->subscription_id) {
            Subscription::where('id', $s->subscription_id)->update([
                'unsubscribed_at' => now(),
            ]);
        }
    }

    private function markComplained(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill(['status' => 'complained', 'complained_at' => $when, 'last_event_at' => $when])->save();
        if ($s->subscription_id) {
            Subscription::where('id', $s->subscription_id)->update(['unsubscribed_at' => now()]);
        }
    }

    private function markUnsubscribed(NewsletterSend $s, Carbon $when): void
    {
        $s->forceFill(['unsubscribed_at' => $when, 'last_event_at' => $when])->save();
        if ($s->subscription_id) {
            Subscription::where('id', $s->subscription_id)->update(['unsubscribed_at' => now()]);
        }
    }
}
