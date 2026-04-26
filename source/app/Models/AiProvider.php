<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * Configured LLM backend. One row per provider; the newsroom can have
 * several (e.g., a prod Claude key, a cheaper Ollama fallback, an
 * OpenAI account for moderation). The `api_key` is encrypted at rest
 * via Laravel's Crypt facade — both read and write go through an
 * Attribute accessor so the ciphertext never leaks into logs, dumps,
 * or Filament form state.
 */
class AiProvider extends Model
{
    public const KIND_OPENAI         = 'openai';
    public const KIND_ANTHROPIC      = 'anthropic';
    public const KIND_OLLAMA         = 'ollama';
    public const KIND_GOOGLE_IMAGEN  = 'google_imagen';

    public const KINDS = [
        self::KIND_OPENAI         => 'OpenAI',
        self::KIND_ANTHROPIC      => 'Anthropic (Claude)',
        self::KIND_OLLAMA         => 'Ollama (self-hosted)',
        self::KIND_GOOGLE_IMAGEN  => 'Google Imagen / NanoBanana (image-only)',
    ];

    protected $fillable = [
        'name', 'kind', 'base_url', 'api_key',
        'default_model', 'embedding_model', 'embedding_dimensions',
        'image_model', 'tts_model',
        'max_output_tokens',
        'daily_budget_microusd', 'monthly_budget_microusd',
        'is_active', 'is_default',
    ];

    protected $casts = [
        'is_active'               => 'boolean',
        'is_default'              => 'boolean',
        'max_output_tokens'       => 'integer',
        'embedding_dimensions'    => 'integer',
        'daily_budget_microusd'   => 'integer',
        'monthly_budget_microusd' => 'integer',
    ];

    /**
     * The first active provider that has an embedding_model configured.
     * EmbeddingClient routes through this; null when no provider in the
     * install supports embeddings (e.g., Anthropic-only setups).
     */
    public static function defaultEmbeddingProvider(): ?self
    {
        return self::active()
            ->whereNotNull('embedding_model')
            ->where('embedding_model', '!=', '')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * The first active provider that has image_model set. Used by
     * ImageGenClient as the fallback when a NewsAgent doesn't specify
     * its own image_provider_id.
     */
    public static function defaultImageProvider(): ?self
    {
        return self::active()
            ->whereNotNull('image_model')
            ->where('image_model', '!=', '')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * The first active provider with tts_model set. Used by TtsClient
     * for podcast generation; null when nobody has wired a TTS-capable
     * provider.
     */
    public static function defaultTtsProvider(): ?self
    {
        return self::active()
            ->whereNotNull('tts_model')
            ->where('tts_model', '!=', '')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * Hide the raw (encrypted) api_key from serialization; the accessor
     * below decrypts it for code that actually needs it.
     */
    protected $hidden = ['api_key'];

    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: function (?string $value) {
                if (! $value) return null;
                try {
                    return Crypt::decryptString($value);
                } catch (\Throwable $e) {
                    // If the app key rotated or the row was imported in
                    // plaintext, fail soft rather than crashing the admin.
                    return null;
                }
            },
            set: function (?string $value) {
                return $value === null || $value === ''
                    ? null
                    : Crypt::encryptString($value);
            },
        );
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AiRequest::class, 'provider_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Sum of cost_microusd for this provider since midnight today. */
    public function spentTodayMicroUsd(): int
    {
        return (int) AiRequest::where('provider_id', $this->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('cost_microusd');
    }

    /** Sum of cost_microusd for this provider in the current calendar month. */
    public function spentThisMonthMicroUsd(): int
    {
        return (int) AiRequest::where('provider_id', $this->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_microusd');
    }

    /**
     * Returns the human-readable reason this provider can't accept the
     * next call, or null when it's free to dispatch. Used by AiClient
     * before sending a request, and by the admin badge to flag a
     * paused provider.
     */
    public function budgetBlockReason(): ?string
    {
        if ($this->daily_budget_microusd && $this->spentTodayMicroUsd() >= $this->daily_budget_microusd) {
            return sprintf(
                'Daily budget exhausted ($%s of $%s spent today).',
                number_format($this->spentTodayMicroUsd() / 1_000_000, 2),
                number_format($this->daily_budget_microusd / 1_000_000, 2),
            );
        }
        if ($this->monthly_budget_microusd && $this->spentThisMonthMicroUsd() >= $this->monthly_budget_microusd) {
            return sprintf(
                'Monthly budget exhausted ($%s of $%s spent this month).',
                number_format($this->spentThisMonthMicroUsd() / 1_000_000, 2),
                number_format($this->monthly_budget_microusd / 1_000_000, 2),
            );
        }
        return null;
    }

    /**
     * Default active provider, preferring is_default = true. Returns null
     * if no provider is configured — callers must handle that.
     */
    public static function defaultProvider(): ?self
    {
        return self::active()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * Ensure at most one provider is the default. Called from saving().
     */
    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if ($model->is_default) {
                self::query()
                    ->where('id', '!=', $model->id ?? 0)
                    ->update(['is_default' => false]);
            }
        });
    }
}
