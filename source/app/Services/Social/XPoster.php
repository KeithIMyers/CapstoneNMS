<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Http;

/**
 * X (Twitter) v2 poster using OAuth 1.0a app-user credentials. The
 * Twitter v2 API still accepts OAuth 1.0a for POST /2/tweets, which
 * is the simplest path for a single first-party publishing account
 * (no user redirect dance required). Settings:
 *
 *   social_x_enabled              toggle
 *   x_api_key                     consumer key
 *   x_api_secret                  consumer secret
 *   x_access_token                user-context access token
 *   x_access_secret               user-context access secret
 *
 * 280-char limit; the article URL counts as 23 chars per t.co rules,
 * so we budget against that rather than the literal URL length.
 */
class XPoster extends AbstractPoster
{
    private const TWEET_LIMIT = 280;
    private const TCO_URL_LEN = 23;

    public function key(): string   { return 'x'; }
    public function label(): string { return 'X (Twitter)'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_x_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        return $this->setting('x_api_key') !== null
            && $this->setting('x_api_secret') !== null
            && $this->setting('x_access_token') !== null
            && $this->setting('x_access_secret') !== null;
    }

    protected function send(News $article, int $rowId): array
    {
        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);
        // Twitter rewrites every URL to a 23-char t.co link, so size
        // the title as if the URL were 23 chars even when it's longer.
        $effectiveLimit = self::TWEET_LIMIT - self::TCO_URL_LEN - 2; // 2 for "\n\n"
        if (mb_strlen($title) > $effectiveLimit) {
            $title = mb_substr($title, 0, max(1, $effectiveLimit - 1)).'…';
        }
        $text = $title."\n\n".$url;

        $endpoint = 'https://api.twitter.com/2/tweets';
        $authHeader = $this->buildOAuth1Header('POST', $endpoint, []);

        $response = Http::withHeaders([
                'Authorization' => $authHeader,
                'Content-Type'  => 'application/json',
            ])
            ->timeout(15)
            ->post($endpoint, ['text' => $text]);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 800));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $tweetId = $body['data']['id'] ?? null;
        $postUrl = $tweetId ? "https://x.com/i/web/status/{$tweetId}" : null;
        $this->markSent($rowId, $postUrl, $body);
        return ['ok' => true, 'post_url' => $postUrl];
    }

    /**
     * Build an OAuth 1.0a Authorization header. We sign only the URL
     * + query params (no body params, since the tweet text goes in
     * the JSON body — Twitter's spec treats JSON bodies as not part
     * of the signature base string, unlike form-encoded bodies).
     *
     * @param array<string, string> $params  query-string params (none for /2/tweets)
     */
    private function buildOAuth1Header(string $method, string $url, array $params): string
    {
        $oauth = [
            'oauth_consumer_key'     => $this->setting('x_api_key'),
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $this->setting('x_access_token'),
            'oauth_version'          => '1.0',
        ];

        $signing = array_merge($params, $oauth);
        ksort($signing);
        $paramStr = implode('&', array_map(
            fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v),
            array_keys($signing),
            array_values($signing),
        ));

        $base = strtoupper($method).'&'.rawurlencode($url).'&'.rawurlencode($paramStr);
        $key  = rawurlencode((string) $this->setting('x_api_secret')).'&'.rawurlencode((string) $this->setting('x_access_secret'));
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        ksort($oauth);
        $parts = [];
        foreach ($oauth as $k => $v) {
            $parts[] = rawurlencode($k).'="'.rawurlencode($v).'"';
        }
        return 'OAuth '.implode(', ', $parts);
    }
}
