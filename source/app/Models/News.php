<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class News extends Model implements Feedable, HasMedia
{
    use InteractsWithMedia;
    use LogsActivity;
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_UNPUBLISHED = 'unpublished';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_IN_REVIEW,
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHED,
        self::STATUS_UNPUBLISHED,
        self::STATUS_ARCHIVED,
    ];

    protected $fillable = [
        'title', 'subtitle', 'kicker', 'dateline', 'slug', 'excerpt',
        'locale', 'translation_group_id',
        'meta_title', 'meta_description', 'canonical_url', 'fact_check_status',
        'requires_fact_check', 'fact_checked_by_user_id', 'fact_checked_at',
        'requires_legal_review', 'legal_review_status',
        'legal_reviewed_by_user_id', 'legal_reviewed_at', 'legal_review_notes',
        'content', 'content_blocks', 'image', 'image_alt', 'image_caption', 'image_credit',
        'video_embed_code', 'tags', 'user_id', 'category_id', 'series_id', 'sort_in_series',
        'status', 'editorial_status', 'article_type', 'published_at', 'unpublished_at',
        'is_featured', 'is_breaking', 'breaking_until', 'is_premium',
        'is_sponsored', 'sponsor_label',
        'podcast_audio_path', 'podcast_script', 'podcast_generated_at',
        'tts_audio_path', 'tts_generated_at', 'tts_voice', 'tts_chars',
        'auto_translate_locales',
        'date', 'views', 'reading_time_minutes',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'array',
        'is_featured' => 'boolean',
        'is_breaking' => 'boolean',
        'is_premium'  => 'boolean',
        'is_sponsored' => 'boolean',
        'content_blocks' => 'array',
        'podcast_script' => 'array',
        'podcast_generated_at' => 'datetime',
        'tts_generated_at' => 'datetime',
        'requires_fact_check' => 'boolean',
        'requires_legal_review' => 'boolean',
        'fact_checked_at' => 'datetime',
        'legal_reviewed_at' => 'datetime',
        'auto_translate_locales' => 'array',
        'published_at' => 'datetime',
        'unpublished_at' => 'datetime',
        'breaking_until' => 'datetime',
    ];

    /**
     * Render the article body for the public site. When blocks are
     * set, walk them through the BlockRenderer; otherwise fall back
     * to the legacy plain-HTML `content` column so existing articles
     * keep working without a backfill.
     */
    public function renderedBody(): string
    {
        if (is_array($this->content_blocks) && ! empty($this->content_blocks)) {
            return app(\App\Services\Blocks\BlockRenderer::class)->render($this->content_blocks);
        }
        return (string) $this->content;
    }

    /**
     * Pre-publish editorial gates. Returns an array of reasons the
     * article can't currently flip to `published` — empty array means
     * publishing is allowed. The Filament EditNews mutator reads this
     * and halts the save when non-empty.
     *
     * @return array<int, string>
     */
    public function publishBlockers(): array
    {
        $blockers = [];
        if ($this->requires_fact_check) {
            $verified  = $this->fact_check_status === 'verified';
            $hasSigner = ! empty($this->fact_checked_by_user_id);
            if (! ($verified && $hasSigner)) {
                $blockers[] = 'Fact check required: status must be "verified" and a fact-checker recorded.';
            }
        }
        if ($this->requires_legal_review) {
            if (($this->legal_review_status ?? '') !== 'approved') {
                $blockers[] = 'Legal review required: status must be "approved" before publishing.';
            }
        }
        return $blockers;
    }

    public function canPublish(): bool
    {
        return $this->publishBlockers() === [];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->user_id) && auth()->check()) {
                $model->user_id = auth()->id();
            }
            if (empty($model->date)) {
                $model->date = now()->getTimestamp();
            }
            if (empty($model->locale)) {
                $model->locale = config('locales.default', 'en');
            }
            self::syncLegacyStatus($model);
        });

        // After insert, if translation_group_id wasn't set manually (i.e.
        // this isn't being created as a translation of an existing piece),
        // default it to the row's own id so siblings can later be linked.
        static::created(function (self $model) {
            if (empty($model->translation_group_id)) {
                $model->translation_group_id = $model->id;
                $model->saveQuietly();
            }
        });

        static::saving(function (self $model) {
            self::syncLegacyStatus($model);
            self::computeReadingTime($model);
        });
    }

    /**
     * Mirror the new editorial_status onto the legacy `status` int so any
     * code path still reading the old column keeps working. published is
     * the only state that resolves to status=1; everything else is 0.
     */
    private static function syncLegacyStatus(self $model): void
    {
        if (! $model->isDirty('editorial_status') && ! $model->isDirty('status')) {
            return;
        }
        $model->status = $model->editorial_status === self::STATUS_PUBLISHED ? 1 : 0;
    }

    private static function computeReadingTime(self $model): void
    {
        if ($model->isDirty('content') || empty($model->reading_time_minutes)) {
            $words = str_word_count(strip_tags((string) $model->content));
            $model->reading_time_minutes = max(1, (int) ceil($words / 230));
        }
    }

    /* ---------- Scopes ---------- */

    /**
     * Visible to anonymous front-end visitors right now: editorial_status is
     * "published", published_at is in the past (or null for legacy rows
     * without a real timestamp), and unpublished_at is either null or in
     * the future.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('editorial_status', self::STATUS_PUBLISHED)
              ->where(function (Builder $q2) {
                  $q2->whereNull('published_at')
                     ->orWhere('published_at', '<=', now());
              })
              ->where(function (Builder $q2) {
                  $q2->whereNull('unpublished_at')
                     ->orWhere('unpublished_at', '>', now());
              });
        });
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('editorial_status', self::STATUS_SCHEDULED)
            ->whereNotNull('published_at');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('editorial_status', self::STATUS_DRAFT);
    }

    public function scopeReadyToPublish(Builder $query): Builder
    {
        return $query->where('editorial_status', self::STATUS_SCHEDULED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeReadyToUnpublish(Builder $query): Builder
    {
        return $query->where('editorial_status', self::STATUS_PUBLISHED)
            ->whereNotNull('unpublished_at')
            ->where('unpublished_at', '<=', now());
    }

    /* ---------- Relations ---------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ArticleSeries::class, 'series_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comments::class, 'post_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Reports::class, 'post_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class, 'news_id');
    }

    /**
     * Many-to-many tags via the new `news_tag` pivot. The legacy CSV
     * `tags` column on this row remains as a fallback during transition.
     */
    public function tagsRelation(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'news_tag', 'news_id', 'tag_id');
    }

    /** Gallery images attached to this article. */
    public function gallery(): HasMany
    {
        return $this->hasMany(NewsGallery::class, 'news_id');
    }

    /** Per-article source citations rendered in the article footer. */
    public function sources(): HasMany
    {
        return $this->hasMany(NewsSource::class, 'news_id')->orderBy('sort');
    }

    /** A/B headline variants. Pick one per session via bucketedHeadline(). */
    public function headlines(): HasMany
    {
        return $this->hasMany(NewsHeadline::class, 'news_id');
    }

    /**
     * All translation siblings of this article (same translation_group_id),
     * including the canonical but excluding self. Used by the language
     * switcher and hreflang alternates.
     */
    public function translationSiblings()
    {
        $groupId = $this->translation_group_id ?: $this->id;
        return self::query()
            ->where('translation_group_id', $groupId)
            ->where('id', '!=', $this->id);
    }

    /** Editorially curated topic landing pages this article appears on. */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'topic_news', 'news_id', 'topic_id')
            ->withPivot('sort');
    }

    /**
     * Co-authors / multi-byline. The primary byline lives on news.user_id;
     * additional bylines (contributors, photographers, illustrators) are
     * recorded on the pivot with a role and sort order.
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'news_authors', 'news_id', 'user_id')
            ->withPivot(['role', 'sort'])
            ->orderBy('news_authors.sort');
    }

    /**
     * Editor-visible corrections / updates log shown at the bottom of the
     * article page.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'news_id')->orderByDesc('created_at');
    }

    public function getActivitylogOptions(): LogOptions
    {
        // `content` and `content_blocks` are intentionally excluded:
        // logging the full body on every save bloats activity_log
        // exponentially on long articles. Editorial provenance for the
        // body itself lives in the `revisions` table (see ::revisions
        // above) — this log is for everything ELSE that changes.
        return LogOptions::defaults()
            ->logOnly([
                'title', 'subtitle', 'kicker', 'dateline', 'slug',
                'excerpt', 'image', 'video_embed_code',
                'category_id', 'editorial_status', 'published_at',
                'unpublished_at', 'is_featured', 'is_breaking',
                'meta_title', 'meta_description', 'canonical_url',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /* ---------- Helpers ---------- */

    public function getReactionCounts(): array
    {
        return $this->reactions()
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
    }

    public function getUserReaction($userId = null)
    {
        $userId = $userId ?? auth()->id();
        return $this->reactions()->where('user_id', $userId)->first();
    }

    public function effectivePublishedAt(): ?\Carbon\Carbon
    {
        if ($this->published_at) {
            return $this->published_at;
        }
        if (is_numeric($this->date)) {
            return \Carbon\Carbon::createFromTimestamp((int) $this->date);
        }
        return $this->created_at;
    }

    public function metaTitle(): string
    {
        return $this->meta_title ?: (string) $this->title;
    }

    /**
     * Pick a headline variant for the current session and return the model
     * (or null if there are no variants). Sticky-per-session so a visitor
     * always sees the same headline on refresh; weighted by CTR across
     * sessions with a small explore floor for new variants.
     *
     * Increments the variant's impression counter at most once per session
     * per variant so reload-spam doesn't distort the CTR denominator.
     */
    public function servedHeadline(): ?NewsHeadline
    {
        $variants = $this->relationLoaded('headlines')
            ? $this->headlines
            : $this->headlines()->get();

        if ($variants->isEmpty()) {
            return null;
        }

        // Weight: CTR when impressions >= 50, otherwise a floor for exploration.
        $weights = [];
        foreach ($variants as $v) {
            $ctr = $v->ctr();
            $weights[$v->id] = $ctr === null ? 0.05 : max(0.01, $ctr);
        }
        $total = array_sum($weights) ?: 1.0;

        $seed = (string) (session()->getId() ?: request()->ip() ?? 'anon').':'.$this->id;
        $bucket = (crc32($seed) % 10000) / 10000.0;

        $picked = null;
        $cum = 0.0;
        foreach ($variants as $v) {
            $cum += ($weights[$v->id] ?? 0) / $total;
            if ($bucket <= $cum) {
                $picked = $v;
                break;
            }
        }
        $picked = $picked ?? $variants->firstWhere('is_default', true) ?? $variants->first();

        // One impression per session per variant. The actual write
        // goes to the cache store rather than the DB so a viral
        // article doesn't trigger thousands of write-locks per minute
        // on news_headlines. The `news:flush-headline-impressions`
        // scheduled command drains the cache counter into MySQL once
        // a minute via a single batched UPDATE per dirty variant.
        if ($picked && ! session()->has("headline_imp_{$picked->id}")) {
            session()->put("headline_imp_{$picked->id}", true);
            try {
                $key = "hl_imp:{$picked->id}";
                // `add` is a no-op if the key exists, then increment
                // is safe across file/database/redis drivers (some
                // file-cache implementations choke on incrementing a
                // missing key).
                \Illuminate\Support\Facades\Cache::add($key, 0, 86400);
                \Illuminate\Support\Facades\Cache::increment($key, 1);
                \Illuminate\Support\Facades\Cache::put("hl_imp_dirty:{$picked->id}", 1, 86400);
            } catch (\Throwable $e) {
                // Cache miss-fire shouldn't break page render.
                \Illuminate\Support\Facades\Log::warning('Headline impression cache write failed', [
                    'headline_id' => $picked->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $picked;
    }

    /**
     * Convenience wrapper: just the headline text, falling back to the
     * canonical title when there are no variants.
     */
    public function bucketedHeadline(): string
    {
        $v = $this->servedHeadline();
        return (string) ($v?->variant ?: $this->title);
    }

    /**
     * Human-readable label + CSS modifier for the fact-check badge. Returns
     * null when the article carries no fact-check status.
     */
    public function factCheckBadge(): ?array
    {
        return match ($this->fact_check_status) {
            'verified'   => ['label' => 'Verified',   'tone' => 'ok'],
            'disputed'   => ['label' => 'Disputed',   'tone' => 'warn'],
            'unverified' => ['label' => 'Unverified', 'tone' => 'neutral'],
            default      => null,
        };
    }

    public function metaDescription(): string
    {
        return $this->meta_description ?: (string) $this->excerpt;
    }

    /* ---------- Feed (Spatie\Feed\Feedable) ---------- */

    public function toFeedItem(): FeedItem
    {
        return FeedItem::create()
            ->id((string) $this->id)
            ->title(strip_tags(stripslashes((string) $this->title)))
            ->summary(strip_tags(stripslashes((string) $this->excerpt)))
            ->updated($this->effectivePublishedAt() ?? $this->updated_at ?? now())
            ->link(route('news.details', ['slug' => $this->slug]))
            ->authorName(optional($this->user)->name ?: 'Editorial staff')
            ->authorEmail(optional($this->user)->email ?: 'noreply@'.parse_url(config('app.url'), PHP_URL_HOST));
    }

    public static function getFeedItems()
    {
        return self::published()
            ->with('user')
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();
    }

    /* ---------- Spatie\MediaLibrary ---------- */

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('lead')->singleFile();
        $this->addMediaCollection('gallery');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // Three responsive sizes, all WebP. The originals remain untouched.
        // Image conversions run in the queue (config/media-library.php
        // queue_conversions_by_default = true).
        $this->addMediaConversion('thumb')->width(320)->format('webp')->nonQueued();
        $this->addMediaConversion('medium')->width(768)->format('webp');
        $this->addMediaConversion('large')->width(1280)->format('webp');
        $this->addMediaConversion('hero')->width(1920)->format('webp');
    }
}
