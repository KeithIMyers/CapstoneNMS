<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;

/**
 * Send a Web Push notification to every registered subscription.
 * Routinely prunes endpoints that respond 404 / 410 (browser
 * uninstall, profile reset, etc.) so the table doesn't accumulate
 * stale rows.
 *
 * Configured via three env vars:
 *   VAPID_PUBLIC_KEY    base64url-encoded P-256 public key
 *   VAPID_PRIVATE_KEY   base64url-encoded P-256 private key
 *   VAPID_SUBJECT       mailto: or https:// URL identifying the site
 *
 * Generate a key pair once with `php artisan webpush:keys`.
 *
 * Fail-soft: missing config = silent no-op so installs that don't
 * use push don't see errors.
 */
class WebPusher
{
    public function isConfigured(): bool
    {
        return ! empty(config('webpush.vapid_public'))
            && ! empty(config('webpush.vapid_private'));
    }

    /**
     * Broadcast a notification payload to every subscription.
     *
     * @param array{title:string,body:string,url?:string,tag?:string,icon?:string} $payload
     * @return array{sent:int, failed:int, pruned:int}
     */
    public function broadcast(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'note' => 'webpush_not_configured'];
        }
        if (! class_exists(WebPush::class)) {
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'note' => 'webpush_lib_missing'];
        }

        $auth = [
            'VAPID' => [
                'subject'    => config('webpush.subject'),
                'publicKey'  => config('webpush.vapid_public'),
                'privateKey' => config('webpush.vapid_private'),
            ],
        ];

        try {
            $webPush = new WebPush($auth);
        } catch (\Throwable $e) {
            Log::warning('WebPush init failed', ['error' => $e->getMessage()]);
            return ['sent' => 0, 'failed' => 0, 'pruned' => 0, 'note' => 'webpush_init_failed'];
        }

        $stats = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $endpointToRowId = [];

        PushSubscription::query()
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($webPush, $payloadJson, &$stats, &$endpointToRowId) {
                foreach ($rows as $row) {
                    $endpointToRowId[$row->endpoint] = $row->id;
                    try {
                        $sub = WebPushSubscription::create([
                            'endpoint' => $row->endpoint,
                            'keys' => [
                                'p256dh' => $row->p256dh,
                                'auth'   => $row->auth,
                            ],
                        ]);
                        $webPush->queueNotification($sub, $payloadJson);
                    } catch (\Throwable $e) {
                        $stats['failed']++;
                        Log::warning('WebPush queue threw', [
                            'endpoint' => $row->endpoint,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $toPrune = [];
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            if ($report->isSuccess()) {
                $stats['sent']++;
                continue;
            }
            $stats['failed']++;

            // Browsers return 410 or 404 when the endpoint is gone for good.
            $resp = $report->getResponse();
            $status = $resp ? $resp->getStatusCode() : 0;
            if (in_array($status, [404, 410], true) && isset($endpointToRowId[$endpoint])) {
                $toPrune[] = $endpointToRowId[$endpoint];
            }
        }

        if (! empty($toPrune)) {
            PushSubscription::whereIn('id', $toPrune)->delete();
            $stats['pruned'] = count($toPrune);
        }

        return $stats;
    }
}
