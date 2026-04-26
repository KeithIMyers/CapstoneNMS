<?php

namespace App\Services\Social;

use App\Models\News;
use App\Services\Social\Contracts\Poster;
use Illuminate\Support\Facades\Log;

/**
 * Single fan-out point for every social channel. Concrete posters
 * live in this namespace and are wired up below — adding a new
 * channel only requires registering its class here. Each call to
 * `dispatch` walks every registered poster and invokes it; per-
 * channel failures are isolated so a misconfigured Threads token
 * doesn't block Bluesky.
 */
class PosterRegistry
{
    /**
     * Ordered list of poster class names. Order is presentation-only
     * (mirrors the SiteSettings tab) — actual fan-out is independent.
     *
     * @var array<int, class-string<Poster>>
     */
    private const CHANNELS = [
        MastodonPoster::class,
        BlueskyPoster::class,
        XPoster::class,
        ThreadsPoster::class,
        LinkedInPoster::class,
        FacebookPoster::class,
        SlackPoster::class,
        DiscordPoster::class,
        TelegramPoster::class,
    ];

    /** @return array<int, Poster> */
    public function all(): array
    {
        return array_map(fn ($c) => app($c), self::CHANNELS);
    }

    /**
     * Dispatch a freshly-published article to every channel that's
     * both configured and enabled. Returns a per-channel result map
     * for the activity log / debugging.
     *
     * @return array<string, array<string, mixed>>
     */
    public function dispatch(News $article): array
    {
        $results = [];
        foreach ($this->all() as $poster) {
            $key = $poster->key();
            if (! $poster->isEnabled() || ! $poster->isConfigured()) {
                $results[$key] = ['ok' => false, 'reason' => 'channel_disabled'];
                continue;
            }
            try {
                $results[$key] = $poster->post($article);
            } catch (\Throwable $e) {
                Log::warning('Social poster threw at dispatch', [
                    'channel' => $key,
                    'news_id' => $article->id,
                    'error'   => $e->getMessage(),
                ]);
                $results[$key] = ['ok' => false, 'reason' => 'transport_error', 'message' => $e->getMessage()];
            }
        }
        return $results;
    }
}
