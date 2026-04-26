<?php

namespace App\Services\Ai\Tools;

use Illuminate\Support\Facades\Http;

/**
 * Fetch an external HTTP(S) URL and return readable text. Strict
 * safety: only http and https schemes; no internal IPs (RFC 1918,
 * loopback, link-local); 10-second timeout; 200 KB body cap. Strips
 * all HTML so the model sees content, not markup.
 *
 * Note: this is intentionally conservative. Editors who need to fetch
 * private endpoints should expose them through a dedicated tool with a
 * narrower contract — not by relaxing this one.
 */
class FetchUrlTool implements Tool
{
    private const TIMEOUT_SECONDS = 10;
    private const BODY_BYTE_LIMIT = 200_000;

    public function key(): string { return 'fetch_url'; }

    public function description(): string
    {
        return 'GET an external URL (http or https). Returns the page title and a plain-text excerpt up to 200 KB. Refuses internal addresses.';
    }

    public function arguments(): array
    {
        return [
            'url' => 'string — full http(s) URL to fetch',
        ];
    }

    public function execute(array $args): string
    {
        $url = (string) ($args['url'] ?? '');
        if (! preg_match('~^https?://~i', $url)) {
            return 'ERROR: url must start with http:// or https://';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return 'ERROR: refusing to fetch internal or invalid host';
        }

        // Resolve the host once and validate every A/AAAA record up
        // front. If we let Guzzle re-resolve at fetch time, hostile
        // DNS with a 0-second TTL could pass the isInternal() check on
        // the first lookup, then return 127.0.0.1 / 169.254.169.254
        // when curl actually opens the socket. Pin the resolved IP
        // through curl's CURLOPT_RESOLVE so the host header stays
        // intact (TLS SNI, virtual hosting) but the network endpoint
        // is locked to a public IP we already vetted.
        [$internal, $pinnedIp, $port] = $this->resolveAndCheck($url, $host);
        if ($internal) {
            return 'ERROR: refusing to fetch internal or invalid host';
        }
        if (! $pinnedIp) {
            return 'ERROR: could not resolve host';
        }

        try {
            // Disable redirect following: Guzzle's default would silently
            // follow a 3xx to an internal IP / cloud-metadata endpoint
            // that bypasses our isInternal() check on the original host.
            // If the upstream redirects, we surface the Location and let
            // the model decide to re-call this tool with the new URL —
            // which gets revalidated through isInternal() from scratch.
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'CapstoneNMS-AgentBot/1.0'])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        // Pin DNS: format is "HOST:PORT:IP[,IP]"
                        CURLOPT_RESOLVE => [$host.':'.$port.':'.$pinnedIp],
                    ],
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return 'ERROR: '.\Illuminate\Support\Str::limit($e->getMessage(), 240);
        }

        $status = $response->status();
        if ($status >= 300 && $status < 400) {
            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                return "ERROR: HTTP {$status} from {$host} with no Location header";
            }
            return "REDIRECT: HTTP {$status} from {$host} → {$location}. Re-call fetch_url with the new URL only if you need that target; it will be validated for internal-host safety.";
        }

        if (! $response->successful()) {
            return "ERROR: HTTP {$status} from {$host}";
        }

        $body = $response->body();
        if (strlen($body) > self::BODY_BYTE_LIMIT) {
            $body = substr($body, 0, self::BODY_BYTE_LIMIT);
        }

        $title = '';
        if (preg_match('~<title[^>]*>([^<]+)</title>~i', $body, $m)) {
            $title = trim(html_entity_decode($m[1]));
        }
        $text = trim(strip_tags($body));
        // Collapse runs of whitespace so the observation isn't half newlines.
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = mb_substr($text, 0, 6000);

        return "URL: {$url}\nTitle: {$title}\n\n{$text}";
    }

    /**
     * Resolve the URL's host and validate every record. Returns:
     *   [internal:bool, pinnedIp:?string, port:int]
     *
     * `internal=true` when the host is invalid OR any resolved IP is
     * in a blocked range. We refuse the call rather than picking the
     * "good" record because a DNS provider returning mixed records is
     * a strong signal of rebinding setup or misconfiguration.
     *
     * @return array{0: bool, 1: ?string, 2: int}
     */
    private function resolveAndCheck(string $url, string $host): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        $host = strtolower($host);
        if (in_array($host, ['localhost', '0.0.0.0'], true)) {
            return [true, null, $port];
        }

        // IP literal: validate range directly, no DNS to pin.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ok = (bool) filter_var(
                $host, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );
            return [! $ok, $ok ? $host : null, $port];
        }

        $records = @gethostbynamel($host) ?: [];
        if (empty($records)) {
            \Illuminate\Support\Facades\Log::warning('FetchUrlTool: DNS resolution failed', [
                'host' => $host,
            ]);
            return [true, null, $port];
        }

        $publicIps = [];
        foreach ($records as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                // Any private / reserved address among the records is
                // disqualifying — refuse the whole fetch.
                return [true, null, $port];
            }
            $publicIps[] = $ip;
        }

        return [false, $publicIps[0], $port];
    }

    private function isInternal(string $host): bool
    {
        // Block hostnames that resolve to internal ranges. Accept
        // numeric IPs only after explicit allow-list checks.
        $host = strtolower($host);
        if (in_array($host, ['localhost', '0.0.0.0'], true)) return true;

        // If it's an IP literal, check ranges directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Hostname: resolve and check each A record. Best-effort; if
        // gethostbynamel fails we err on the side of allowing (most
        // public endpoints work).
        $records = @gethostbynamel($host) ?: [];
        foreach ($records as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }
        return false;
    }
}
