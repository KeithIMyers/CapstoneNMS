<?php

namespace App\Services\Social;

use App\Models\News;
use App\Services\Social\Contracts\Poster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared scaffolding for every social poster. Concrete classes
 * implement `key()`, `label()`, `isConfigured()`, `isEnabled()`, and
 * the HTTP call inside `send()`. This base owns:
 *
 *   - Idempotency check on (news_id, channel) so a republish or a
 *     stuck queue can't double-post.
 *   - Writing the social_posts audit row (queued → sent | error).
 *   - Sponsored-content opt-out (auto-post on sponsored articles is
 *     off; an editor can fan out manually).
 *   - Generic enabled/configured short-circuits.
 *
 * The `send()` template method receives the queued row id so a
 * concrete implementation can mark error / success without juggling
 * the SQL.
 */
abstract class AbstractPoster implements Poster
{
    public function post(News $article): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'reason' => 'channel_disabled'];
        }
        if (! $this->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        if (DB::table('social_posts')
                ->where('news_id', $article->id)
                ->where('channel', $this->key())
                ->exists()) {
            return ['ok' => false, 'reason' => 'already_posted'];
        }

        $rowId = DB::table('social_posts')->insertGetId([
            'news_id'    => $article->id,
            'channel'    => $this->key(),
            'status'     => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $result = $this->send($article, $rowId);
        } catch (\Throwable $e) {
            $this->markError($rowId, 'transport_error', $e->getMessage());
            Log::warning('Social poster threw', [
                'channel' => $this->key(),
                'news_id' => $article->id,
                'error'   => $e->getMessage(),
            ]);
            return ['ok' => false, 'reason' => 'transport_error', 'message' => $e->getMessage()];
        }

        return $result;
    }

    /**
     * Concrete API call. Must update the audit row to 'sent' (with
     * `markSent`) or 'error' (with `markError`) before returning.
     *
     * @return array{ok: bool, reason?: string, post_url?: ?string, message?: ?string, http_status?: ?int}
     */
    abstract protected function send(News $article, int $rowId): array;

    /** Plain-text article URL (canonical, locale-aware). */
    protected function articleUrl(News $article): string
    {
        return route('news.details', ['slug' => $article->slug]);
    }

    /** Cleaned-up plain title. */
    protected function articleTitle(News $article): string
    {
        return trim(strip_tags(stripslashes((string) $article->title)));
    }

    /**
     * Truncate a string to fit within `$max` characters, preserving the
     * trailing piece intact. Returns `[$truncatedHead, $tail]` joined
     * with `$glue`. Useful for fitting `<title>\n<url>` into a 280-char
     * tweet without breaking the URL.
     */
    protected function fitWithTail(string $head, string $tail, int $max, string $glue = "\n\n"): string
    {
        $glueLen = mb_strlen($glue);
        $tailLen = mb_strlen($tail);
        $budget  = $max - $tailLen - $glueLen;
        if ($budget < 1) {
            return mb_substr($tail, 0, $max);
        }
        if (mb_strlen($head) > $budget) {
            $head = mb_substr($head, 0, max(1, $budget - 1)).'…';
        }
        return $head.$glue.$tail;
    }

    protected function markSent(int $rowId, ?string $postUrl, mixed $response): void
    {
        DB::table('social_posts')->where('id', $rowId)->update([
            'status'     => 'sent',
            'post_url'   => $postUrl,
            'response'   => mb_substr(json_encode($response, JSON_UNESCAPED_SLASHES) ?: '', 0, 2000),
            'posted_at'  => now(),
            'updated_at' => now(),
        ]);
    }

    protected function markError(int $rowId, string $reason, string $detail): void
    {
        DB::table('social_posts')->where('id', $rowId)->update([
            'status'        => 'error',
            'error_message' => mb_substr($reason.': '.$detail, 0, 1000),
            'updated_at'    => now(),
        ]);
    }

    /** Read a per-channel setting via getcong(); empty string → null. */
    protected function setting(string $key): ?string
    {
        $val = function_exists('getcong') ? getcong($key) : null;
        $val = trim((string) $val);
        return $val === '' ? null : $val;
    }

    /** Generic boolean toggle: any of 1/true/yes/on counts as enabled. */
    protected function settingBool(string $key, bool $default = false): bool
    {
        $val = function_exists('getcong') ? getcong($key) : null;
        if ($val === null || $val === '') return $default;
        return in_array(strtolower((string) $val), ['1', 'true', 'on', 'yes'], true);
    }
}
