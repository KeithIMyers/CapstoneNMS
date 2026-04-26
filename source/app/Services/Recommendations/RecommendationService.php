<?php

namespace App\Services\Recommendations;

use App\Models\News;
use App\Models\ReadingHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Behavioral-affinity recommendations for logged-in readers and a
 * sensible fallback for everyone else. Pure-PHP scoring; no
 * dependency on a vector database or external ML service.
 *
 * Algorithm:
 *   1. Build the reader's affinity profile from their last 60 days
 *      of reading_history: tally category and tag occurrences,
 *      weighted by recency (newer reads count more).
 *   2. Score every published article from the last 30 days by:
 *      (a) category match × 3.0
 *      (b) tag-overlap count × 1.5 each
 *      (c) recency boost: log(age_hours + 2) inverted
 *      (d) popularity: log(1 + views) × 0.4
 *   3. Exclude articles already in the reader's history.
 *
 * Anonymous readers / readers with no history fall through to a
 * mixed "trending + recent" feed — same shape so the consuming
 * partial doesn't need a special case.
 *
 * Cache: 10 minutes per (user_id ?: 'anon', limit). The behavioral
 * profile changes slowly relative to the cost of recomputing it on
 * every page view.
 */
class RecommendationService
{
    private const CACHE_TTL_SECONDS = 600;
    private const HISTORY_DAYS      = 60;
    private const CANDIDATE_DAYS    = 30;

    /**
     * Personalized recommendations for a reader. Returns up to
     * `$limit` News rows ordered by computed affinity score.
     *
     * @return Collection<int, News>
     */
    public function forUser(?User $user, int $limit = 6): Collection
    {
        $cacheKey = 'reco:'.($user?->id ?: 'anon').":{$limit}";
        $ids = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user, $limit) {
            return $this->computeIds($user, $limit);
        });
        if (empty($ids)) return collect();

        $byId = News::published()
            ->whereIn('id', $ids)
            ->with(['category:id,name,slug', 'user:id,name,slug'])
            ->get()
            ->keyBy('id');

        // Preserve the score order from computeIds().
        return collect($ids)
            ->map(fn ($id) => $byId->get($id))
            ->filter()
            ->values();
    }

    /**
     * Same shape as `forUser` but explicitly scoped to "you might
     * also like this" beneath one open article. Excludes the source
     * article itself and prefers tag overlap over user history.
     *
     * @return Collection<int, News>
     */
    public function relatedTo(News $source, ?User $user, int $limit = 4): Collection
    {
        $tagIds = $source->tagsRelation()->pluck('tags.id')->all();
        $cacheKey = 'reco:related:'.$source->id.':'.($user?->id ?: 'anon').":{$limit}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($source, $user, $tagIds, $limit) {
            $candidates = News::published()
                ->where('id', '!=', $source->id)
                ->where('published_at', '>=', now()->subDays(90))
                ->limit(120)
                ->orderByDesc('published_at')
                ->with(['category:id,name,slug', 'user:id,name,slug'])
                ->get();

            $userTagWeights = $user ? $this->affinityProfile($user)['tags'] : [];
            $excludeIds = $user ? $this->recentlyReadIds($user) : [];

            $scored = $candidates
                ->reject(fn (News $a) => in_array($a->id, $excludeIds, true))
                ->map(function (News $a) use ($source, $tagIds, $userTagWeights) {
                    $score = 0.0;
                    if ($a->category_id && $a->category_id === $source->category_id) {
                        $score += 2.5;
                    }
                    $aTagIds = $a->tagsRelation->pluck('id')->all();
                    $overlap = count(array_intersect($aTagIds, $tagIds));
                    $score += $overlap * 1.5;
                    foreach ($aTagIds as $t) {
                        if (isset($userTagWeights[$t])) $score += $userTagWeights[$t] * 0.5;
                    }
                    $hours = max(1, (int) (now()->diffInHours($a->published_at)));
                    $score += 1 / log($hours + 2);
                    $score += log(1 + (int) $a->views) * 0.3;
                    $a->setAttribute('_reco_score', $score);
                    return $a;
                })
                ->sortByDesc('_reco_score')
                ->take($limit)
                ->values();

            return $scored;
        });
    }

    /** @return array<int, int> ranked article ids */
    private function computeIds(?User $user, int $limit): array
    {
        $candidates = News::published()
            ->where('published_at', '>=', now()->subDays(self::CANDIDATE_DAYS))
            ->limit(150)
            ->orderByDesc('published_at')
            ->with('tagsRelation:id')
            ->get();

        if ($candidates->isEmpty()) return [];

        $excludeIds = $user ? $this->recentlyReadIds($user) : [];

        // Anonymous / no-history fallback: mixed trending + recent.
        $profile = $user ? $this->affinityProfile($user) : null;
        if (! $profile || empty($profile['categories']) && empty($profile['tags'])) {
            return $candidates
                ->reject(fn ($a) => in_array($a->id, $excludeIds, true))
                ->sortByDesc(function (News $a) {
                    $hours = max(1, (int) (now()->diffInHours($a->published_at)));
                    return (1 / log($hours + 2)) + (log(1 + (int) $a->views) * 0.6);
                })
                ->take($limit)
                ->pluck('id')
                ->values()
                ->all();
        }

        $scored = $candidates
            ->reject(fn ($a) => in_array($a->id, $excludeIds, true))
            ->map(function (News $a) use ($profile) {
                $score = 0.0;
                if ($a->category_id && isset($profile['categories'][$a->category_id])) {
                    $score += $profile['categories'][$a->category_id] * 3.0;
                }
                foreach ($a->tagsRelation as $t) {
                    if (isset($profile['tags'][$t->id])) {
                        $score += $profile['tags'][$t->id] * 1.5;
                    }
                }
                $hours = max(1, (int) (now()->diffInHours($a->published_at)));
                $score += 1 / log($hours + 2);
                $score += log(1 + (int) $a->views) * 0.4;
                return ['id' => $a->id, 'score' => $score];
            })
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->pluck('id')->all();
    }

    /**
     * Tally category + tag occurrences in this user's last
     * HISTORY_DAYS of reads, weighted by recency. Returns
     * normalized weights in [0..1].
     *
     * @return array{categories: array<int, float>, tags: array<int, float>}
     */
    private function affinityProfile(User $user): array
    {
        $cutoff = now()->subDays(self::HISTORY_DAYS);
        $rows = ReadingHistory::query()
            ->where('user_id', $user->id)
            ->where('last_read_at', '>=', $cutoff)
            ->orderByDesc('last_read_at')
            ->limit(200)
            ->get(['news_id', 'last_read_at']);
        if ($rows->isEmpty()) return ['categories' => [], 'tags' => []];

        $newsIds = $rows->pluck('news_id')->all();
        $articles = News::query()
            ->whereIn('id', $newsIds)
            ->with('tagsRelation:id')
            ->get(['id', 'category_id'])
            ->keyBy('id');

        $cats = [];
        $tags = [];
        $now  = now();
        foreach ($rows as $r) {
            $a = $articles->get($r->news_id);
            if (! $a) continue;
            // Recency weight: same-day = 1.0, decays linearly across the window.
            $ageDays = max(0, (int) $now->diffInDays($r->last_read_at));
            $w = max(0.05, 1.0 - ($ageDays / self::HISTORY_DAYS));
            if ($a->category_id) {
                $cats[$a->category_id] = ($cats[$a->category_id] ?? 0) + $w;
            }
            foreach ($a->tagsRelation as $t) {
                $tags[$t->id] = ($tags[$t->id] ?? 0) + $w;
            }
        }

        return ['categories' => $this->normalize($cats), 'tags' => $this->normalize($tags)];
    }

    /** @return array<int, int> news ids the user has read recently */
    private function recentlyReadIds(User $user): array
    {
        return DB::table('reading_history')
            ->where('user_id', $user->id)
            ->where('last_read_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->pluck('news_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @param  array<int|string, float> $weights
     * @return array<int|string, float>
     */
    private function normalize(array $weights): array
    {
        if (empty($weights)) return [];
        $max = max($weights);
        if ($max <= 0) return [];
        return array_map(fn ($v) => $v / $max, $weights);
    }
}
