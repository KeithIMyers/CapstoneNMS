<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasFactory, Notifiable, HasApiTokens;

    /**
     * Mass-assignable attributes are deliberately limited to fields a
     * profile-edit / signup form should ever set. Privileged fields —
     * role, status, is_agent, OAuth identity, 2FA state, and AI quotas
     * — are NOT in this list and must be set via `forceFill()` /
     * `forceCreate()` from a trusted code path (admin Filament pages,
     * `make:admin` CLI, OAuth callback after verification, agent
     * persona creation in the admin form).
     *
     * Rationale: any future controller that does `User::create(
     * $request->all())` against arbitrary input will silently drop
     * those sensitive fields rather than letting an attacker self-
     * promote to admin / disable 2FA / raise their AI budget.
     */
    protected $fillable = [
        'name',
        'slug',
        'bio',
        'twitter_handle',
        'email',
        'password',
        'phone',
        'image',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_secret' => 'encrypted',
            'ai_daily_budget_microusd' => 'integer',
            'ai_calls_per_minute' => 'integer',
            'is_agent' => 'boolean',
            'privacy_prefs' => 'array',
            'accessibility_prefs' => 'array',
            'deletion_requested_at' => 'datetime',
        ];
    }

    /** Sum of cost_microusd attributed to this user since midnight today. */
    public function aiSpentTodayMicroUsd(): int
    {
        return (int) AiRequest::where('user_id', $this->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('cost_microusd');
    }

    /** Number of AI calls this user issued in the last 60 seconds. */
    public function aiCallsLastMinute(): int
    {
        return AiRequest::where('user_id', $this->id)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->count();
    }

    /**
     * Returns a human-readable reason this user is currently blocked
     * from issuing an AI call, or null when they're free to dispatch.
     */
    public function aiBlockReason(): ?string
    {
        if ($this->ai_daily_budget_microusd && $this->aiSpentTodayMicroUsd() >= $this->ai_daily_budget_microusd) {
            return sprintf(
                'You\'ve reached your daily AI budget ($%s of $%s).',
                number_format($this->aiSpentTodayMicroUsd() / 1_000_000, 2),
                number_format($this->ai_daily_budget_microusd / 1_000_000, 2),
            );
        }
        if ($this->ai_calls_per_minute && $this->aiCallsLastMinute() >= $this->ai_calls_per_minute) {
            return sprintf(
                'AI rate limit hit (%d calls in the last 60 seconds). Try again shortly.',
                $this->aiCallsLastMinute(),
            );
        }
        return null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if (empty($user->slug) && ! empty($user->name)) {
                $user->slug = self::uniqueSlug($user->name, $user->id);
            }
        });
    }

    private static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'user';
        $slug = $base;
        $i = 1;
        while (self::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '<>', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }
        return $slug;
    }

    /**
     * Whether this user can log in via any authentication entry point —
     * web login form, Google OAuth, password reset, MFA challenge, or
     * the Filament admin panel. News-agent personas are "ghost" users
     * (is_agent = true) that exist purely to back the public byline +
     * author page; they have no usable password and never authenticate.
     *
     * Every auth entry point in the app delegates here so we can flip
     * the rule in one place.
     */
    public function canLogIn(): bool
    {
        if ($this->is_agent) return false;
        if ((int) ($this->status ?? 1) !== 1) return false;
        // Pending account-deletion request locks the user out. The
        // PrivacyController surfaces a "cancel deletion" link via the
        // deletion-confirmation email; the purge command finishes the
        // job after the grace window.
        if ($this->deletion_requested_at !== null) return false;
        return true;
    }

    /** Read a CCPA/GDPR-style preference flag with a fallback default. */
    public function privacyFlag(string $key, bool $default = false): bool
    {
        $prefs = (array) ($this->privacy_prefs ?? []);
        if (! array_key_exists($key, $prefs)) return $default;
        return (bool) $prefs[$key];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->canLogIn()) return false;
        return in_array($this->role, ['admin', 'sub_admin', 'editor', 'author'], true);
    }

    /**
     * Normalize role to lowercase on set so legacy callers writing 'User'
     * and 'user' (signup vs Google OAuth) end up with the same canonical
     * value. The panel allowlist and ability checks are case-sensitive.
     */
    public function setRoleAttribute(?string $value): void
    {
        $this->attributes['role'] = $value === null ? null : strtolower($value);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEditor(): bool
    {
        return in_array($this->role, ['admin', 'sub_admin', 'editor'], true);
    }

    public function isAuthor(): bool
    {
        return in_array($this->role, ['admin', 'sub_admin', 'editor', 'author'], true);
    }

    /**
     * Whether the user has an active Cashier subscription on ANY of
     * the configured tier slugs. Used by Paywall::canRead, GiftService,
     * AskQuota, and anywhere "is this a paid subscriber" matters
     * without caring which tier.
     */
    public function isPaidSubscriber(): bool
    {
        if (! method_exists($this, 'subscribed')) return false;
        $slugs = SubscriptionTier::cachedActiveSlugs();
        if (empty($slugs)) {
            // No tiers configured: fall back to the legacy single-name
            // check so a fresh install with only env-driven price IDs
            // still resolves correctly.
            return $this->subscribed(config('paywall.subscription_name', 'default'));
        }
        foreach ($slugs as $slug) {
            if ($this->subscribed($slug)) return true;
        }
        return false;
    }

    /**
     * The tier the user is currently subscribed to (or null). Used
     * for tier-specific entitlements like "this tier gets unlimited
     * Ask quota" or "comments badge color".
     */
    public function currentTier(): ?SubscriptionTier
    {
        foreach (SubscriptionTier::cachedActiveSlugs() as $slug) {
            if ($this->subscribed($slug)) {
                return SubscriptionTier::where('slug', $slug)->first();
            }
        }
        return null;
    }

    public function onTier(string $slug): bool
    {
        return method_exists($this, 'subscribed') && $this->subscribed($slug);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    /* ---------- Public author profile ---------- */

    /** Articles where this user is the primary byline. */
    public function authoredArticles(): HasMany
    {
        return $this->hasMany(News::class, 'user_id');
    }

    /** Articles where this user is on the multi-byline pivot. */
    public function coauthoredArticles(): BelongsToMany
    {
        return $this->belongsToMany(News::class, 'news_authors', 'user_id', 'news_id')
            ->withPivot(['role', 'sort']);
    }

    public function profileUrl(): string
    {
        return url('/author/'.($this->slug ?: $this->id));
    }
}
