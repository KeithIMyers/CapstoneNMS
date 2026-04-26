<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * LinkedIn UGC Posts API poster. Settings:
 *
 *   social_linkedin_enabled       toggle
 *   linkedin_access_token         OAuth 2 token (w_member_social or
 *                                 w_organization_social scope)
 *   linkedin_author_urn           urn:li:person:{id} for personal page,
 *                                 urn:li:organization:{id} for company page
 *
 * Posts a UGC text+article share via /v2/ugcPosts. Tokens are issued
 * via LinkedIn's OAuth 2 flow elsewhere — this poster assumes the
 * admin has already pasted a long-lived token.
 */
class LinkedInPoster extends AbstractPoster
{
    private const POST_LIMIT = 3000;

    public function key(): string   { return 'linkedin'; }
    public function label(): string { return 'LinkedIn'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_linkedin_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        $urn = $this->setting('linkedin_author_urn');
        return $this->setting('linkedin_access_token') !== null
            && $urn !== null
            && (str_starts_with($urn, 'urn:li:person:') || str_starts_with($urn, 'urn:li:organization:'));
    }

    protected function send(News $article, int $rowId): array
    {
        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);
        $text  = $this->fitWithTail($title, $url, self::POST_LIMIT);

        $authorUrn = (string) $this->setting('linkedin_author_urn');

        $payload = [
            'author'         => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary'    => ['text' => $text],
                    'shareMediaCategory' => 'ARTICLE',
                    'media'              => [[
                        'status'      => 'READY',
                        'originalUrl' => $url,
                        'title'       => ['text' => mb_substr($title, 0, 200)],
                    ]],
                ],
            ],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ];

        $response = Http::withToken((string) $this->setting('linkedin_access_token'))
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'Content-Type'              => 'application/json',
            ])
            ->timeout(20)
            ->post('https://api.linkedin.com/v2/ugcPosts', $payload);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 800));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $shareId = $body['id'] ?? $response->header('X-RestLi-Id');
        $postUrl = $shareId ? "https://www.linkedin.com/feed/update/{$shareId}" : null;
        $this->markSent($rowId, $postUrl, $body);
        return ['ok' => true, 'post_url' => $postUrl];
    }
}
