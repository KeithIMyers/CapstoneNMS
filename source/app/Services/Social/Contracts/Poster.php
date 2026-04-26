<?php

namespace App\Services\Social\Contracts;

use App\Models\News;

/**
 * Contract for every social / messaging channel the publish observer
 * fans out to. Each implementation is responsible for credential
 * lookup (typically via getcong() against keys it owns) and for the
 * single-shot wire call. The shared idempotency + audit-row write
 * lives in AbstractPoster so concrete classes only have to think
 * about the API on the other end.
 */
interface Poster
{
    /** Stable channel key persisted to social_posts.channel. */
    public function key(): string;

    /** Human label for the admin UI. */
    public function label(): string;

    /** Whether the admin has supplied credentials for this channel. */
    public function isConfigured(): bool;

    /**
     * Whether this channel is currently enabled in site settings. A
     * configured-but-disabled channel won't fire on auto-publish but
     * remains visible in the admin so admins can flip it back on
     * without re-pasting credentials.
     */
    public function isEnabled(): bool;

    /**
     * Send the article to this channel.
     *
     * @return array{
     *   ok:         bool,
     *   reason?:    string,        // not_configured | already_posted | transport_error | api_error
     *   post_url?:  ?string,
     *   message?:   ?string,
     *   http_status?: ?int,
     * }
     */
    public function post(News $article): array;
}
