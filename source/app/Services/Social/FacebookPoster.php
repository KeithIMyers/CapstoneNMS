<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * Facebook Graph API page poster. Posts a link + message to a Page
 * timeline using a long-lived Page Access Token. Settings:
 *
 *   social_facebook_enabled       toggle
 *   facebook_page_id              numeric Page id
 *   facebook_page_access_token    long-lived token w/ pages_manage_posts
 *
 * NOTE: Facebook deprecated user-timeline auto-posts in 2018;
 * Page-only is the supported path.
 */
class FacebookPoster extends AbstractPoster
{
    private const API_BASE = 'https://graph.facebook.com/v19.0';

    public function key(): string   { return 'facebook'; }
    public function label(): string { return 'Facebook page'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_facebook_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        return $this->setting('facebook_page_id') !== null
            && $this->setting('facebook_page_access_token') !== null;
    }

    protected function send(News $article, int $rowId): array
    {
        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);

        $pageId = (string) $this->setting('facebook_page_id');
        $token  = (string) $this->setting('facebook_page_access_token');

        $response = Http::timeout(20)->acceptJson()
            ->post(self::API_BASE."/{$pageId}/feed", [
                'message'      => $title,
                'link'         => $url,
                'access_token' => $token,
            ]);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 600));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $postId = $body['id'] ?? null; // shape: "{pageId}_{postId}"
        $postUrl = null;
        if ($postId && str_contains($postId, '_')) {
            [$_, $pid] = explode('_', $postId, 2);
            $postUrl = "https://www.facebook.com/{$pageId}/posts/{$pid}";
        }
        $this->markSent($rowId, $postUrl, $body);
        return ['ok' => true, 'post_url' => $postUrl];
    }
}
