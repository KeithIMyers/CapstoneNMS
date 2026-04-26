<?php

namespace App\Services\Social;

use App\Models\News;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Bluesky AT Protocol poster. Configured via:
 *
 *   social_bluesky_enabled        toggle
 *   bluesky_handle                e.g. mysite.bsky.social
 *   bluesky_app_password          generated under Settings → App Passwords
 *   bluesky_pds_url               optional, defaults to https://bsky.social
 *
 * Two-step flow:
 *   POST /xrpc/com.atproto.server.createSession → access JWT + DID (cached 100m)
 *   POST /xrpc/com.atproto.repo.createRecord    → publishes the post
 *
 * The text uses Bluesky's facet annotations so the URL renders as a
 * clickable link and respects the 300-grapheme post limit.
 */
class BlueskyPoster extends AbstractPoster
{
    private const POST_LIMIT = 300;

    public function key(): string   { return 'bluesky'; }
    public function label(): string { return 'Bluesky'; }

    public function isEnabled(): bool
    {
        return $this->settingBool('social_bluesky_enabled', $this->isConfigured());
    }

    public function isConfigured(): bool
    {
        return $this->setting('bluesky_handle') !== null
            && $this->setting('bluesky_app_password') !== null;
    }

    protected function send(News $article, int $rowId): array
    {
        $session = $this->session();
        if (! $session) {
            $this->markError($rowId, 'auth_error', 'createSession failed');
            return ['ok' => false, 'reason' => 'api_error'];
        }

        $url   = $this->articleUrl($article);
        $title = $this->articleTitle($article);
        $text  = $this->fitWithTail($title, $url, self::POST_LIMIT);

        // Build a facet for the URL so it renders as a clickable link
        // rather than plain text. Bluesky uses byte offsets into UTF-8.
        $urlByteStart = strpos($text, $url);
        $facets = [];
        if ($urlByteStart !== false) {
            $facets[] = [
                'index'    => ['byteStart' => $urlByteStart, 'byteEnd' => $urlByteStart + strlen($url)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
            ];
        }

        $response = Http::withToken($session['accessJwt'])
            ->timeout(15)
            ->acceptJson()
            ->post($this->pds().'/xrpc/com.atproto.repo.createRecord', [
                'repo'       => $session['did'],
                'collection' => 'app.bsky.feed.post',
                'record'     => [
                    '$type'     => 'app.bsky.feed.post',
                    'text'      => $text,
                    'createdAt' => now()->toIso8601ZuluString(),
                    'facets'    => $facets,
                ],
            ]);

        if (! $response->successful()) {
            $this->markError($rowId, 'api_error', 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 800));
            return ['ok' => false, 'reason' => 'api_error', 'http_status' => $response->status()];
        }

        $body = $response->json();
        $postUrl = $this->postUrlFromUri($body['uri'] ?? null, $session['handle'] ?? $this->setting('bluesky_handle'));
        $this->markSent($rowId, $postUrl, $body);
        return ['ok' => true, 'post_url' => $postUrl];
    }

    /**
     * Cached session creation. Bluesky access JWTs last ~2h; we cache
     * for 100 minutes and re-auth on miss.
     *
     * @return array{accessJwt:string, refreshJwt:string, did:string, handle:string}|null
     */
    private function session(): ?array
    {
        $cacheKey = 'bsky_session:'.sha1($this->setting('bluesky_handle').':'.$this->setting('bluesky_app_password'));
        $cached = Cache::store('file')->get($cacheKey);
        if (is_array($cached) && isset($cached['accessJwt'])) return $cached;

        $r = Http::timeout(15)->acceptJson()
            ->post($this->pds().'/xrpc/com.atproto.server.createSession', [
                'identifier' => $this->setting('bluesky_handle'),
                'password'   => $this->setting('bluesky_app_password'),
            ]);
        if (! $r->successful()) return null;

        $body = $r->json();
        if (! isset($body['accessJwt'], $body['did'])) return null;

        Cache::store('file')->put($cacheKey, $body, now()->addMinutes(100));
        return $body;
    }

    private function pds(): string
    {
        $val = $this->setting('bluesky_pds_url');
        return $val ? rtrim($val, '/') : 'https://bsky.social';
    }

    /**
     * Convert an at:// URI (`at://{did}/app.bsky.feed.post/{rkey}`) to
     * the public bsky.app web URL: https://bsky.app/profile/{handle}/post/{rkey}.
     */
    private function postUrlFromUri(?string $uri, ?string $handle): ?string
    {
        if (! $uri || ! $handle) return null;
        if (! preg_match('~/app\.bsky\.feed\.post/([^/]+)$~', $uri, $m)) return null;
        return "https://bsky.app/profile/{$handle}/post/{$m[1]}";
    }
}
