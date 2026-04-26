<?php

namespace App\Services\Apple;

use App\Models\News;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Apple News Publisher REST client. Auth is HMAC-SHA256 over the
 * canonical request: METHOD + url + ISO-8601-date + content-type
 * + body. Apple expects credentials issued from News Publisher →
 * "API Channels".
 *
 * Settings (Site Settings → Apple News tab; env fallbacks too):
 *   apple_news_channel_id   the channel uuid
 *   apple_news_api_key_id   key identifier
 *   apple_news_api_secret   base64-encoded shared secret
 *
 * Operations: post(article) creates an article; delete($appleId)
 * removes one. Returns the raw HTTP Response so callers can
 * inspect status/body.
 */
class AppleNewsClient
{
    private const BASE_URL = 'https://news-api.apple.com';

    public function isConfigured(): bool
    {
        return $this->channelId() && $this->apiKeyId() && $this->apiSecret();
    }

    public function post(News $article): ?Response
    {
        if (! $this->isConfigured()) return null;

        $anf = (new AppleNewsFormatGenerator())->generate($article);
        $payload = $this->multipartPayload($anf);

        $url = self::BASE_URL.'/channels/'.$this->channelId().'/articles';
        $contentType = 'multipart/form-data; boundary='.$payload['boundary'];

        $response = Http::withHeaders([
                'Authorization' => $this->buildAuthHeader('POST', $url, $contentType, $payload['body']),
                'Content-Type'  => $contentType,
                'Accept'        => 'application/json',
            ])
            ->timeout(30)
            ->withBody($payload['body'], $contentType)
            ->post($url);

        if (! $response->successful()) {
            Log::warning('Apple News post failed', [
                'news_id' => $article->id,
                'status'  => $response->status(),
                'body'    => mb_substr($response->body(), 0, 800),
            ]);
        }

        return $response;
    }

    /**
     * Build the canonical-request HMAC for Apple's auth scheme.
     * https://developer.apple.com/documentation/apple_news/apple_news_api/about_the_apple_news_api/security_and_authentication_for_the_apple_news_api
     */
    private function buildAuthHeader(string $method, string $url, string $contentType, string $body): string
    {
        $date = gmdate('Y-m-d\TH:i:s\Z');
        $canonical = $method.$url.$date.$contentType.$body;
        $secret = base64_decode((string) $this->apiSecret());
        $signature = base64_encode(hash_hmac('sha256', $canonical, $secret, true));
        return sprintf('HHMAC; key=%s; signature=%s; date=%s', $this->apiKeyId(), $signature, $date);
    }

    /**
     * Apple News expects multipart/form-data with one part for the
     * article.json and additional parts for any embedded asset
     * URLs. Image asset uploads are out of scope for v1 (we send
     * only canonical URLs in the ANF).
     *
     * @return array{boundary:string, body:string}
     */
    private function multipartPayload(array $anf): array
    {
        $boundary = 'usnt-'.bin2hex(random_bytes(8));
        $json = json_encode($anf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json\r\n";
        $body .= "Content-Disposition: form-data; filename=article.json; name=article.json; size=".strlen($json)."\r\n\r\n";
        $body .= $json."\r\n";
        $body .= "--{$boundary}--\r\n";

        return ['boundary' => $boundary, 'body' => $body];
    }

    private function channelId(): ?string
    {
        return $this->setting('apple_news_channel_id');
    }

    private function apiKeyId(): ?string
    {
        return $this->setting('apple_news_api_key_id');
    }

    private function apiSecret(): ?string
    {
        return $this->setting('apple_news_api_secret');
    }

    private function setting(string $key): ?string
    {
        $val = function_exists('getcong') ? getcong($key) : null;
        $val = trim((string) $val);
        if ($val === '') {
            $val = (string) env(strtoupper($key));
        }
        return $val === '' ? null : $val;
    }
}
