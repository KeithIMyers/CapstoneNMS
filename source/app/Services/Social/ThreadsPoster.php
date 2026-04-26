<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * Meta Threads API poster. Two-step publish flow:
 *
 *   POST /me/threads          create a media container with the text/url
 *   POST /me/threads_publish  finalize the container as a live post
 *
 * Settings:
 *
 *   social_threads_enabled        toggle
 *   threads_user_id               numeric Threads user id ("me" also works for tokens)
 *   threads_access_token          long-lived token from Meta's app dashboard
 *
 * 500-char post limit; we sit comfortably under that with title + URL.
 */
class ThreadsPoster extends AbstractPoster
{
    private const POST_LIMIT = 500;
    private const API_BASE   = 'https://graph.threads.net/v1.0';

    public function key(): string   { return 'threads'; }
    public function label(): string { return 'Threads (Meta)'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_threads_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        return $this->setting('threads_access_token') !== null
            && $this->setting('threads_user_id') !== null;
    }

    protected function send(News $article, int $rowId): array
    {
        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);
        $text  = $this->fitWithTail($title, $url, self::POST_LIMIT);

        $userId = (string) $this->setting('threads_user_id');
        $token  = (string) $this->setting('threads_access_token');

        // Step 1: container
        $create = Http::timeout(20)->acceptJson()
            ->post(self::API_BASE."/{$userId}/threads", [
                'media_type'   => 'TEXT',
                'text'         => $text,
                'link_attachment' => $url,
                'access_token' => $token,
            ]);
        if (! $create->successful()) {
            $this->markError($rowId, 'api_error', 'create HTTP '.$create->status().': '.mb_substr($create->body(), 0, 600));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $create->status()];
        }
        $containerId = $create->json('id');
        if (! $containerId) {
            $this->markError($rowId, 'api_error', 'create returned no id: '.mb_substr($create->body(), 0, 400));
            return ['ok' => false, 'reason' => 'api_error'];
        }

        // Step 2: publish — Meta recommends a brief delay; the v1.0
        // API will 400 with "media not ready" if you race it.
        usleep(700_000);

        $publish = Http::timeout(20)->acceptJson()
            ->post(self::API_BASE."/{$userId}/threads_publish", [
                'creation_id'  => $containerId,
                'access_token' => $token,
            ]);
        if (! $publish->successful()) {
            $this->markError($rowId, 'api_error', 'publish HTTP '.$publish->status().': '.mb_substr($publish->body(), 0, 600));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $publish->status()];
        }

        $body = $publish->json();
        $threadId = $body['id'] ?? null;
        // Permalink URL is fetched separately; cheap option is to skip
        // and let the audit row hold the API id only.
        $this->markSent($rowId, null, ['container_id' => $containerId, 'thread_id' => $threadId]);
        return ['ok' => true, 'post_url' => null];
    }
}
