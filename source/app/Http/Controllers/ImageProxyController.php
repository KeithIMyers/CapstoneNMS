<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reverse-proxy / cache for external image URLs referenced by article
 * fields (News::$image, Apple News export, etc.).
 *
 * Why route external URLs through here instead of putting them in
 * `<img src>` directly:
 *   1. Privacy — the third-party host would otherwise see every
 *      reader's IP + User-Agent.
 *   2. Mixed content — http:// URLs on an https:// page are blocked
 *      by browsers; rewriting through a same-origin endpoint dodges
 *      that without making editors hand-edit each URL.
 *   3. Availability — the upstream going down breaks the article;
 *      the local cache survives that.
 *
 * The URL is encrypted with APP_KEY via Crypt::encryptString — the
 * controller decrypts it back on the way in. That keeps the proxy
 * stateless (no lookup table) AND prevents the route from being
 * abused as a generic SSRF surface (you need APP_KEY to mint a token).
 *
 * The cache is keyed by sha256(decrypted_url) and lives under the
 * private `local` disk so direct guesses can't dump it. Only the
 * controller streams it out with a media content-type.
 */
class ImageProxyController extends Controller
{
    private const CACHE_DIR = 'image-proxy-cache';
    private const CACHE_TTL_SECONDS = 86_400 * 30; // 30 days
    private const MAX_BYTES = 6_000_000; // 6 MB hard ceiling per image
    private const TIMEOUT_SECONDS = 10;
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/avif', 'image/svg+xml',
    ];

    public function proxy(Request $request): Response
    {
        $token = (string) $request->query('t', '');
        if ($token === '') {
            abort(404);
        }

        try {
            $url = Crypt::decryptString($this->base64UrlDecode($token));
        } catch (\Throwable $e) {
            abort(404);
        }

        if (! preg_match('~^https?://~i', $url)) {
            abort(404);
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) abort(404);

        $cacheKey = hash('sha256', $url);
        $cachePath = self::CACHE_DIR.'/'.$cacheKey;
        $metaPath  = $cachePath.'.json';
        $disk = Storage::disk('local');

        // Serve from cache when fresh.
        if ($disk->exists($cachePath) && $disk->exists($metaPath)) {
            $meta = json_decode((string) $disk->get($metaPath), true);
            $cachedAt = (int) ($meta['cached_at'] ?? 0);
            if (is_array($meta) && $cachedAt > 0
                && time() - $cachedAt < self::CACHE_TTL_SECONDS) {
                return $this->serveFromCache($disk, $cachePath, $meta);
            }
        }

        // Cache miss / expired: fetch with SSRF + size guards.
        [$internal, $pinnedIp, $port] = $this->resolveAndCheck($url, $host);
        if ($internal || ! $pinnedIp) {
            abort(502);
        }

        try {
            $resp = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'CapstoneNMS-ImageProxy/1.0'])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        \CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinnedIp],
                    ],
                ])
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('ImageProxy fetch failed', ['url' => $url, 'error' => $e->getMessage()]);
            abort(502);
        }

        if (! $resp->successful()) {
            abort(502);
        }

        $contentType = strtolower(trim((string) ($resp->header('Content-Type') ?: 'application/octet-stream')));
        // Strip any "; charset=..." suffix.
        $contentType = trim(explode(';', $contentType)[0]);
        if (! in_array($contentType, self::ALLOWED_MIMES, true)) {
            abort(415);
        }

        $body = (string) $resp->body();
        if (strlen($body) === 0 || strlen($body) > self::MAX_BYTES) {
            abort(502);
        }

        // SVG is XSS-able; strip any <script> + on* handlers before
        // caching. Editors who want to embed an interactive SVG can
        // upload it to Filament where the policy is reviewed.
        if ($contentType === 'image/svg+xml') {
            $body = $this->sanitizeSvg($body);
        }

        $meta = [
            'content_type' => $contentType,
            'bytes'        => strlen($body),
            'cached_at'    => time(),
        ];

        $disk->put($cachePath, $body);
        $disk->put($metaPath, json_encode($meta));

        return $this->serveBody($body, $meta);
    }

    private function serveFromCache(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, array $meta): Response
    {
        return $this->serveBody((string) $disk->get($path), $meta);
    }

    private function serveBody(string $body, array $meta): Response
    {
        $contentType = (string) ($meta['content_type'] ?? 'application/octet-stream');
        return response($body, 200, [
            'Content-Type'           => $contentType,
            // Cache aggressively at the browser + CDN edge — the
            // upstream URL is encoded into the path, so a content
            // change is a different URL.
            'Cache-Control'          => 'public, max-age='.self::CACHE_TTL_SECONDS.', immutable',
            'X-Content-Type-Options' => 'nosniff',
            // Force the browser to render the image inline rather
            // than treat it as a download — and never let the page
            // it landed in execute it as script.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'",
        ]);
    }

    /**
     * Mirror of FetchUrlTool::resolveAndCheck — refuses any host that
     * resolves to a private / loopback / link-local / cloud-metadata
     * IP, and pins the public IP through CURLOPT_RESOLVE so a hostile
     * TTL=0 record can't rebind between check and fetch.
     *
     * @return array{0:bool, 1:?string, 2:int}
     */
    private function resolveAndCheck(string $url, string $host): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        $host = strtolower($host);
        if (in_array($host, ['localhost', '0.0.0.0'], true)) {
            return [true, null, $port];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ok = (bool) filter_var(
                $host, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            return [! $ok, $ok ? $host : null, $port];
        }

        $records = @gethostbynamel($host) ?: [];
        if (empty($records)) return [true, null, $port];

        $publicIps = [];
        foreach ($records as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return [true, null, $port];
            }
            $publicIps[] = $ip;
        }

        return [false, $publicIps[0], $port];
    }

    private function sanitizeSvg(string $svg): string
    {
        // Cheap defang: drop <script> blocks and any on*= handlers.
        // A properly-malicious SVG with foreignObject / <use> tricks
        // would still be safer to reject, but sanitizing here is
        // strictly an improvement over passing the bytes through.
        $svg = preg_replace('~<script\b[^>]*>.*?</script\s*>~is', '', $svg) ?? $svg;
        $svg = preg_replace('~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $svg) ?? $svg;
        $svg = preg_replace('~href\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')~i', 'href="#"', $svg) ?? $svg;
        return $svg;
    }

    private function base64UrlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
