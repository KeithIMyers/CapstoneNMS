<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * Mastodon Statuses API poster. Configured via three settings rows:
 *
 *   social_mastodon_enabled       toggle
 *   mastodon_instance             e.g. https://mastodon.social
 *   mastodon_access_token         user's API token w/ write:statuses scope
 *
 * One status per article: "<title>\n\n<url>", truncated to 500 chars.
 */
class MastodonPoster extends AbstractPoster
{
    public function key(): string   { return 'mastodon'; }
    public function label(): string { return 'Mastodon'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_mastodon_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        return $this->instance() !== null && $this->setting('mastodon_access_token') !== null;
    }

    protected function send(News $article, int $rowId): array
    {
        $url    = $this->articleUrl($article);
        $title  = $this->articleTitle($article);
        $status = $this->fitWithTail($title, $url, 500);

        $response = Http::withToken($this->setting('mastodon_access_token'))
            ->timeout(15)
            ->acceptJson()
            ->post($this->instance().'/api/v1/statuses', [
                'status'     => $status,
                'visibility' => 'public',
            ]);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 800));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $this->markSent($rowId, $body['url'] ?? null, $body);
        return ['ok' => true, 'post_url' => $body['url'] ?? null];
    }

    private function instance(): ?string
    {
        $val = $this->setting('mastodon_instance');
        return $val ? rtrim($val, '/') : null;
    }
}
