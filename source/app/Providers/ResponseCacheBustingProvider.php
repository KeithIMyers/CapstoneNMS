<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\News;
use App\Models\Pages;
use App\Models\PodcastShow;
use App\Models\Settings;
use App\Models\Topic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Wipe the public-page response cache whenever a model that influences
 * what guests see changes. The middleware uses the file cache store; we
 * flush it on relevant saved / deleted events.
 *
 * The TTL is short enough (5 minutes) that even without invalidation
 * the site self-heals — but immediate invalidation feels right when an
 * editor publishes or unpublishes.
 */
class ResponseCacheBustingProvider extends ServiceProvider
{
    public function boot(): void
    {
        $invalidate = function () {
            try {
                Cache::store('file')->flush();
            } catch (\Throwable $e) {
                // Don't fail the save just because cache flush failed.
            }
        };

        foreach ([News::class, Category::class, Pages::class, Settings::class, Topic::class, PodcastShow::class] as $model) {
            $model::saved($invalidate);
            $model::deleted($invalidate);
        }
    }
}
