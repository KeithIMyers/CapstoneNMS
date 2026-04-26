<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editable system prompt + settings for a built-in assistant. Assistants
 * look up a prompt by its stable `key` at runtime; admins can tune the
 * voice, tighten the temperature, or pin a specific provider/model for
 * that one use case without touching code.
 */
class AiPrompt extends Model
{
    protected $table = 'ai_prompts';

    protected $fillable = [
        'key', 'name', 'description',
        'system_prompt', 'temperature', 'max_tokens',
        'provider_id', 'model_override', 'updated_by',
    ];

    protected $casts = [
        'temperature' => 'float',
        'max_tokens'  => 'integer',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Fetch a prompt by key with a graceful fallback so assistants don't
     * crash if the row is missing — they fall back to a minimal inline
     * prompt so the feature still works while the editor writes one.
     */
    public static function forKey(string $key, string $fallbackPrompt): self
    {
        $row = self::where('key', $key)->first();
        if ($row) return $row;

        $new = new self([
            'key'            => $key,
            'name'           => ucwords(str_replace(['.', '_'], ' ', $key)),
            'system_prompt'  => $fallbackPrompt,
            'temperature'    => 0.4,
            'max_tokens'     => 1024,
        ]);
        $new->save();
        return $new;
    }
}
