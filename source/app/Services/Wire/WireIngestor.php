<?php

namespace App\Services\Wire;

use App\Models\Category;
use App\Models\News;
use App\Models\WireSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pulls RSS / Atom feeds defined in wire_sources and lands each new
 * item as a draft News row. Idempotent on item GUID so re-running
 * doesn't fan out duplicates.
 *
 * The ingestor is intentionally dumb about copyright — items land as
 * drafts attributing the original publisher in image_credit + a body
 * footer, and the editor is expected to either rewrite them via the
 * compose agent (when ai_rewrite=true on the source) or shape the
 * draft by hand before publishing. We never auto-publish.
 *
 * Feed support: standard RSS 2.0 + Atom 1.0. SimplePie would add
 * niceness but adds a dependency; the SimpleXMLElement parser below
 * handles both shapes well enough for the common case.
 */
class WireIngestor
{
    /**
     * Walk every active source and ingest new items. Returns a stats
     * array per source: ['source_id' => N, 'name' => '…', 'fetched' =>
     * int, 'created' => int, 'errors' => int].
     */
    public function ingestAll(): array
    {
        $stats = [];
        foreach (WireSource::active()->get() as $source) {
            $stats[] = $this->ingestOne($source) + ['source_id' => $source->id, 'name' => $source->name];
        }
        return $stats;
    }

    public function ingestOne(WireSource $source): array
    {
        $stats = ['fetched' => 0, 'created' => 0, 'errors' => 0];

        // Refuse to fetch feeds whose host resolves to internal/private
        // ranges, and disable redirect-following so an attacker who
        // controls a public feed URL can't pivot us into AWS metadata
        // (169.254.169.254), localhost, or a RFC1918 host. If the
        // upstream redirects, log it and skip — let the editor update
        // feed_url to the new canonical location.
        $feedHost = (string) (parse_url($source->feed_url, PHP_URL_HOST) ?: '');
        if ($feedHost === '' || $this->hostIsInternal($feedHost)) {
            Log::warning('Wire source refused (internal/invalid host)', [
                'source_id' => $source->id,
                'host' => $feedHost,
            ]);
            $stats['errors']++;
            return $stats;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'CapstoneNMS-WireBot/1.0'])
                ->withOptions(['allow_redirects' => false])
                ->get($source->feed_url);
            $status = $response->status();
            if ($status >= 300 && $status < 400) {
                Log::warning('Wire source returned redirect; not following', [
                    'source_id' => $source->id,
                    'status' => $status,
                    'location' => $response->header('Location'),
                ]);
                $stats['errors']++;
                return $stats;
            }
            if (! $response->successful()) {
                Log::warning('Wire source fetch failed', [
                    'source_id' => $source->id,
                    'status' => $status,
                ]);
                $stats['errors']++;
                return $stats;
            }
        } catch (\Throwable $e) {
            Log::warning('Wire source threw', ['source_id' => $source->id, 'error' => $e->getMessage()]);
            $stats['errors']++;
            return $stats;
        }

        $items = $this->parseFeed($response->body());
        $stats['fetched'] = count($items);

        $items = array_slice($items, 0, $source->limit_per_run);

        foreach ($items as $item) {
            $guid = (string) ($item['guid'] ?? $item['link'] ?? '');
            if ($guid === '') continue;

            // Skip if we've already ingested this item for this source.
            $alreadySeen = DB::table('wire_items_seen')
                ->where('source_id', $source->id)
                ->where('guid', $guid)
                ->exists();
            if ($alreadySeen) continue;

            try {
                $news = $this->draftFromItem($source, $item);
                DB::table('wire_items_seen')->insert([
                    'source_id' => $source->id,
                    'guid'      => $guid,
                    'news_id'   => $news->id,
                    'seen_at'   => now(),
                ]);
                $stats['created']++;
            } catch (\Throwable $e) {
                Log::warning('Wire ingest item failed', [
                    'source_id' => $source->id,
                    'guid' => $guid,
                    'error' => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }

        $source->update(['last_fetched_at' => now()]);

        return $stats;
    }

    /**
     * Build a draft News from a parsed feed item.
     */
    private function draftFromItem(WireSource $source, array $item): News
    {
        $title   = trim((string) ($item['title']   ?? 'Untitled'));
        $body    = trim((string) ($item['content'] ?? $item['description'] ?? ''));
        $excerpt = trim((string) ($item['description'] ?? ''));
        if ($excerpt === '' && $body !== '') {
            $excerpt = Str::limit(strip_tags($body), 280);
        }

        $categoryId = $source->default_category_id
            ?: Category::query()->orderBy('id')->value('id');

        // ai_rewrite is best-effort: if it fails, fall back to the raw
        // feed text rather than dropping the ingest.
        if ($source->ai_rewrite && $body !== '') {
            try {
                $assistant = app(\App\Services\Ai\Assistant::class);
                $resp = $assistant->run(
                    \App\Services\Ai\Assistant::KEY_COPYEDIT,
                    "Source: {$source->name}\n\nTitle: {$title}\n\nBody:\n".strip_tags($body),
                    ['max_tokens' => 2048],
                );
                $rewritten = trim($resp->text);
                if ($rewritten !== '') $body = $rewritten;
            } catch (\Throwable $e) {
                // Fall through with original body.
            }
        }

        // Add an attribution footer so the source is acknowledged in
        // the draft itself — editors can keep it, rewrite it, or remove
        // it during their pass. Both the URL and the source name go
        // through htmlspecialchars so a hostile feed can't break out of
        // the href or inject script via the visible label, and we only
        // accept http(s) schemes (no javascript:, data:, etc).
        $sourceLink = trim((string) ($item['link'] ?? ''));
        if ($sourceLink !== '' && preg_match('~^https?://~i', $sourceLink)) {
            $safeHref  = htmlspecialchars($sourceLink, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars((string) $source->name, ENT_QUOTES, 'UTF-8');
            $body .= "\n\n<p><em>Source: <a href=\"{$safeHref}\" rel=\"noopener nofollow\" target=\"_blank\">{$safeLabel}</a></em></p>";
        }

        return News::create([
            'title'            => Str::limit($title, 250),
            'slug'             => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'excerpt'          => Str::limit($excerpt, 800),
            'content'          => $body,
            'category_id'      => $categoryId,
            'editorial_status' => News::STATUS_DRAFT,
            'user_id'          => $source->default_author_id ?: 1,
        ]);
    }

    /**
     * Resolve the host (including hostname → IP lookup) and reject
     * anything in a private, loopback, link-local, or reserved range.
     * Mirrors FetchUrlTool::isInternal so wire ingestion has the same
     * SSRF posture as agent-driven fetches.
     */
    private function hostIsInternal(string $host): bool
    {
        $host = strtolower($host);
        if (in_array($host, ['localhost', '0.0.0.0'], true)) return true;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        $records = @gethostbynamel($host) ?: [];
        foreach ($records as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseFeed(string $xml): array
    {
        $items = [];
        libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET refuses to fetch external entities over the
            // network (XXE-via-SYSTEM); LIBXML_NOENT is intentionally
            // NOT set so character entities still expand normally
            // without the parser substituting external resources.
            // Belt-and-braces: PHP 8+ already disables external entity
            // loading by default in libxml, but pinning the flags here
            // protects against that default ever changing again.
            $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            if (! $doc) return [];

            // RSS 2.0
            if (isset($doc->channel->item)) {
                foreach ($doc->channel->item as $node) {
                    $items[] = [
                        'guid'        => (string) ($node->guid ?? $node->link ?? ''),
                        'link'        => (string) ($node->link ?? ''),
                        'title'       => (string) ($node->title ?? ''),
                        'description' => (string) ($node->description ?? ''),
                        'content'     => (string) ($node->children('content', true)->encoded ?? $node->description ?? ''),
                    ];
                }
                return $items;
            }

            // Atom 1.0
            if (isset($doc->entry)) {
                foreach ($doc->entry as $entry) {
                    $link = '';
                    foreach ($entry->link as $l) {
                        if (! isset($l['rel']) || (string) $l['rel'] === 'alternate') {
                            $link = (string) ($l['href'] ?? '');
                            break;
                        }
                    }
                    $items[] = [
                        'guid'        => (string) ($entry->id ?? $link ?? ''),
                        'link'        => $link,
                        'title'       => (string) ($entry->title ?? ''),
                        'description' => (string) ($entry->summary ?? ''),
                        'content'     => (string) ($entry->content ?? $entry->summary ?? ''),
                    ];
                }
                return $items;
            }
        } catch (\Throwable $e) {
            Log::warning('Wire feed parse failed', ['error' => $e->getMessage()]);
        }
        libxml_clear_errors();
        return $items;
    }
}
